<?php
/**
 * Tests for the change-log retention cron service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Change_Log_Purge;

/**
 * The daily purge compacts superseded history and expires old tombstones.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Change_Log_Purge
 */
class Test_Change_Log_Purge extends Sync_Store_Test_Case {
	/**
	 * The log under test.
	 *
	 * @var Change_Log
	 */
	private $log;

	/**
	 * Start each test against an empty stream and clean cron/watermark state.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->log = new Change_Log();
		delete_option( Change_Log::PRUNE_WATERMARK_OPTION );
		wp_clear_scheduled_hook( Change_Log_Purge::PURGE_HOOK );
	}

	/**
	 * Remove retention filters and cron state added by individual tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_change_log_tombstone_retention_days' );
		delete_option( Change_Log::PRUNE_WATERMARK_OPTION );
		wp_clear_scheduled_hook( Change_Log_Purge::PURGE_HOOK );
		parent::tearDown();
	}

	/**
	 * First object id used by bulk-seeded rows that must not collide with each other.
	 */
	const SEEDED_OBJECT_ID_BASE = 100000;

	/**
	 * Bulk-insert aged rows straight into the log, bypassing the recording hooks.
	 *
	 * Seeding a backlog through record() would be thousands of round trips; the
	 * purge only reads columns, so one prepared multi-row insert is equivalent.
	 *
	 * @param string   $change_type      Change type for every inserted row.
	 * @param int      $count            Number of rows to insert.
	 * @param int      $days             Age of every inserted row, in days.
	 * @param int|null $shared_object_id Object id shared by all rows (making all but the
	 *                                   newest superseded), or null to give each row its own.
	 */
	private function seed_aged_rows( string $change_type, int $count, int $days, ?int $shared_object_id = null ): void {
		global $wpdb;

		$created = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$values  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare(
				'(%s,%d,%s,%s,%s,%s)',
				'product',
				$shared_object_id ?? ( self::SEEDED_OBJECT_ID_BASE + $i ),
				$change_type,
				$created,
				'test',
				$created
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every value tuple is prepared above.
		$wpdb->query(
			'INSERT INTO ' . $this->log->table_name()
			. ' (object_type, object_id, change_type, modified_gmt, origin, created_gmt) VALUES '
			. implode( ',', $values )
		);
	}

	/**
	 * Count the rows currently in the log, optionally of one change type.
	 *
	 * @param string $change_type Change type to count, or an empty string for all rows.
	 */
	private function count_rows( string $change_type = '' ): int {
		global $wpdb;

		if ( '' === $change_type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table name.
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->log->table_name() );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->log->table_name() . ' WHERE change_type = %s',
				$change_type
			)
		);
	}

	/**
	 * Rewrite a row's created_gmt so it falls outside a retention window.
	 *
	 * @param int $sequence Row to age.
	 * @param int $days     Days to move into the past.
	 */
	private function age_row( int $sequence, int $days ): void {
		global $wpdb;

		$wpdb->update(
			$this->log->table_name(),
			array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ),
			array( 'sequence' => $sequence )
		);
	}

	/**
	 * A full run compacts old superseded rows but keeps recent history intact.
	 */
	public function test_purge_expired_with_aged_superseded_row_compacts_it_and_keeps_recent_rows(): void {
		// Arrange.
		$this->log->record( 'product', 11, 'create', 'test', false );
		$aged_superseded = $this->log->head_sequence();
		$this->log->record( 'product', 11, 'update', 'test', false );
		$recent_superseded = $this->log->head_sequence();
		$this->log->record( 'product', 11, 'update', 'test', false );
		$latest = $this->log->head_sequence();
		$this->age_row( $aged_superseded, 2 );

		// Act.
		( new Change_Log_Purge( $this->log ) )->purge_expired();
		$sequences = array_column( $this->log->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert: only the aged superseded row is outside the 24h window.
		$this->assertEquals( array( $recent_superseded, $latest ), $sequences );
	}

	/**
	 * Tombstones older than the retention window are pruned by a run.
	 */
	public function test_purge_expired_with_aged_tombstone_prunes_it_and_advances_watermark(): void {
		global $wpdb;

		// Arrange.
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$aged_tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'delete', 'test', false );
		$recent_tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 33, 'update', 'test', false );
		$this->age_row( $aged_tombstone, 91 );
		$block_watermark = static function ( string $query ): string {
			if ( false !== strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, Change_Log::PRUNE_WATERMARK_OPTION ) ) {
				return 'SELECT * FROM wcpos_missing_watermark_table';
			}

			return $query;
		};
		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $block_watermark );

		// Act.
		( new Change_Log_Purge( $this->log ) )->purge_expired();
		$after_failed_watermark = array_column( $this->log->page( array(), 0, 20 )['rows'], 'sequence' );
		remove_filter( 'query', $block_watermark );
		$wpdb->suppress_errors( $previous_suppress_errors );
		( new Change_Log_Purge( $this->log ) )->purge_expired();
		$sequences = array_column( $this->log->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert: deletion waits for a persisted horizon, then advertises the pruned interval.
		$this->assertContains( $aged_tombstone, $after_failed_watermark );
		$this->assertEquals( array( $recent_tombstone, $this->log->head_sequence() ), $sequences );
		$this->assertEquals( $aged_tombstone, $this->log->prune_watermark() );
	}

	/**
	 * The newest row survives pruning even when it is an expired tombstone.
	 */
	public function test_purge_expired_with_aged_tombstone_at_head_keeps_head_row(): void {
		// Arrange: an idle store whose last event was a deletion long ago.
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$aged_below_head = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'delete', 'test', false );
		$head = $this->log->head_sequence();
		$this->age_row( $aged_below_head, 91 );
		$this->age_row( $head, 91 );

		// Act.
		( new Change_Log_Purge( $this->log ) )->purge_expired();

		// Assert: rows below head prune; head itself never regresses.
		$this->assertEquals( $head, $this->log->head_sequence() );
		$this->assertEquals( $aged_below_head, $this->log->prune_watermark() );
	}

	/**
	 * Non-positive tombstone retention keeps every tombstone forever.
	 */
	public function test_purge_expired_with_zero_retention_filter_keeps_aged_tombstones(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_change_log_tombstone_retention_days', '__return_zero' );
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$aged_tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'update', 'test', false );
		$this->age_row( $aged_tombstone, 400 );

		// Act.
		( new Change_Log_Purge( $this->log ) )->purge_expired();

		// Assert.
		$this->assertEquals( $aged_tombstone, $this->log->oldest_sequence() );
		$this->assertEquals( 0, $this->log->prune_watermark() );
	}

	/**
	 * A compaction backlog cannot consume the whole per-run budget.
	 *
	 * Compaction runs first and, on a busy store, can supply more work than a
	 * single run may delete. Tombstone pruning is the only operation that
	 * advances the prune watermark, so starving it stalls the completeness
	 * boundary forever — while the hard per-run ceiling must still hold.
	 */
	public function test_purge_expired_with_compaction_backlog_still_prunes_expired_tombstones(): void {
		// Arrange: more compactable rows than one run may delete, plus expired tombstones.
		$this->seed_aged_rows( 'update', Change_Log_Purge::MAX_DELETES_PER_RUN + 2, 2, 11 );
		$this->seed_aged_rows( 'delete', 3, 91 );
		$this->log->record( 'product', 22, 'update', 'test', false );
		$before = $this->count_rows();

		// Act.
		( new Change_Log_Purge( $this->log ) )->purge_expired();
		$deleted = $before - $this->count_rows();

		// Assert: pruning got its slice, the ceiling held, and the backlog reschedules.
		$this->assertEquals( 0, $this->count_rows( 'delete' ) );
		$this->assertGreaterThan( 0, $this->log->prune_watermark() );
		$this->assertLessThanOrEqual( Change_Log_Purge::MAX_DELETES_PER_RUN, $deleted );
		$this->assertNotEquals( false, wp_next_scheduled( Change_Log_Purge::PURGE_HOOK ) );
	}

	/**
	 * Registering hooks schedules the daily cron exactly once.
	 */
	public function test_register_hooks_without_existing_schedule_registers_daily_purge_event(): void {
		// Arrange.
		$service = new Change_Log_Purge( $this->log );

		// Act: construction alone must not touch cron state.
		$before_registration = wp_next_scheduled( Change_Log_Purge::PURGE_HOOK );
		$service->register_hooks();
		$first_run = wp_next_scheduled( Change_Log_Purge::PURGE_HOOK );
		( new Change_Log_Purge( $this->log ) )->register_hooks();

		// Assert: second registration reuses the existing schedule.
		$this->assertEquals( false, $before_registration );
		$this->assertNotEquals( false, $first_run );
		$this->assertEquals( $first_run, wp_next_scheduled( Change_Log_Purge::PURGE_HOOK ) );
	}
}
