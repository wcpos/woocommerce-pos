<?php
/**
 * Submits queued PrintNode print jobs out-of-band via WP-Cron.
 *
 * Checkout only schedules a single cron event; the actual HTTP submission to
 * PrintNode happens here so the storefront request never blocks on the network.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Cloud_Print_Submit_Service class.
 */
class Cloud_Print_Submit_Service {
	/** Maximum number of submit attempts before a job is terminally failed. */
	const MAX_ATTEMPTS = 3;

	/** Per-job submit lock option prefix. */
	const SUBMIT_LOCK_PREFIX = 'wcpos_pn_submit_lock_';

	/** Seconds a submit lock is honoured before a crashed worker is self-healed. */
	const SUBMIT_LOCK_TTL = 120;

	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Printer registry.
	 *
	 * @var Cloud_Print_Registry
	 */
	private $registry;

	/**
	 * Constructor — hook the submit cron action.
	 */
	public function __construct() {
		$this->jobs     = new Print_Job_Service();
		$this->registry = new Cloud_Print_Registry();
		add_action( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $this, 'submit' ), 10, 1 );
	}

	/**
	 * Submit a queued PrintNode job.
	 *
	 * Idempotent and concurrency-safe: a job that already carries a PrintNode job
	 * id is left alone, and an atomic per-job lock (double-checked under the lock)
	 * guarantees that two concurrent cron workers cannot both submit the same job
	 * and double-print.
	 *
	 * Transient PrintNode submit errors are retried with linear backoff up to
	 * MAX_ATTEMPTS, after which the job is terminally FAILED. Misconfigured-printer
	 * and empty-render failures are terminal immediately (never retried). The API
	 * key is never logged or stored — PrintNode_Client error messages omit it.
	 *
	 * @param int $job_id Print job ID.
	 */
	public function submit( $job_id ): void {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );
		if ( null === $job ) {
			return;
		}
		if ( '' !== $job['external_job_id'] ) {
			return;
		}

		// Atomic guard: only one worker may submit a given job at a time.
		if ( ! $this->acquire_lock( $job_id ) ) {
			return;
		}

		try {
			// Double-check under the lock in case another worker just finished.
			$job = $this->jobs->get( $job_id );
			if ( null === $job || '' !== $job['external_job_id'] ) {
				return;
			}

			$printer = $this->registry->get_printer( (string) $job['printer_id'] );
			if ( null === $printer ) {
				$this->fail( $job_id, 'Cloud print: printer not found for job.' );

				return;
			}

			$provider = (string) ( $printer['provider'] ?? '' );
			if ( 'star-online' === $provider ) {
				$this->submit_star_online( $job_id, $job, $printer );

				return;
			}

			if ( 'printnode' !== $provider ) {
				$this->fail( $job_id, 'Cloud print: unsupported push provider for job.' );

				return;
			}

			$api_key       = (string) ( $printer['printnode_api_key'] ?? '' );
			$pn_printer_id = (int) ( $printer['printnode_printer_id'] ?? 0 );
			if ( '' === $api_key || 0 === $pn_printer_id ) {
				$this->fail( $job_id, 'Cloud print: PrintNode printer is missing an API key or printer id.' );

				return;
			}

			$payload = $this->jobs->render_payload( $job );
			if ( '' === $payload ) {
				$this->fail( $job_id, 'Cloud print: PrintNode job produced no printable content.' );

				return;
			}

			$content_type = 'escpos' === $job['pn_kind'] ? 'raw_base64' : 'pdf_base64';
			$title        = $this->title_for( $job );

			$result = ( new PrintNode_Client( $api_key ) )->submit_job(
				$pn_printer_id,
				$title,
				$content_type,
				base64_encode( $payload )
			);

			if ( is_wp_error( $result ) ) {
				$this->handle_submit_error( $job_id, $result->get_error_message() );

				return;
			}

			$this->jobs->record_external_submission( $job_id, 'printnode', (string) $result['id'], 'submitted' );
			$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_PRINTED );
		} finally {
			$this->release_lock( $job_id );
		}
	}

	/**
	 * Submit a queued Star Online job: render Star markup and POST it to stario.online.
	 *
	 * @param int   $job_id  Job id.
	 * @param array $job     Job array.
	 * @param array $printer Registered star-online printer.
	 */
	private function submit_star_online( int $job_id, array $job, array $printer ): void {
		$api_key   = (string) ( $printer['star_api_key'] ?? '' );
		$url       = (string) ( $printer['star_cloudprnt_url'] ?? '' );
		$device_id = (string) ( $printer['star_device_id'] ?? '' );
		$api_base  = Star_Online_Client::api_base_from_cloudprnt_url( $url );
		$group     = Star_Online_Client::group_from_cloudprnt_url( $url );

		if ( '' === $api_key || null === $api_base || '' === $group || '' === $device_id ) {
			$this->fail( $job_id, 'Cloud print: Star Online printer is misconfigured.' );

			return;
		}

		$payload = $this->jobs->render_payload( $job );
		if ( '' === $payload ) {
			$this->fail( $job_id, 'Cloud print: Star Online job produced no printable content.' );

			return;
		}

		$result = ( new Star_Online_Client( $api_base, $api_key ) )->submit_job(
			$group,
			$device_id,
			$this->title_for( $job ),
			'text/vnd.star.markup',
			$payload
		);

		if ( is_wp_error( $result ) ) {
			$this->handle_submit_error( $job_id, $result->get_error_message() );

			return;
		}

		$this->jobs->record_external_submission( $job_id, 'star-online', (string) $result['id'], 'submitted' );
		$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_PRINTED );
	}

	/**
	 * Acquire the atomic per-job submit lock.
	 *
	 * Mirrors Print_Job_Service::acquire_claim_lock — add_option is atomic, so the
	 * first caller wins. A lock older than the TTL is treated as a crashed worker
	 * and self-healed.
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock( int $job_id ): bool {
		$option = self::SUBMIT_LOCK_PREFIX . $job_id;
		$now    = time();

		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( $option, 0 );
		if ( $locked_at > 0 && ( $now - $locked_at ) > self::SUBMIT_LOCK_TTL ) {
			delete_option( $option );

			return add_option( $option, (string) $now, '', false );
		}

		return false;
	}

	/**
	 * Release the per-job submit lock.
	 *
	 * @param int $job_id Job ID.
	 */
	private function release_lock( int $job_id ): void {
		delete_option( self::SUBMIT_LOCK_PREFIX . $job_id );
	}

	/**
	 * Handle a transient PrintNode submit error: retry with linear backoff up to
	 * MAX_ATTEMPTS, then terminally fail.
	 *
	 * The PrintNode client never includes the API key in its error messages, so
	 * recording the message verbatim is safe.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $error  Failure reason from PrintNode_Client.
	 */
	private function handle_submit_error( int $job_id, string $error ): void {
		$attempts = (int) get_post_meta( $job_id, Print_Job_Service::META_SUBMIT_ATTEMPTS, true ) + 1;
		update_post_meta( $job_id, Print_Job_Service::META_SUBMIT_ATTEMPTS, $attempts );
		update_post_meta( $job_id, Print_Job_Service::META_ERROR, sanitize_text_field( $error ) );

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_PENDING );
			wp_schedule_single_event(
				time() + $attempts * 60,
				Cloud_Print_Trigger_Service::CRON_SUBMIT,
				array( $job_id )
			);
			Logger::log( sprintf( 'Cloud print: external submission failed for job %d, retry %d scheduled.', $job_id, $attempts ) );

			return;
		}

		$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_FAILED );
		Logger::log( sprintf( 'Cloud print: external submission failed for job %d after %d attempts.', $job_id, $attempts ) );
	}

	/**
	 * Build a human-readable PrintNode job title.
	 *
	 * @param array $job Job array.
	 *
	 * @return string
	 */
	private function title_for( array $job ): string {
		if ( ! empty( $job['order_id'] ) ) {
			$order = wc_get_order( (int) $job['order_id'] );
			if ( $order ) {
				return 'WCPOS Order #' . $order->get_order_number();
			}
		}

		return 'WCPOS Print Job ' . (int) $job['id'];
	}

	/**
	 * Mark a job failed, record the error, and log a generic failure.
	 *
	 * The PrintNode client never includes the API key in its error messages, so
	 * recording the message verbatim is safe.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $error  Failure reason.
	 */
	private function fail( int $job_id, string $error ): void {
		$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_FAILED );
		update_post_meta( $job_id, Print_Job_Service::META_ERROR, sanitize_text_field( $error ) );
		Logger::log( sprintf( 'Cloud print: external submission failed for job %d.', $job_id ) );
	}
}
