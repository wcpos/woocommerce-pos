<?php
/**
 * Print job service tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WP_UnitTestCase;

/**
 * Print_Job_Service_Test class.
 */
class Print_Job_Service_Test extends WP_UnitTestCase {
	/**
	 * It registers the internal print job post type.
	 */
	public function test_register_post_type_registers_wcpos_print_job(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();

		$this->assertEquals( true, post_type_exists( 'wcpos_print_job' ) );
	}

	/**
	 * It creates and reads a pending raw payload job.
	 */
	public function test_create_then_get_returns_pending_job_with_payload(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();

		$id  = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( "\x1b@hello" ),
			)
		);
		$job = $service->get( $id );

		$this->assertEquals( 'printer-1', $job['printer_id'] );
		$this->assertEquals( 'pending', $job['status'] );
		$this->assertEquals( 'application/octet-stream', $job['content_type'] );
	}

	/**
	 * It returns zero when WordPress rejects the job insert.
	 */
	public function test_create_returns_zero_when_insert_fails(): void {
		// Force wp_insert_post() to reject the insert with a WP_Error.
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'x' ),
			)
		);

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertEquals( 0, $id );
	}

	/**
	 * It filters jobs by order_id, scoped to the requested printer.
	 */
	public function test_query_filters_jobs_by_order_id(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$service->create(
			array(
				'printer_id' => 'printer-1',
				'order_id'   => 101,
				'payload'    => base64_encode( 'a' ),
			)
		);
		$service->create(
			array(
				'printer_id' => 'printer-1',
				'order_id'   => 202,
				'payload'    => base64_encode( 'b' ),
			)
		);

		$jobs = $service->query(
			array(
				'printer_id' => 'printer-1',
				'order_id'   => 202,
			)
		);

		$this->assertEquals( 1, \count( $jobs ) );
		$this->assertEquals( 202, $jobs[0]['order_id'] );
	}

	/**
	 * Query() filters by template_id when supplied.
	 */
	public function test_query_filters_by_template_id(): void {
		$jobs = new Print_Job_Service();
		$jobs->register_post_type();

		$jobs->create(
			array(
				'printer_id'   => 'kitchen',
				'order_id'     => 5,
				'template_id'  => '11',
				'content_type' => 'text/plain',
				'payload'      => base64_encode( 'a' ),
			)
		);
		$jobs->create(
			array(
				'printer_id'   => 'kitchen',
				'order_id'     => 5,
				'template_id'  => '22',
				'content_type' => 'text/plain',
				'payload'      => base64_encode( 'b' ),
			)
		);

		$only_11 = $jobs->query(
			array(
				'printer_id'  => 'kitchen',
				'order_id'    => 5,
				'template_id' => '11',
			)
		);
		$this->assertCount( 1, $only_11 );
		$this->assertEquals( '11', $only_11[0]['template_id'] );
	}

	/**
	 * It leaves the second job pending while another claim is active.
	 */
	public function test_try_claim_rejects_second_active_claim_for_printer(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$first_id  = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'a' ),
			)
		);
		$second_id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'b' ),
			)
		);

		$this->assertEquals( true, $service->try_claim( $first_id ) );
		$this->assertEquals( false, $service->try_claim( $second_id ) );
		$this->assertEquals( 'claimed', $service->get( $first_id )['status'] );
		$this->assertEquals( 'pending', $service->get( $second_id )['status'] );
	}
	/**
	 * It does not resurrect a job cancelled between the claim's read and write.
	 */
	public function test_try_claim_loses_to_concurrent_cancellation(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'a' ),
			)
		);

		$raced = false;
		$race  = function ( $check, $object_id, $meta_key, $meta_value ) use ( $service, $id, &$raced ) {
			if ( ! $raced && $id === (int) $object_id && Print_Job_Service::META_STATUS === $meta_key && Print_Job_Service::STATUS_CLAIMED === $meta_value ) {
				$raced = true;
				$service->cancel_if_waiting( $id );
			}

			return $check;
		};
		add_filter( 'update_post_metadata', $race, 10, 4 );

		try {
			$claimed = $service->try_claim( $id );
		} finally {
			remove_filter( 'update_post_metadata', $race, 10 );
		}

		$this->assertEquals( false, $claimed );
		$this->assertEquals( 'cancelled', $service->get( $id )['status'] );
		$this->assertEmpty( get_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, true ) );
	}

	/**
	 * It records provider-neutral external submission metadata.
	 */
	public function test_record_external_submission_roundtrips(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'star',
				'content_type' => 'text/vnd.star.markup',
			)
		);

		$service->record_external_submission( $id, 'star-online', '689', 'submitted' );

		$job = $service->get( $id );
		$this->assertSame( 'star-online', $job['external_provider'] );
		$this->assertSame( '689', $job['external_job_id'] );
		$this->assertSame( 'submitted', $job['external_state'] );
	}
}
