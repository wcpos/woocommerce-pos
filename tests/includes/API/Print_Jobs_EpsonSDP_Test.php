<?php
/**
 * Epson Server Direct Print tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\V1\Print_Jobs_Controller;
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
				. '<Parameter><devid>local_printer</devid><timeout>' . Print_Jobs_Controller::EPSON_SDP_PRINT_TIMEOUT_MS . '</timeout></Parameter>'
				. '<PrintData><epos-print/></PrintData>'
				. '</ePOSPrint></PrintRequestInfo>',
			$body
		);
		$this->assertStringNotContainsString( 'Envelope', $body );
	}

	/**
	 * Post to the Epson SDP route the way the printer actually does: URL-encoded
	 * form data, with the request type in ConnectionType.
	 *
	 * @param array<string, string> $fields Form fields.
	 */
	private function sdp_form( array $fields ) {
		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/print-jobs/epson-sdp' );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_query_params(
			array(
				'printer_id' => 'p1',
				'pt'         => 'tok',
			)
		);
		$request->set_body( http_build_query( $fields ) );
		// WP_REST_Request::get_parameter_order() skips parse_body_params() when the
		// method is POST — a served request gets its POST bag from
		// WP_REST_Server::serve_request(), which copies $_POST in. rest_do_request()
		// bypasses that, so without this the fields are invisible to get_param() and
		// every request looks like an untyped poll.
		$request->set_body_params( $fields );

		return rest_do_request( $request );
	}

	/**
	 * It serves a job to a GetRequest poll.
	 */
	public function test_get_request_poll_receives_the_job(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);

		$response = $this->sdp_form(
			array(
				'ConnectionType' => 'GetRequest',
				'ID'             => '',
			)
		);

		$this->assertStringContainsString( '<PrintData><epos-print/></PrintData>', $response->get_raw_body() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It never hands a job to a status notification.
	 *
	 * A status notification shares the poll URL with the job poll but is not
	 * asking for work. Answering it with print data loses the job: the printer
	 * discards the response, so it neither prints nor reports a result.
	 */
	public function test_status_notification_does_not_consume_the_job(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);

		$response = $this->sdp_form(
			array(
				'ConnectionType' => 'SetStatus',
				'ID'             => '',
				'Status'         => '<statusmonitor Version="1.00"><printerstatus devicename="local_printer" asbstatus="0x00000001"/></statusmonitor>',
			)
		);

		$this->assertStringNotContainsString( 'PrintData', $response->get_raw_body() );
		$this->assertEquals( 'pending', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It reads the print result out of the ResponseFile form field.
	 *
	 * The printer posts the result XML as form data, so in the raw body it is
	 * percent-encoded and a raw-body substring test can never see it.
	 */
	public function test_set_response_form_post_confirms_the_claim(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);
		$this->jobs->claim( $id );

		$this->sdp_form(
			array(
				'ConnectionType' => 'SetResponse',
				'ID'             => '',
				'ResponseFile'   => '<?xml version="1.0" encoding="utf-8"?><PrintResponseInfo Version="1.00">'
					. '<response xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print" success="true" code="" status="251854870" battery="0"/>'
					. '</PrintResponseInfo>',
			)
		);

		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It marks a failed result and logs the printer's code from the form field.
	 */
	public function test_set_response_form_post_records_failure(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);
		$this->jobs->claim( $id );

		$this->sdp_form(
			array(
				'ConnectionType' => 'SetResponse',
				'ID'             => '',
				'ResponseFile'   => '<?xml version="1.0" encoding="utf-8"?><PrintResponseInfo Version="1.00">'
					. '<response success="false" code="EPTR_REC_EMPTY" status="251854870"/>'
					. '</PrintResponseInfo>',
			)
		);

		$this->assertEquals( 'failed', $this->jobs->get( $id )['status'] );
		$this->assertEquals(
			array( sprintf( '/wcpos/v1/print-jobs/epson-sdp: printer "p1" reported failure code "EPTR_REC_EMPTY" for print job %d.', $id ) ),
			$this->logged_messages
		);
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
