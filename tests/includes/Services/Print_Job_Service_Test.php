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
	 * It refuses to cancel a non-print post supplied as a job ID.
	 */
	public function test_cancel_if_waiting_refuses_non_print_post(): void {
		$service = new Print_Job_Service();
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Content that must be preserved.',
			)
		);

		$cancelled = $service->cancel_if_waiting( $post_id );

		$this->assertEquals( false, $cancelled );
		$this->assertEquals( 'Content that must be preserved.', get_post_field( 'post_content', $post_id ) );
		$this->assertEquals( '', get_post_meta( $post_id, Print_Job_Service::META_STATUS, true ) );
	}

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
	 * It does not delete a waiting job while its lifecycle lock is held.
	 */
	public function test_delete_refuses_waiting_job_while_lifecycle_lock_is_held(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id' => 'printer-1',
				'payload'    => base64_encode( 'a' ),
			)
		);
		add_option( Print_Job_Service::LIFECYCLE_LOCK_PREFIX . $id, (string) time(), '', false );

		try {
			$deleted = $service->delete( $id );
		} finally {
			delete_option( Print_Job_Service::LIFECYCLE_LOCK_PREFIX . $id );
		}

		$this->assertFalse( $deleted );
		$this->assertSame( Print_Job_Service::STATUS_PENDING, $service->get( $id )['status'] );
	}

	/**
	 * It reports when WordPress refuses to delete a terminal job.
	 */
	public function test_delete_propagates_wordpress_failure(): void {
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id' => 'printer-1',
				'payload'    => base64_encode( 'a' ),
			)
		);
		$service->set_status( $id, Print_Job_Service::STATUS_PRINTED );
		add_filter( 'pre_delete_post', '__return_false' );

		try {
			$deleted = $service->delete( $id );
		} finally {
			remove_filter( 'pre_delete_post', '__return_false' );
		}

		$this->assertFalse( $deleted );
		$this->assertSame( Print_Job_Service::POST_TYPE, get_post_type( $id ) );
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
	 * It fails a stale claim without making it eligible for automatic delivery.
	 */
	public function test_release_stale_claims_stale_claim_marks_failed_and_unconfirmed(): void {
		// Arrange.
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'a' ),
			)
		);
		$service->claim( $id );
		update_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, time() - Print_Job_Service::CLAIM_TTL - 1 );

		// Act.
		$service->release_stale_claims( 'printer-1' );

		// Assert.
		$this->assertSame( Print_Job_Service::STATUS_FAILED, $service->get( $id )['status'] );
		$this->assertSame( 'claim_timeout', get_post_meta( $id, Print_Job_Service::META_ERROR, true ) );
		$this->assertTrue( $service->get( $id )['unconfirmed'] );
		$this->assertSame( '1', get_post_meta( $id, Print_Job_Service::META_UNCONFIRMED, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $id, Print_Job_Service::META_TERMINAL_AT, true ) );
	}

	/**
	 * It leaves a fresh in-flight claim unchanged.
	 */
	public function test_release_stale_claims_fresh_claim_remains_claimed(): void {
		// Arrange.
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'a' ),
			)
		);
		$service->claim( $id );
		$claimed_at = (int) get_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, true );

		// Act.
		$service->release_stale_claims( 'printer-1' );

		// Assert.
		$this->assertSame( Print_Job_Service::STATUS_CLAIMED, $service->get( $id )['status'] );
		$this->assertSame( $claimed_at, (int) get_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, true ) );
		$this->assertSame( '', get_post_meta( $id, Print_Job_Service::META_UNCONFIRMED, true ) );
		$this->assertSame( '', get_post_meta( $id, Print_Job_Service::META_ERROR, true ) );
	}

	/**
	 * It does not overwrite a cancellation that lands during stale cleanup.
	 */
	public function test_release_stale_claims_concurrent_cancellation_remains_cancelled(): void {
		// Arrange.
		$service = new Print_Job_Service();
		$service->register_post_type();
		$id = $service->create(
			array(
				'printer_id'   => 'printer-1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'a' ),
			)
		);
		$service->claim( $id );
		update_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, time() - Print_Job_Service::CLAIM_TTL - 1 );

		$raced = false;
		$race  = function ( $delete, $object_id, $meta_key ) use ( $service, $id, &$raced ) {
			if ( ! $raced && $id === (int) $object_id && Print_Job_Service::META_CLAIMED_AT === $meta_key ) {
				$raced = true;
				$service->cancel_if_waiting( $id );
			}

			return $delete;
		};
		add_filter( 'delete_post_metadata', $race, 10, 3 );

		// Act.
		try {
			$service->release_stale_claims( 'printer-1' );
		} finally {
			remove_filter( 'delete_post_metadata', $race, 10 );
		}

		// Assert.
		$this->assertSame( Print_Job_Service::STATUS_CANCELLED, $service->get( $id )['status'] );
		$this->assertSame( '', get_post_meta( $id, Print_Job_Service::META_UNCONFIRMED, true ) );
		$this->assertSame( '', get_post_meta( $id, Print_Job_Service::META_ERROR, true ) );
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
	/**
	 * A late result is attributed to an unconfirmed job only within the window.
	 */
	public function test_find_unconfirmed_ignores_a_job_failed_outside_the_result_window(): void {
		// Arrange: a claim that went stale and was failed as unconfirmed.
		$service = new Print_Job_Service();
		$id      = $service->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/xml',
				'payload'      => base64_encode( '<epos-print/>' ),
			)
		);
		$service->claim( $id );
		update_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, time() - Print_Job_Service::CLAIM_TTL - 1 );
		$service->release_stale_claims( 'p1' );
		$this->assertSame( $id, $service->find_unconfirmed( 'p1' )['id'] );

		// Act: age it past the window.
		update_post_meta( $id, Print_Job_Service::META_TERMINAL_AT, time() - Print_Job_Service::UNCONFIRMED_RESULT_WINDOW - 1 );

		// Assert.
		$this->assertNull( $service->find_unconfirmed( 'p1' ) );
	}
}
