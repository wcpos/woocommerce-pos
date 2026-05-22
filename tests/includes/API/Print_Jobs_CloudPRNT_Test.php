<?php
/**
 * Star CloudPRNT print job tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Jobs_CloudPRNT_Test class.
 */
class Print_Jobs_CloudPRNT_Test extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Set up a registered Star CloudPRNT printer.
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
						'protocol'        => 'star-cloudprnt',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
			)
		);
	}

	/**
	 * Poll the CloudPRNT route.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Query params.
	 */
	private function poll( string $method, array $params ) {
		$request = new \WP_REST_Request( $method, '/wcpos/v1/print-jobs/cloudprnt' );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_query_params(
			array_merge(
				array(
					'printer_id' => 'p1',
					'pt'         => 'tok',
				),
				$params
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * It advertises a pending job to a token-authenticated printer.
	 */
	public function test_poll_advertises_pending_job_with_token(): void {
		$id   = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);
		$data = $this->poll( 'POST', array() )->get_data();

		$this->assertEquals( true, $data['jobReady'] );
		$this->assertEquals( (string) $id, $data['jobToken'] );
	}

	/**
	 * It claims on GET and marks printed on DELETE.
	 */
	public function test_get_then_delete_claims_then_marks_printed(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$this->assertEquals( 200, $this->poll( 'GET', array( 'token' => $id ) )->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );

		$this->assertEquals( 200, $this->poll( 'DELETE', array( 'token' => $id ) )->get_status() );
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It serializes one in-flight job per printer.
	 */
	public function test_poll_serializes_one_job_per_printer(): void {
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'A' ),
			)
		);
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'B' ),
			)
		);

		$first = $this->poll( 'POST', array() )->get_data();
		$this->poll( 'GET', array( 'token' => $first['jobToken'] ) );

		$this->assertEquals( false, $this->poll( 'POST', array() )->get_data()['jobReady'] );
	}

	/**
	 * It rejects invalid printer tokens.
	 */
	public function test_poll_rejects_bad_token_with_401(): void {
		$this->assertEquals( 401, $this->poll( 'POST', array( 'pt' => 'wrong' ) )->get_status() );
	}
}
