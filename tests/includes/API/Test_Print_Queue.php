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
					array(
						'id'       => 'office',
						'name'     => 'Office',
						'provider' => 'printnode',
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
		// Arrange: kitchen has a backlog (including a job it fetched before
		// dying — claimed forever) and has never polled; front is live.
		$oldest = $this->make_job( 'kitchen' );
		$this->make_job( 'kitchen' );
		$this->make_job( 'kitchen', Print_Job_Service::STATUS_CLAIMED );
		$this->make_job( 'front', Print_Job_Service::STATUS_PRINTED );
		$this->make_job( 'front', Print_Job_Service::STATUS_FAILED );
		( new Cloud_Print_Registry() )->record_seen( 'front' );

		// Act.
		$summary = $this->queue()->get_data()['summary'];

		// Assert: waiting = pending + claimed, so a stuck claimed job still
		// trips the stale banner.
		$this->assertEquals( 2, $summary['counts']['pending'] );
		$this->assertEquals( 1, $summary['counts']['claimed'] );
		$this->assertEquals( 1, $summary['counts']['printed'] );
		$this->assertEquals( 1, $summary['counts']['failed'] );
		$printers = array_column( $summary['printers'], null, 'printer_id' );
		$this->assertEquals( 3, $printers['kitchen']['pending'] );
		$this->assertEquals( 'Kitchen', $printers['kitchen']['name'] );
		$this->assertEquals(
			get_post( $oldest )->post_date_gmt,
			$printers['kitchen']['oldest_pending_gmt']
		);
		$this->assertEquals( 0, $printers['kitchen']['last_seen'] );
		$this->assertGreaterThan( 0, $printers['front']['last_seen'] );
		$this->assertEquals( 0, $printers['front']['pending'] );
		$this->assertTrue( $printers['kitchen']['polling'] );
		$this->assertFalse( $printers['office']['polling'] );
	}

	/**
	 * It treats a printer with no provider field as polling, like the print path.
	 */
	public function test_queue_summary_defaults_missing_provider_to_polling(): void {
		// Arrange: a legacy printer row saved without a provider.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'   => 'legacy',
						'name' => 'Legacy',
					),
				),
			)
		);
		$this->make_job( 'legacy' );

		// Act.
		$printers = array_column( $this->queue()->get_data()['summary']['printers'], null, 'printer_id' );

		// Assert: the stale banner must not be suppressed for it.
		$this->assertTrue( $printers['legacy']['polling'] );
	}

	/**
	 * It maps status=active to the non-terminal statuses.
	 */
	public function test_queue_active_status_shows_waiting_and_failed_only(): void {
		// Arrange: one of each state.
		$pending = $this->make_job( 'kitchen' );
		$claimed = $this->make_job( 'kitchen', Print_Job_Service::STATUS_CLAIMED );
		$failed  = $this->make_job( 'kitchen', Print_Job_Service::STATUS_FAILED );
		$this->make_job( 'kitchen', Print_Job_Service::STATUS_PRINTED );
		$this->make_job( 'kitchen', Print_Job_Service::STATUS_CANCELLED );

		// Act.
		$data = $this->queue( array( 'status' => 'active' ) )->get_data();

		// Assert: printed and cancelled history is excluded.
		$this->assertEquals( 3, $data['total'] );
		$this->assertEquals(
			array( $pending, $claimed, $failed ),
			array_column( $data['jobs'], 'id' )
		);
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
	 * It keeps a stable order across pages when jobs share a creation second.
	 */
	public function test_queue_pagination_is_stable_for_same_second_jobs(): void {
		// Arrange: three jobs created within the same second.
		$ids = array(
			$this->make_job( 'kitchen' ),
			$this->make_job( 'kitchen' ),
			$this->make_job( 'kitchen' ),
		);

		// Act: walk the queue one row per page.
		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$data = $this->queue(
				array(
					'per_page' => 1,
					'page'     => $page,
				)
			)->get_data();
			$seen[] = $data['jobs'][0]['id'];
		}

		// Assert: every job appears exactly once, in ID order.
		$this->assertEquals( $ids, $seen );
	}

	/**
	 * It preserves render metadata when retrying a template-backed job.
	 */
	public function test_retry_copies_template_metadata(): void {
		// Arrange: an auto-print style job — render metadata, no payload.
		$jobs = new Print_Job_Service();
		$id   = $jobs->create(
			array(
				'printer_id'   => 'kitchen',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => '',
				'template_id'  => 'receipt-80mm',
				'pn_kind'      => 'order',
			)
		);
		$jobs->set_status( $id, Print_Job_Service::STATUS_FAILED );

		// Act.
		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/' . $id . '/reprint' ) );

		// Assert: the copy can still render.
		$this->assertEquals( 201, $response->get_status() );
		$copy = $jobs->get( (int) $response->get_data()['id'] );
		$this->assertEquals( 'receipt-80mm', $copy['template_id'] );
		$this->assertEquals( 'order', $copy['pn_kind'] );
		$this->assertEquals( 'pending', $copy['status'] );
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
