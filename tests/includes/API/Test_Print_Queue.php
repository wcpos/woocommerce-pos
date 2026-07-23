<?php
/**
 * Cloud print queue endpoint tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Test_Print_Queue class.
 */
class Test_Print_Queue extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Set up two registered polling printers.
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
						'id'       => 'kitchen',
						'name'     => 'Kitchen',
						'provider' => 'star-cloudprnt',
					),
					array(
						'id'       => 'front',
						'name'     => 'Front counter',
						'provider' => 'epson-sdp',
					),
				),
			)
		);
	}

	/**
	 * Tear down runtime state.
	 */
	public function tearDown(): void {
		delete_option( Cloud_Print_Registry::RUNTIME_OPTION );
		parent::tearDown();
	}

	/**
	 * Create a job directly in the store.
	 *
	 * @param string $printer_id Printer ID.
	 * @param string $status     Job status.
	 *
	 * @return int Job ID.
	 */
	private function make_job( string $printer_id, string $status = Print_Job_Service::STATUS_PENDING ): int {
		$id = $this->jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'RECEIPT-BYTES' ),
			)
		);
		if ( Print_Job_Service::STATUS_PENDING !== $status ) {
			$this->jobs->set_status( $id, $status );
		}

		return $id;
	}

	/**
	 * Fetch the queue endpoint.
	 *
	 * @param array $params Query params.
	 *
	 * @return \WP_REST_Response
	 */
	private function queue( array $params = array() ) {
		$request = $this->wp_rest_get_request( '/wcpos/v1/print-jobs/queue' );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * It paginates jobs oldest-first and never includes the payload.
	 */
	public function test_queue_paginates_jobs_without_payload(): void {
		// Arrange.
		$first = $this->make_job( 'kitchen' );
		$this->make_job( 'kitchen' );
		$this->make_job( 'front' );

		// Act.
		$response = $this->queue(
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$data     = $response->get_data();

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, $data['total'] );
		$this->assertCount( 2, $data['jobs'] );
		$this->assertEquals( $first, $data['jobs'][0]['id'] );
		$this->assertEquals( 'kitchen', $data['jobs'][0]['printer_id'] );
		$this->assertEquals( 'pending', $data['jobs'][0]['status'] );
		$this->assertNotEmpty( $data['jobs'][0]['created_gmt'] );
		$this->assertArrayNotHasKey( 'payload', $data['jobs'][0] );
		$this->assertStringNotContainsString( 'RECEIPT-BYTES', wp_json_encode( $data ) );
	}

	/**
	 * It filters by printer and status.
	 */
	public function test_queue_filters_by_printer_and_status(): void {
		// Arrange.
		$this->make_job( 'kitchen' );
		$this->make_job( 'front' );
		$failed = $this->make_job( 'front', Print_Job_Service::STATUS_FAILED );

		// Act.
		$data = $this->queue(
			array(
				'printer_id' => 'front',
				'status'     => 'failed',
			)
		)->get_data();

		// Assert.
		$this->assertEquals( 1, $data['total'] );
		$this->assertEquals( $failed, $data['jobs'][0]['id'] );
	}

	/**
	 * It reports status counts and per-printer backlog with staleness data.
	 */
	public function test_queue_summary_reports_counts_and_backlog(): void {
		// Arrange: kitchen has a backlog and has never polled; front is live.
		$oldest = $this->make_job( 'kitchen' );
		$this->make_job( 'kitchen' );
		$this->make_job( 'front', Print_Job_Service::STATUS_PRINTED );
		$this->make_job( 'front', Print_Job_Service::STATUS_FAILED );
		( new Cloud_Print_Registry() )->record_seen( 'front' );

		// Act.
		$summary = $this->queue()->get_data()['summary'];

		// Assert.
		$this->assertEquals( 2, $summary['counts']['pending'] );
		$this->assertEquals( 1, $summary['counts']['printed'] );
		$this->assertEquals( 1, $summary['counts']['failed'] );
		$printers = array_column( $summary['printers'], null, 'printer_id' );
		$this->assertEquals( 2, $printers['kitchen']['pending'] );
		$this->assertEquals( 'Kitchen', $printers['kitchen']['name'] );
		$this->assertEquals(
			get_post( $oldest )->post_date_gmt,
			$printers['kitchen']['oldest_pending_gmt']
		);
		$this->assertEquals( 0, $printers['kitchen']['last_seen'] );
		$this->assertGreaterThan( 0, $printers['front']['last_seen'] );
		$this->assertEquals( 0, $printers['front']['pending'] );
	}

	/**
	 * It bulk-cancels waiting jobs by id and by printer, never printed ones.
	 */
	public function test_queue_bulk_cancel(): void {
		// Arrange.
		$a       = $this->make_job( 'kitchen' );
		$b       = $this->make_job( 'kitchen' );
		$printed = $this->make_job( 'kitchen', Print_Job_Service::STATUS_PRINTED );
		$other   = $this->make_job( 'front' );

		// Act: cancel one by id, then the rest of kitchen by printer.
		$by_id      = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/queue/cancel' );
		$by_id->set_body_params( array( 'ids' => array( $a, $printed ) ) );
		$id_data    = rest_do_request( $by_id )->get_data();
		$by_printer = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/queue/cancel' );
		$by_printer->set_body_params( array( 'printer_id' => 'kitchen' ) );
		$printer_data = rest_do_request( $by_printer )->get_data();

		// Assert: printed jobs are untouched, other printers are untouched.
		$this->assertEquals( 1, $id_data['cancelled'] );
		$this->assertEquals( 1, $printer_data['cancelled'] );
		$this->assertEquals( 'cancelled', $this->jobs->get( $a )['status'] );
		$this->assertEquals( 'cancelled', $this->jobs->get( $b )['status'] );
		$this->assertEquals( 'printed', $this->jobs->get( $printed )['status'] );
		$this->assertEquals( 'pending', $this->jobs->get( $other )['status'] );
	}

	/**
	 * It rejects anonymous requests.
	 */
	public function test_queue_requires_authentication(): void {
		// Arrange.
		wp_set_current_user( 0 );

		// Act.
		$response = $this->queue();

		// Assert.
		$this->assertEquals( 401, $response->get_status() );
	}
}
