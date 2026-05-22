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
	 * Set up the print job CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		( new Print_Job_Service() )->register_post_type();
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
						'protocol' => 'epson-sdp',
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

		$this->assertEquals( 400, rest_do_request( $request )->get_status() );
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
