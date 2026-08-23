<?php
/**
 * Epson Server Direct Print tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Jobs_EpsonSDP_Test class.
 */
class Print_Jobs_EpsonSDP_Test extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Captured log messages.
	 *
	 * @var array<int, string>
	 */
	private array $logged_messages = array();

	/**
	 * Set up a registered Epson SDP printer.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs            = new Print_Job_Service();
		$this->logged_messages = array();
		$this->jobs->register_post_type();
		Logger::reset_dedup_state();
		add_filter(
			'woocommerce_pos_logging',
			function ( $should_log, $message ) {
				$this->logged_messages[] = $message;

				return false;
			},
			10,
			2
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'provider'        => 'epson-sdp',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_logging' );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/**
	 * Post to the Epson SDP route.
	 *
	 * @param string $body Request body.
	 */
	private function sdp( string $body ) {
		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/print-jobs/epson-sdp' );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_query_params(
			array(
				'printer_id' => 'p1',
				'pt'         => 'tok',
			)
		);
		$request->set_body( $body );

		return rest_do_request( $request );
	}

	/**
	 * It claims the next job when the printer polls for work.
	 */
	public function test_poll_claims_next_job_and_returns_200(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);

		$response = $this->sdp( '<PrintRequestInfo><ConnectionType>GET</ConnectionType></PrintRequestInfo>' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It wraps the print data in the Server Direct Print response envelope.
	 *
	 * The printer discards a response it cannot recognise without printing or
	 * posting a result, so the wire format is the whole contract here: asserting
	 * only the status code and the job state passes against a payload no printer
	 * will ever print.
	 */
	public function test_poll_wraps_print_data_in_the_sdp_envelope(): void {
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);

		$body = $this->sdp( '<PrintRequestInfo><ConnectionType>GET</ConnectionType></PrintRequestInfo>' )->get_raw_body();

		$this->assertSame(
			'<?xml version="1.0" encoding="utf-8"?>'
				. '<PrintRequestInfo Version="1.00"><ePOSPrint>'
				. '<Parameter><devid>local_printer</devid><timeout>10000</timeout></Parameter>'
				. '<PrintData><epos-print/></PrintData>'
				. '</ePOSPrint></PrintRequestInfo>',
			$body
		);
		$this->assertStringNotContainsString( 'Envelope', $body );
	}

	/**
	 * It confirms the single in-flight claim on result posts.
	 */
	public function test_result_post_confirms_the_in_flight_claim(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);
		$this->jobs->claim( $id );

		$response = $this->sdp( '<response success="true" code="" status="251658262"/>' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It logs a failed result reported by the printer.
	 */
	public function test_failed_result_logs_printer_failure(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);
		$this->jobs->claim( $id );

		$response = $this->sdp( '<response success="false" code="EPOS2_ERR_PRINT" status="251658262"/>' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'failed', $this->jobs->get( $id )['status'] );
		$this->assertEquals(
			array( sprintf( '/wcpos/v1/print-jobs/epson-sdp: printer "p1" reported failure code "EPOS2_ERR_PRINT" for print job %d.', $id ) ),
			$this->logged_messages
		);
	}

	/**
	 * It authenticates and claims a job from path credentials alone.
	 *
	 * Mirrors the CloudPRNT path-credential route: printer_id and pt ride in
	 * the URL path, no credential query params and no WCPOS header — only the
	 * `wcpos=1` marker query pair.
	 */
	public function test_epson_sdp_path_credentials_poll_claims_next_job(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);

		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/print-jobs/epson-sdp/p1/tok' );
		$request->set_query_params( array( 'wcpos' => '1' ) );
		$request->set_body( '<PrintRequestInfo><ConnectionType>GET</ConnectionType></PrintRequestInfo>' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );
	}
}
