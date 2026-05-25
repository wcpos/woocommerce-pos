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
		if ( post_type_exists( Print_Job_Service::POST_TYPE ) ) {
			unregister_post_type( Print_Job_Service::POST_TYPE );
		}

		$service = new Print_Job_Service();
		$id      = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'x' ),
			)
		);

		$this->assertEquals( 0, $id );
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

}
