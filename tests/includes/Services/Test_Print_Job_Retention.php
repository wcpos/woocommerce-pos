<?php
/**
 * Print job payload-strip and retention-purge tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WP_UnitTestCase;

/**
 * Test_Print_Job_Retention class.
 */
class Test_Print_Job_Retention extends WP_UnitTestCase {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Set up the job store.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs = new Print_Job_Service();
		$this->jobs->register_post_type();
	}

	/**
	 * Tear down scheduled events and filters.
	 */
	public function tearDown(): void {
		wp_clear_scheduled_hook( Print_Job_Service::PURGE_HOOK );
		remove_all_filters( 'woocommerce_pos_print_job_retention_days' );
		remove_all_filters( 'woocommerce_pos_print_job_failed_retention_days' );
		parent::tearDown();
	}

	/**
	 * Create a job, optionally aged and in a given status.
	 *
	 * @param string $status   Final status.
	 * @param int    $days_old Age in days.
	 *
	 * @return int Job ID.
	 */
	private function make_job( string $status = Print_Job_Service::STATUS_PENDING, int $days_old = 0 ): int {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'kitchen',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'RECEIPT-BYTES' ),
			)
		);
		if ( Print_Job_Service::STATUS_PENDING !== $status ) {
			$this->jobs->set_status( $id, $status );
		}
		if ( $days_old > 0 ) {
			$stamp = gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS );
			wp_update_post(
				array(
					'ID'            => $id,
					'post_date'     => $stamp,
					'post_date_gmt' => $stamp,
					'edit_date'     => true,
				)
			);
			if ( Print_Job_Service::STATUS_PENDING !== $status ) {
				update_post_meta( $id, Print_Job_Service::META_TERMINAL_AT, time() - $days_old * DAY_IN_SECONDS );
			}
		}

		return $id;
	}

	/**
	 * It strips the stored payload when a job reaches a terminal-success state.
	 */
	public function test_terminal_status_strips_payload(): void {
		// Arrange.
		$printed   = $this->make_job();
		$cancelled = $this->make_job();

		// Act.
		$this->jobs->set_status( $printed, Print_Job_Service::STATUS_PRINTED );
		$this->jobs->set_status( $cancelled, Print_Job_Service::STATUS_CANCELLED );

		// Assert: metadata survives for the duplicate-trigger guard; bytes don't.
		$this->assertEquals( '', $this->jobs->get( $printed )['payload'] );
		$this->assertEquals( '', $this->jobs->get( $cancelled )['payload'] );
		$this->assertEquals( 'printed', $this->jobs->get( $printed )['status'] );
		$this->assertEquals( 'kitchen', $this->jobs->get( $printed )['printer_id'] );
	}

	/**
	 * It keeps the payload on failed jobs so Retry can copy it.
	 */
	public function test_failed_jobs_keep_payload_for_retry(): void {
		// Arrange / Act.
		$failed = $this->make_job( Print_Job_Service::STATUS_FAILED );

		// Assert.
		$this->assertEquals( base64_encode( 'RECEIPT-BYTES' ), $this->jobs->get( $failed )['payload'] );
	}

	/**
	 * It purges expired terminal jobs on their retention windows and nothing else.
	 */
	public function test_purge_deletes_expired_terminal_jobs_only(): void {
		// Arrange: printed/cancelled expire after 7 days, failed after 30.
		$old_printed    = $this->make_job( Print_Job_Service::STATUS_PRINTED, 8 );
		$new_printed    = $this->make_job( Print_Job_Service::STATUS_PRINTED, 6 );
		$old_cancelled  = $this->make_job( Print_Job_Service::STATUS_CANCELLED, 8 );
		$old_failed     = $this->make_job( Print_Job_Service::STATUS_FAILED, 31 );
		$recent_failed  = $this->make_job( Print_Job_Service::STATUS_FAILED, 8 );
		$ancient_queued = $this->make_job( Print_Job_Service::STATUS_PENDING, 40 );

		// Act.
		$this->jobs->purge_expired();

		// Assert: waiting jobs are never purged, whatever their age.
		$this->assertNull( $this->jobs->get( $old_printed ) );
		$this->assertNull( $this->jobs->get( $old_cancelled ) );
		$this->assertNull( $this->jobs->get( $old_failed ) );
		$this->assertNotNull( $this->jobs->get( $new_printed ) );
		$this->assertNotNull( $this->jobs->get( $recent_failed ) );
		$this->assertNotNull( $this->jobs->get( $ancient_queued ) );
	}

	/**
	 * It keeps jobs forever when a retention filter returns zero.
	 */
	public function test_purge_retention_filter_zero_disables_purging(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_print_job_retention_days', '__return_zero' );
		$old_printed = $this->make_job( Print_Job_Service::STATUS_PRINTED, 100 );
		$old_failed  = $this->make_job( Print_Job_Service::STATUS_FAILED, 100 );

		// Act.
		$this->jobs->purge_expired();

		// Assert: only the filtered window is disabled.
		$this->assertNotNull( $this->jobs->get( $old_printed ) );
		$this->assertNull( $this->jobs->get( $old_failed ) );
	}

	/**
	 * It keys retention on when the job ENDED, not when it was created.
	 */
	public function test_purge_retention_clock_starts_at_terminal_status(): void {
		// Arrange: created 40 days ago, but only just printed/failed.
		$late_print = $this->make_job();
		$late_fail  = $this->make_job();
		$stamp      = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		foreach ( array( $late_print, $late_fail ) as $id ) {
			wp_update_post(
				array(
					'ID'            => $id,
					'post_date'     => $stamp,
					'post_date_gmt' => $stamp,
					'edit_date'     => true,
				)
			);
		}
		$this->jobs->set_status( $late_print, Print_Job_Service::STATUS_PRINTED );
		$this->jobs->set_status( $late_fail, Print_Job_Service::STATUS_FAILED );

		// Act.
		$this->jobs->purge_expired();

		// Assert: both get their full retention window from today.
		$this->assertNotNull( $this->jobs->get( $late_print ) );
		$this->assertNotNull( $this->jobs->get( $late_fail ) );
	}

	/**
	 * It purges legacy terminal rows that predate the terminal-at meta.
	 */
	public function test_purge_falls_back_to_creation_date_for_legacy_rows(): void {
		// Arrange: an old printed row with no terminal-at meta.
		$legacy = $this->make_job( Print_Job_Service::STATUS_PRINTED, 8 );
		delete_post_meta( $legacy, Print_Job_Service::META_TERMINAL_AT );

		// Act.
		$this->jobs->purge_expired();

		// Assert.
		$this->assertNull( $this->jobs->get( $legacy ) );
	}

	/**
	 * It registers the purge cron callback exactly once across service instances.
	 */
	public function test_purge_callback_registers_once(): void {
		// Arrange: three constructions, as Init + trigger + submit do per request.
		new Print_Job_Service();
		new Print_Job_Service();
		new Print_Job_Service();

		// Act.
		$hook      = $GLOBALS['wp_filter'][ Print_Job_Service::PURGE_HOOK ] ?? null;
		$callbacks = null !== $hook ? \count( $hook->callbacks[10] ?? array() ) : 0;

		// Assert: identical static callbacks dedupe to a single registration.
		$this->assertEquals( 1, $callbacks );
	}

	/**
	 * It schedules the daily purge event on init.
	 */
	public function test_purge_event_is_scheduled(): void {
		// Arrange.
		wp_clear_scheduled_hook( Print_Job_Service::PURGE_HOOK );

		// Act: register_post_type runs on init in production.
		$this->jobs->register_post_type();

		// Assert.
		$this->assertNotFalse( wp_next_scheduled( Print_Job_Service::PURGE_HOOK ) );
	}
}
