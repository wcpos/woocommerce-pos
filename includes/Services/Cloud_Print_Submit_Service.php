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
use WCPOS\WooCommercePOS\Interfaces\Push_Provider_Adapter_Interface;

/**
 * Cloud_Print_Submit_Service class.
 */
class Cloud_Print_Submit_Service {
	/** Maximum number of submit attempts before a job is terminally failed. */
	const MAX_ATTEMPTS = 3;

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
		// A job cancelled between scheduling and this run must not be sent.
		// The cancel path cannot unschedule reliably (retries reschedule with
		// fresh timestamps), so the worker is the authority on status.
		if ( ! $this->is_submittable( $job ) ) {
			return;
		}

		// Atomic guard: only one worker may submit a given job at a time.
		if ( ! $this->jobs->acquire_lifecycle_lock( $job_id ) ) {
			return;
		}

		try {
			// Double-check under the lock in case another worker just finished
			// or the job was cancelled while we waited for it.
			$job = $this->jobs->get( $job_id );
			if ( null === $job || '' !== $job['external_job_id'] || ! $this->is_submittable( $job ) ) {
				return;
			}

			$printer = $this->registry->get_printer( (string) $job['printer_id'] );
			if ( null === $printer ) {
				$this->fail( $job_id, 'Cloud print: printer not found for job.' );

				return;
			}

			$provider = (string) ( $printer['provider'] ?? '' );
			$adapter  = Provider::adapter( $provider );
			if ( ! $adapter instanceof Push_Provider_Adapter_Interface ) {
				$this->fail( $job_id, 'Cloud print: unsupported push provider for job.' );

				return;
			}

			$payload = $this->jobs->render_payload( $job );
			$result  = $adapter->submit( $printer, $job, $payload, $this->title_for( $job ) );
			if ( ! $result['success'] ) {
				if ( $result['retryable'] ) {
					$this->handle_submit_error( $job_id, $result['error'] );
				} else {
					$this->fail( $job_id, $result['error'] );
				}

				return;
			}

			$this->jobs->record_external_submission( $job_id, $provider, $result['external_job_id'], 'submitted' );

			if ( '' !== $result['drawer_error'] ) {
				update_post_meta( $job_id, Print_Job_Service::META_DRAWER_ERROR, sanitize_text_field( $result['drawer_error'] ) );
				Logger::log( sprintf( 'Cloud print: PrintNode drawer kick failed for job %d after receipt submission.', $job_id ) );
			}

			$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_PRINTED );
		} finally {
			$this->jobs->release_lifecycle_lock( $job_id );
		}
	}

	/**
	 * Whether a job's status still permits submission.
	 *
	 * Pending is the normal case; claimed covers a retry that a worker had
	 * already picked up. Cancelled, printed and failed jobs are terminal and
	 * must never be pushed to a provider.
	 *
	 * @param array $job Job row.
	 *
	 * @return bool True when the job may be submitted.
	 */
	private function is_submittable( array $job ): bool {
		return \in_array(
			$job['status'] ?? '',
			array( Print_Job_Service::STATUS_PENDING, Print_Job_Service::STATUS_CLAIMED ),
			true
		);
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
