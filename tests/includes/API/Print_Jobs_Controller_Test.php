<?php
/**
 * Print jobs controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

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
		parent::tearDown();
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
		$this->assertEquals( 555, $res->get_data()['pn_job_id'] );

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
