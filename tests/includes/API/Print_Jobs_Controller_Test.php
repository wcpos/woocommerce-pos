<?php
/**
 * Print jobs controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Jobs_Controller_Test class.
 */
class Print_Jobs_Controller_Test extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Captured request args from the last intercepted HTTP request.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Active pre_http_request callback, stored so it can be removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * Set up the print job CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		( new Print_Job_Service() )->register_post_type();
	}

	/**
	 * Remove any active HTTP filter.
	 */
	public function tearDown(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		wp_clear_scheduled_hook( Cloud_Print_Trigger_Service::CRON_SUBMIT );
		delete_option( 'woocommerce_pos_settings_cloud_print' );
		parent::tearDown();
	}

	/**
	 * Create a thermal template post with raw markup, bypassing wp_kses.
	 *
	 * Mirrors the trigger-service test helper: wp_insert_post() runs content
	 * through wp_kses for users without unfiltered_html, stripping the custom
	 * thermal tags, so the content is written directly via $wpdb.
	 *
	 * @return int Template post ID.
	 */
	private function create_thermal_template(): int {
		$tid = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'T',
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="48"><text>Order #{{order.number}}</text><cut /></receipt>' ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $tid );
		update_post_meta( $tid, '_template_engine', 'thermal' );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * Register a pre_http_request filter that captures args and returns a faux response.
	 *
	 * @param mixed $response Faux response array or WP_Error to return.
	 */
	private function mock_http( $response ): void {
		$this->http_filter = function ( $pre, $args, $url ) use ( $response ) {
			$this->captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return $response;
		};

		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * It enqueues a raw payload print job.
	 */
	public function test_enqueue_raw_payload_job_returns_201_pending(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( "\x1b@hello" ),
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'printer-1', $data['printer_id'] );
		$this->assertEquals( 'pending', $data['status'] );
	}

	/**
	 * It rejects enqueue requests without a printer id.
	 */
	public function test_enqueue_without_printer_id_returns_400(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params( array( 'content_type' => 'text/html' ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_missing_printer', $response->as_error()->get_error_code() );
	}

	/**
	 * It rejects app-side raw payloads for Epson SDP printers.
	 */
	public function test_enqueue_raw_payload_to_epson_printer_returns_400(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'epson-1',
						'provider' => 'epson-sdp',
					),
				),
			)
		);
		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'   => 'epson-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_incompatible', $response->as_error()->get_error_code() );
	}

	/**
	 * It rejects fixed-layout escpos/starprnt format jobs for Star CloudPRNT printers.
	 *
	 * The fixed-layout 'escpos' adapter emits a language StarPRNT-native
	 * printers cannot decode, and the fixed-layout 'starprnt' adapter is a
	 * non-functional placeholder — both must fail loudly at enqueue.
	 */
	public function test_enqueue_fixed_layout_format_to_star_printer_returns_400(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'star-1',
						'provider' => 'star-cloudprnt',
					),
				),
			)
		);

		foreach ( array( 'escpos', 'starprnt' ) as $format ) {
			$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
			$request->set_body_params(
				array(
					'printer_id' => 'star-1',
					'order_id'   => 12345,
					'format'     => $format,
				)
			);

			$response = rest_do_request( $request );

			$this->assertEquals( 400, $response->get_status(), "format={$format}" );
			$this->assertEquals( 'wcpos_print_job_incompatible', $response->as_error()->get_error_code(), "format={$format}" );
		}
	}

	/**
	 * It ignores a fixed-layout format when enqueueing a Star template job.
	 */
	public function test_enqueue_star_template_job_with_redundant_format_succeeds(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'star-1',
						'provider' => 'star-cloudprnt',
					),
				),
			)
		);
		$template_id = $this->create_thermal_template();
		$order       = OrderHelper::create_order();
		$request     = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'  => 'star-1',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $template_id,
				'format'      => 'escpos',
			)
		);

		$response = rest_do_request( $request );

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'application/vnd.star.starprnt', $response->get_data()['content_type'] );
	}

	/**
	 * It refreshes the media type when retrying a template-backed Star job.
	 */
	public function test_reprint_star_template_job_refreshes_content_type(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'star-1',
						'provider' => 'star-cloudprnt',
					),
				),
			)
		);
		$jobs = new Print_Job_Service();
		$id   = $jobs->create(
			array(
				'printer_id'   => 'star-1',
				'content_type' => 'application/octet-stream',
				'template_id'  => 'receipt-80mm',
			)
		);
		$jobs->set_status( $id, Print_Job_Service::STATUS_FAILED );

		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $id . '/reprint' ) );

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'application/vnd.star.starprnt', $response->get_data()['content_type'] );
	}

	/**
	 * It enqueues an order-based PrintNode job and schedules its submit event.
	 */
	public function test_enqueue_order_based_printnode_job_schedules_submit(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'KEY',
						'printnode_printer_id' => 9,
					),
				),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'  => 'bar',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'pending', $data['status'] );
		$this->assertEquals( 'pdf', $data['pn_kind'] );
		$this->assertEquals( 'application/pdf', $data['content_type'] );
		$this->assertNotFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $data['id'] ) )
		);
	}

	/**
	 * It persists drawer options from a manual order-based cloud print request.
	 */
	public function test_create_order_job_persists_drawer_options(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'epson1',
						'name'     => 'Epson',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(),
			)
		);

		$template_id = $this->create_thermal_template();
		$order       = OrderHelper::create_order();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'      => 'epson1',
				'order_id'        => $order->get_id(),
				'template_id'     => (string) $template_id,
				'autoOpenDrawer'  => true,
				'drawerConnector' => 'pin5',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( true, $data['auto_open_drawer'] );
		$this->assertEquals( 'pin5', $data['drawer_connector'] );
	}

	/**
	 * It ignores drawer options for Star CloudPRNT order jobs; Star remains client-rendered/out of scope.
	 */
	public function test_create_order_job_ignores_drawer_options_for_star_cloudprnt(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'star1',
						'name'     => 'Star',
						'provider' => 'star-cloudprnt',
					),
				),
			)
		);

		$template_id = $this->create_thermal_template();
		$order       = OrderHelper::create_order();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'      => 'star1',
				'order_id'        => $order->get_id(),
				'template_id'     => (string) $template_id,
				'autoOpenDrawer'  => true,
				'drawerConnector' => 'pin5',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertFalse( $data['auto_open_drawer'] );
		$this->assertEquals( 'pin2', $data['drawer_connector'] );
	}

	/**
	 * It returns 404 for an order-based job targeting an unknown printer
	 * (rather than silently enqueuing a job that can never be delivered).
	 */
	public function test_enqueue_order_based_unknown_printer_returns_404(): void {
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'  => 'ghost',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_unknown_printer', $response->as_error()->get_error_code() );
	}

	/**
	 * It returns 404 for an order-based job referencing a non-existent order.
	 */
	public function test_enqueue_order_based_unknown_order_returns_404(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'epson-1',
						'name'     => 'Epson',
						'provider' => 'epson-sdp',
					),
				),
			)
		);
		$tid = $this->create_thermal_template();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'  => 'epson-1',
				'order_id'    => 99999999,
				'template_id' => (string) $tid,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_unknown_order', $response->as_error()->get_error_code() );
	}

	/**
	 * It rejects a PrintNode job that has no order + template (nothing to render/submit).
	 */
	public function test_enqueue_printnode_without_template_returns_400(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'KEY',
						'printnode_printer_id' => 9,
					),
				),
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params( array( 'printer_id' => 'bar' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_printnode_requires_template', $response->as_error()->get_error_code() );
	}

	/**
	 * It enqueues a pending order-based job for an Epson printer (rendered on poll).
	 */
	public function test_enqueue_order_based_epson_job_returns_201_pending(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'epson-1',
						'name'     => 'Epson',
						'provider' => 'epson-sdp',
					),
				),
			)
		);
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_post_request( '/wcpos/v1/print-jobs' );
		$request->set_body_params(
			array(
				'printer_id'  => 'epson-1',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'pending', $data['status'] );
		$this->assertEquals( (string) $tid, $data['template_id'] );
	}

	/**
	 * It lists jobs filtered by printer id.
	 */
	public function test_list_returns_jobs_filtered_by_printer(): void {
		$this->jobs_seed( 'printer-A' );
		$this->jobs_seed( 'printer-B' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/print-jobs' );
		$request->set_query_params( array( 'printer_id' => 'printer-A' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $response->get_data() ) );
	}

	/**
	 * It cancels a print job.
	 */
	public function test_cancel_sets_status_cancelled(): void {
		$id = $this->jobs_seed( 'printer-A' );

		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/print-jobs/' . $id );
		$request->set_header( 'X-WCPOS', '1' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'cancelled', ( new Print_Job_Service() )->get( $id )['status'] );
	}

	/**
	 * It refuses to cancel terminal jobs, preserving a failed job's retryable payload.
	 *
	 * set_status() clears post_content on cancel, so cancelling a failed job
	 * destroys the payload Retry copies. cancel_waiting() already guards this
	 * for bulk cancels; the single-job endpoint must agree.
	 */
	public function test_cancel_refuses_non_cancellable_statuses(): void {
		$jobs = new Print_Job_Service();
		foreach ( array( Print_Job_Service::STATUS_FAILED, Print_Job_Service::STATUS_PRINTED, Print_Job_Service::STATUS_CANCELLED ) as $status ) {
			$id = $this->jobs_seed( 'printer-A' );
			$jobs->set_status( $id, $status );

			$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/print-jobs/' . $id );
			$request->set_header( 'X-WCPOS', '1' );
			$response = rest_do_request( $request );

			$this->assertEquals( 409, $response->get_status() );
			$this->assertEquals( 'wcpos_print_job_not_cancellable', $response->as_error()->get_error_code() );
			$job = $jobs->get( $id );
			$this->assertEquals( $status, $job['status'] );
			if ( Print_Job_Service::STATUS_FAILED === $status ) {
				$this->assertEquals( base64_encode( 'x' ), $job['payload'] );
			}
		}
	}

	/**
	 * It does not overwrite a printer failure that wins the cancellation race.
	 */
	public function test_cancel_does_not_overwrite_concurrent_printer_failure(): void {
		$jobs = new Print_Job_Service();
		$id   = $this->jobs_seed( 'printer-A' );
		$jobs->set_status( $id, Print_Job_Service::STATUS_CLAIMED );
		$raced = false;
		$race  = function ( $check, $object_id, $meta_key, $meta_value ) use ( $jobs, $id, &$raced ) {
			if ( ! $raced && $id === (int) $object_id && Print_Job_Service::META_STATUS === $meta_key && Print_Job_Service::STATUS_CANCELLED === $meta_value ) {
				$raced = true;
				$jobs->set_status( $id, Print_Job_Service::STATUS_FAILED );
			}

			return $check;
		};
		add_filter( 'update_post_metadata', $race, 10, 4 );

		try {
			$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/print-jobs/' . $id );
			$request->set_header( 'X-WCPOS', '1' );
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'update_post_metadata', $race, 10 );
		}

		$this->assertEquals( 409, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_not_cancellable', $response->as_error()->get_error_code() );
		$this->assertEquals( Print_Job_Service::STATUS_FAILED, $jobs->get( $id )['status'] );
		$this->assertEquals( base64_encode( 'x' ), $jobs->get( $id )['payload'] );
	}

	/**
	 * It does not cancel a job while its provider submission holds the lifecycle lock.
	 */
	public function test_cancel_refuses_job_while_submission_lock_held(): void {
		$jobs = new Print_Job_Service();
		$id   = $this->jobs_seed( 'printer-A' );
		add_option( Print_Job_Service::LIFECYCLE_LOCK_PREFIX . $id, (string) time(), '', false );

		try {
			$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/print-jobs/' . $id );
			$request->set_header( 'X-WCPOS', '1' );
			$response = rest_do_request( $request );
		} finally {
			delete_option( Print_Job_Service::LIFECYCLE_LOCK_PREFIX . $id );
		}

		$this->assertEquals( 409, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_not_cancellable', $response->as_error()->get_error_code() );
		$this->assertEquals( Print_Job_Service::STATUS_PENDING, $jobs->get( $id )['status'] );
		$this->assertEquals( base64_encode( 'x' ), $jobs->get( $id )['payload'] );
	}

	/**
	 * Retrying a push-provider job schedules its out-of-band submit.
	 *
	 * PrintNode and Star Online never poll the queue, so a replacement job
	 * that is not handed to CRON_SUBMIT stays pending forever — Retry looks
	 * like it worked and nothing ever prints.
	 */
	public function test_reprint_schedules_submit_for_push_provider(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'                   => 'pn',
						'name'                 => 'PrintNode',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'secret-key',
						'printnode_printer_id' => 42,
					),
				),
				'assignments' => array(),
			)
		);
		$jobs   = new Print_Job_Service();
		$source = $jobs->create(
			array(
				'printer_id'   => 'pn',
				'content_type' => 'application/pdf',
				'payload'      => base64_encode( 'x' ),
			)
		);
		$jobs->set_status( $source, Print_Job_Service::STATUS_FAILED );

		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/print-jobs/' . $source . '/reprint' );
		$request->set_header( 'X-WCPOS', '1' );
		$response = rest_do_request( $request );

		$this->assertEquals( 201, $response->get_status() );
		$new_id = (int) $response->get_data()['id'];
		$this->assertNotFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $new_id ) )
		);
	}

	/**
	 * Retrying a failed job records its replacement and strips the source payload.
	 */
	public function test_reprint_failed_job_records_replacement_and_strips_source_payload(): void {
		// Arrange.
		$jobs   = new Print_Job_Service();
		$source = $jobs->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'receipt' ),
			)
		);
		$jobs->set_status( $source, Print_Job_Service::STATUS_FAILED );

		// Act.
		$response       = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $source . '/reprint' ) );
		$replacement_id = (int) $response->get_data()['id'];
		$retried_source = $jobs->get( $source );

		// Assert.
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( $replacement_id, $retried_source['retried_to'] );
		$this->assertEquals( '', $retried_source['payload'] );
	}

	/**
	 * Reprinting a pending source leaves it available for its original print attempt.
	 */
	public function test_reprint_pending_job_preserves_source_payload_without_marking_it_retried(): void {
		// Arrange.
		$jobs   = new Print_Job_Service();
		$source = $jobs->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'receipt' ),
			)
		);

		// Act.
		$response         = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $source . '/reprint' ) );
		$reprinted_source = $jobs->get( $source );

		// Assert.
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 0, $reprinted_source['retried_to'] );
		$this->assertEquals( base64_encode( 'receipt' ), $reprinted_source['payload'] );
	}

	/**
	 * A failed retry-meta write preserves the source and rolls back its replacement.
	 */
	public function test_reprint_retry_meta_failure_preserves_source_without_replacement(): void {
		// Arrange.
		$jobs   = new Print_Job_Service();
		$source = $jobs->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'receipt' ),
			)
		);
		$jobs->set_status( $source, Print_Job_Service::STATUS_FAILED );
		$job_count       = $jobs->count();
		$fail_retry_meta = function ( $check, $object_id, $meta_key ) use ( $source ) {
			if ( $source === (int) $object_id && Print_Job_Service::META_RETRIED_TO === $meta_key ) {
				return false;
			}

			return $check;
		};
		add_filter( 'update_post_metadata', $fail_retry_meta, 10, 3 );

		// Act.
		try {
			$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $source . '/reprint' ) );
		} finally {
			remove_filter( 'update_post_metadata', $fail_retry_meta, 10 );
		}

		// Assert.
		$this->assertEquals( 500, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_retry_failed', $response->as_error()->get_error_code() );
		$this->assertEquals( $job_count, $jobs->count() );
		$this->assertEquals( 0, $jobs->get( $source )['retried_to'] );
		$this->assertEquals( base64_encode( 'receipt' ), $jobs->get( $source )['payload'] );
	}

	/**
	 * Retrying an already-retried source returns its replacement without another copy.
	 */
	public function test_reprint_already_retried_source_returns_409_without_creating_duplicate(): void {
		// Arrange.
		$jobs   = new Print_Job_Service();
		$source = $jobs->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'receipt' ),
			)
		);
		$jobs->set_status( $source, Print_Job_Service::STATUS_FAILED );
		$first          = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $source . '/reprint' ) );
		$replacement_id = (int) $first->get_data()['id'];
		$job_count      = $jobs->count();

		// Act.
		$second = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $source . '/reprint' ) );
		$error  = $second->as_error();

		// Assert.
		$this->assertEquals( 409, $second->get_status() );
		$this->assertEquals( 'wcpos_print_job_already_retried', $error->get_error_code() );
		$this->assertEquals( $replacement_id, $error->get_error_data()['retried_to'] );
		$this->assertEquals( $job_count, $jobs->count() );
	}

	/**
	 * It enqueues a pending diagnostic job for a Star printer.
	 */
	public function test_test_print_enqueues_pending_job_for_star_printer(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'              => 'kitchen',
						'name'            => 'Kitchen',
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
				'assignments' => array(),
			)
		);
		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'kitchen' ) );
		$res = rest_do_request( $req );

		$this->assertEquals( 201, $res->get_status() );
		$this->assertEquals( 'pending', $res->get_data()['status'] );
		$this->assertEquals( 'kitchen', $res->get_data()['printer_id'] );
	}

	/**
	 * It returns 404 for an unknown printer.
	 */
	public function test_test_print_unknown_printer_returns_404(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'nope' ) );
		$this->assertEquals( 404, rest_do_request( $req )->get_status() );
	}

	/**
	 * It returns 500 when the diagnostic job cannot be created.
	 */
	public function test_test_print_returns_500_when_job_creation_fails(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'              => 'kitchen',
						'name'            => 'Kitchen',
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
				'assignments' => array(),
			)
		);
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'kitchen' ) );
		$res = rest_do_request( $req );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertEquals( 500, $res->get_status() );
		$this->assertEquals( 'wcpos_print_job_create_failed', $res->as_error()->get_error_code() );
	}

	/**
	 * It submits a diagnostic PDF to PrintNode and returns the job id.
	 *
	 * Replaces the former Phase-3 test that asserted a 400 wcpos_print_job_no_diagnostic
	 * for PrintNode printers; PrintNode now submits a diagnostic directly.
	 */
	public function test_test_print_printnode_submits_diagnostic_returns_201(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'KEY',
						'printnode_printer_id' => 9,
					),
				),
				'assignments' => array(),
			)
		);
		$this->mock_http( $this->fake_response( 555 ) );

		// Act.
		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'bar' ) );
		$res = rest_do_request( $req );

		// Assert.
		$this->assertEquals( 201, $res->get_status() );
		$this->assertEquals( true, $res->get_data()['submitted'] );
		$this->assertSame( 'printnode', $res->get_data()['external_provider'] );
		$this->assertSame( '555', $res->get_data()['external_job_id'] );
		$this->assertSame( 'submitted', $res->get_data()['external_state'] );

		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertEquals( 'pdf_base64', $body['contentType'] );
		$this->assertEquals( 9, $body['printerId'] );
	}

	/**
	 * It returns 400 for a PrintNode printer missing its API key, without any HTTP call.
	 */
	public function test_test_print_printnode_unconfigured_returns_400_no_http(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => '',
						'printnode_printer_id' => 9,
					),
				),
				'assignments' => array(),
			)
		);
		$this->mock_http( new \WP_Error( 'should_not_be_called', 'no http' ) );

		// Act.
		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'bar' ) );
		$res = rest_do_request( $req );

		// Assert.
		$this->assertEquals( 400, $res->get_status() );
		$this->assertEquals( 'wcpos_print_job_printnode_unconfigured', $res->as_error()->get_error_code() );
		$this->assertEquals( array(), $this->captured );
	}

	/**
	 * It returns 502 when the PrintNode submission fails.
	 */
	public function test_test_print_printnode_submit_failure_returns_502(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'KEY',
						'printnode_printer_id' => 9,
					),
				),
				'assignments' => array(),
			)
		);
		$this->mock_http( new \WP_Error( 'http_request_failed', 'Connection timed out.' ) );

		// Act.
		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'bar' ) );
		$res = rest_do_request( $req );

		// Assert.
		$this->assertEquals( 502, $res->get_status() );
		$this->assertEquals( 'wcpos_print_job_printnode_failed', $res->as_error()->get_error_code() );
	}

	/**
	 * It proxies the PrintNode printer list, mapped to id/name/state only.
	 */
	public function test_printnode_printers_returns_mapped_list(): void {
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id'          => 73,
						'name'        => 'Front Desk',
						'state'       => 'online',
						'description' => 'should be dropped',
					),
					array(
						'id'    => 88,
						'name'  => 'Kitchen',
						'state' => 'offline',
					),
				)
			)
		);

		$req = $this->wp_rest_post_request( '/wcpos/v1/printnode/printers' );
		$req->set_body_params( array( 'api_key' => 'KEY' ) );
		$res  = rest_do_request( $req );
		$data = $res->get_data();

		$this->assertEquals( 200, $res->get_status() );
		$this->assertEquals(
			array(
				array(
					'id'    => 73,
					'name'  => 'Front Desk',
					'state' => 'online',
				),
				array(
					'id'    => 88,
					'name'  => 'Kitchen',
					'state' => 'offline',
				),
			),
			$data['printers']
		);
		// The API key must be sent via Basic auth, never echoed back.
		$this->assertArrayNotHasKey( 'api_key', (array) $data );
	}

	/**
	 * It rejects a printer-list request with no API key, without any HTTP call.
	 */
	public function test_printnode_printers_missing_api_key_returns_400(): void {
		$this->mock_http( new \WP_Error( 'should_not_be_called', 'no http' ) );

		$req = $this->wp_rest_post_request( '/wcpos/v1/printnode/printers' );
		$req->set_body_params( array() );
		$res = rest_do_request( $req );

		$this->assertEquals( 400, $res->get_status() );
		$this->assertEquals( 'wcpos_printnode_missing_api_key', $res->as_error()->get_error_code() );
		$this->assertEquals( array(), $this->captured );
	}

	/**
	 * It rejects an API key sent via the query string (must be body-only), without any HTTP call.
	 */
	public function test_printnode_printers_api_key_in_query_returns_400_no_http(): void {
		$this->mock_http( new \WP_Error( 'should_not_be_called', 'no http' ) );

		$req = $this->wp_rest_post_request( '/wcpos/v1/printnode/printers' );
		$req->set_query_params( array( 'api_key' => 'leaky' ) );
		$res = rest_do_request( $req );

		$this->assertEquals( 400, $res->get_status() );
		$this->assertEquals( 'wcpos_printnode_api_key_in_query', $res->as_error()->get_error_code() );
		$this->assertEquals( array(), $this->captured );
	}

	/**
	 * It maps a rejected PrintNode API key (401) to a 400 client error.
	 */
	public function test_printnode_printers_invalid_key_returns_400(): void {
		$this->mock_http(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
				'headers'  => array(),
			)
		);

		$req = $this->wp_rest_post_request( '/wcpos/v1/printnode/printers' );
		$req->set_body_params( array( 'api_key' => 'wrong-key' ) );
		$res = rest_do_request( $req );

		$this->assertEquals( 400, $res->get_status() );
		$this->assertEquals( 'wcpos_printnode_printers_failed', $res->as_error()->get_error_code() );
	}

	/**
	 * It returns 502 when the PrintNode printer-list request fails.
	 */
	public function test_printnode_printers_request_failure_returns_502(): void {
		$this->mock_http( new \WP_Error( 'http_request_failed', 'Connection timed out.' ) );

		$req = $this->wp_rest_post_request( '/wcpos/v1/printnode/printers' );
		$req->set_body_params( array( 'api_key' => 'KEY' ) );
		$res = rest_do_request( $req );

		$this->assertEquals( 502, $res->get_status() );
		$this->assertEquals( 'wcpos_printnode_printers_failed', $res->as_error()->get_error_code() );
	}

	/**
	 * Build a faux 2xx response array.
	 *
	 * @param mixed $payload Payload to JSON-encode as the body.
	 * @param int   $code    HTTP status code.
	 *
	 * @return array
	 */
	private function fake_response( $payload, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $payload ),
			'headers'  => array(),
		);
	}

	/**
	 * It proxies Star Online devices for the add-printer wizard.
	 */
	public function test_star_online_devices_proxy_returns_list(): void {
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'AccessIdentifier' => 'abc',
						'ClientType'       => 'Star mC-Print2',
						'Status'           => array( 'Online' => true ),
					),
				)
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/star-online/devices' );
		$request->set_body_params(
			array(
				'cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
				'api_key'       => 'KEY',
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$devices = $response->get_data()['devices'];
		$this->assertSame( 'abc', $devices[0]['id'] );
		$this->assertSame( 'Star mC-Print2', $devices[0]['name'] );
	}

	/**
	 * It preserves unknown state when Star omits Status.Online.
	 */
	public function test_star_online_devices_proxy_returns_unknown_for_missing_online_status(): void {
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'AccessIdentifier' => 'abc',
						'ClientType'       => 'Star mC-Print2',
					),
				)
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/star-online/devices' );
		$request->set_body_params(
			array(
				'cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
				'api_key'       => 'KEY',
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'unknown', $response->get_data()['devices'][0]['state'] );
	}

	/**
	 * It rejects Star Online API keys in query strings.
	 */
	public function test_star_online_devices_rejects_api_key_in_query(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/star-online/devices' );
		$request->set_query_params( array( 'api_key' => 'KEY' ) );
		$request->set_body_params( array( 'cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot' ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * It queues and immediately submits a Star Online test print.
	 */
	public function test_test_print_star_online_queues_and_submits(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'                 => 'star',
						'name'               => 'Star',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
				'assignments' => array(),
			)
		);
		$this->mock_http( $this->fake_response( array( 'JobId' => '999' ), 201 ) );

		$req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
		$req->set_body_params( array( 'printer_id' => 'star' ) );
		$res = rest_do_request( $req );

		$this->assertEquals( 201, $res->get_status() );
		$this->assertNotEquals( 'wcpos_print_job_no_diagnostic', $res->as_error() ? $res->as_error()->get_error_code() : '' );
		$this->assertSame( '999', $res->get_data()['external_job_id'] );
		$this->assertNotFalse( wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $res->get_data()['id'] ) ) );
	}

	/**
	 * Seed a print job.
	 *
	 * @param string $printer_id Printer ID.
	 */
	private function jobs_seed( string $printer_id ): int {
		return ( new Print_Job_Service() )->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'x' ),
			)
		);
	}
}
