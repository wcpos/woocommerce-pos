<?php
/**
 * Epson Server Direct Print tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

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
	 * Set up a registered Epson SDP printer.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs = new Print_Job_Service();
		$this->jobs->register_post_type();
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'protocol'        => 'epson-sdp',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
			)
		);
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
}
