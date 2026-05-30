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
	 * Idempotent: a job that already carries a PrintNode job id is left alone so a
	 * duplicate cron fire cannot double-print. Failures leave pn_job_id at 0 so a
	 * re-dispatch can retry.
	 *
	 * @param int $job_id Print job ID.
	 */
	public function submit( $job_id ): void {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );
		if ( null === $job ) {
			return;
		}
		if ( $job['pn_job_id'] > 0 ) {
			return;
		}

		$printer = $this->registry->get_printer( (string) $job['printer_id'] );
		if ( null === $printer || 'printnode' !== ( $printer['provider'] ?? '' ) ) {
			$this->fail( $job_id, 'Cloud print: PrintNode printer not found for job.' );

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
			$this->fail( $job_id, $result->get_error_message() );

			return;
		}

		$this->jobs->record_printnode_submission( $job_id, (int) $result['id'], 'submitted' );
		$this->jobs->set_status( $job_id, Print_Job_Service::STATUS_PRINTED );
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
		Logger::log( sprintf( 'Cloud print: PrintNode submission failed for job %d.', $job_id ) );
	}
}
