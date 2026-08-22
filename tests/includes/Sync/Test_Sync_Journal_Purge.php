<?php
/**
 * Tests for the unified-journal retention cron service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge;

/**
 * The daily purge compacts superseded history and expires old tombstones.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge
 */
class Test_Sync_Journal_Purge extends Sync_Store_Test_Case {
	/**
	 * The log under test.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * Start each test against an empty stream and clean cron/watermark state.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		Sync_Journal::reset_prune_watermarks();
		wp_clear_scheduled_hook( Sync_Journal_Purge::PURGE_HOOK );
	}

	/**
	 * Remove retention filters and cron state added by individual tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_change_log_tombstone_retention_days' );
		remove_all_filters( 'woocommerce_pos_sync_mutation_failure_retention_days' );
		Sync_Journal::reset_prune_watermarks();
		wp_clear_scheduled_hook( Sync_Journal_Purge::PURGE_HOOK );
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
	 * @param bool     $deleted         Deleted flag for every inserted row.
	 * @param int      $count            Number of rows to insert.
	 * @param int      $days             Age of every inserted row, in days.
	 * @param int|null $shared_object_id Object id shared by all rows (making all but the
	 *                                   newest superseded), or null to give each row its own.
	 */
	private function seed_aged_rows( bool $deleted, int $count, int $days, ?int $shared_object_id = null ): void {
		global $wpdb;

		$created = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$values  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare(
				'(%s,%d,%d,%s,%s,%s,%s)',
				'product',
				$shared_object_id ?? ( self::SEEDED_OBJECT_ID_BASE + $i ),
				$deleted ? 1 : 0,
				'',
				$created,
				'test',
				$created
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every value tuple is prepared above.
		$wpdb->query(
			'INSERT INTO ' . $this->journal->table_name()
			. ' (object_type, object_id, deleted, revision, modified_gmt, origin, created_gmt) VALUES '
			. implode( ',', $values )
		);
	}

	/**
	 * Count the rows currently in the log, optionally of one deleted flag.
	 *
	 * @param bool|null $deleted Deleted flag to count, or null for all rows.
	 */
	private function count_rows( ?bool $deleted = null ): int {
		global $wpdb;

		if ( null === $deleted ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table name.
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->journal->table_name() );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->journal->table_name() . ' WHERE deleted = %d',
				$deleted ? 1 : 0
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
			$this->journal->table_name(),
			array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ),
			array( 'sequence' => $sequence )
		);
	}

	/**
	 * A full run compacts old superseded rows but keeps recent history intact.
	 */
	public function test_purge_expired_with_aged_superseded_row_compacts_it_and_keeps_recent_rows(): void {
		// Arrange.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$aged_superseded = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$recent_superseded = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$latest = $this->journal->head_sequence();
		$this->age_row( $aged_superseded, 2 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();
		$sequences = array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert: only the aged superseded row is outside the 24h window.
		$this->assertEquals( array( $recent_superseded, $latest ), $sequences );
	}

	/**
	 * Tombstones older than the retention window are pruned by a run.
	 */
	public function test_purge_expired_with_aged_tombstone_prunes_it_and_advances_watermark(): void {
		global $wpdb;

		// Arrange.
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$recent_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 33, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 91 );
		$block_watermark = static function ( string $query ): string {
			if ( false !== strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, Sync_Journal::PRUNE_WATERMARK_OPTION_PREFIX ) ) {
				return 'SELECT * FROM wcpos_missing_watermark_table';
			}

			return $query;
		};
		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $block_watermark );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();
		$after_failed_watermark = array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' );
		remove_filter( 'query', $block_watermark );
		$wpdb->suppress_errors( $previous_suppress_errors );
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();
		$sequences = array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert: deletion waits for a persisted horizon, then advertises the pruned interval.
		$this->assertContains( $aged_tombstone, $after_failed_watermark );
		$this->assertEquals( array( $recent_tombstone, $this->journal->head_sequence() ), $sequences );
		$this->assertEquals( $aged_tombstone, $this->journal->prune_watermark() );
	}

	public function test_purge_expired_keeps_an_aged_order_tombstone_that_is_the_order_lane_head(): void {
		// The newest ORDER row is an expired tombstone while a newer catalogue
		// row holds the global head above it. Pruning it would regress the
		// order-lane head below live client checkpoints (whose cursor-past-head
		// guard then forces a full resync) — the per-stream clamp keeps it.
		$this->journal->record( 'order', 101, true, 'deleted', 'hook:delete', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 102, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 91 );

		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		$this->assertCount( 1, $this->journal->rows_after_sequence( 0, 10 ) );
		$this->assertSame( $aged_tombstone, $this->journal->head_sequence( array( 'order' ) ) );
		$this->assertSame( 0, $this->journal->prune_watermark() );
	}

	/**
	 * A narrowed stream's head is protected by its OWN type, not the catalogue's.
	 *
	 * `/changes/sequence-log?collection=tax_rates` is independently readable, so
	 * a tax-rate tombstone that is the newest tax_rate row must survive even when
	 * a newer product row holds the catalogue head. Pruning it would serve that
	 * stream a head below its own horizon, and its clients would rebaseline on
	 * every poll (free#1560 review round 2).
	 */
	public function test_purge_expired_keeps_an_aged_tombstone_that_is_its_own_types_head(): void {
		// Arrange: the tax_rates stream's newest row is an expired tombstone,
		// while a newer product row holds the catalogue head above it.
		$this->journal->record( 'tax_rate', 7, true, '', 'test', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert: the tax_rates stream keeps a head, and never one below its horizon.
		$this->assertSame( $aged_tombstone, $this->journal->head_sequence( array( 'tax_rate' ) ) );
		$this->assertSame( 0, $this->journal->prune_watermark( array( 'tax_rate' ) ) );
	}

	/**
	 * A quiet catalogue must not block order-tombstone pruning.
	 *
	 * The cutoff is clamped per stream, not by the lowest head in the journal:
	 * with a static catalogue every order tombstone sits above the catalogue
	 * head, and one shared clamp would leave the order lane growing forever —
	 * the unbounded-growth gap the unified journal closes (free#1560).
	 */
	public function test_purge_expired_prunes_order_tombstones_while_the_catalogue_stream_is_quiet(): void {
		// Arrange: the catalogue's newest row predates every order row.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'order', 101, true, 'deleted', 'hook:delete', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'order', 102, false, 'rev', 'hook:update', false );
		$order_head = $this->journal->head_sequence();
		$this->age_row( $aged_tombstone, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert: the tombstone is gone and the order head is intact.
		$order_rows = $this->journal->rows_after_sequence( 0, 10 );
		$this->assertCount( 1, $order_rows );
		$this->assertSame( 102, $order_rows[0]['order_id'] );
		$this->assertSame( $order_head, $this->journal->head_sequence( array( 'order' ) ) );
	}

	/**
	 * An order prune leaves the CATALOGUE stream's horizon where it was.
	 *
	 * The streams share one AUTO_INCREMENT space, so an order tombstone's
	 * sequence routinely sits far above a quiet catalogue stream's head. A
	 * shared watermark would put every catalogue cursor below the horizon and
	 * rebaseline it on every poll, forever (free#1560 review, blocker B4).
	 */
	public function test_purge_expired_with_pruned_order_tombstone_keeps_catalogue_horizon_unmoved(): void {
		// Arrange: a quiet catalogue whose head sits below a busy order lane.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$catalogue_head = $this->journal->head_sequence( Sync_Journal::catalogue_object_types() );
		$this->journal->record( 'order', 101, true, 'deleted', 'hook:delete', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'order', 102, false, 'rev', 'hook:update', false );
		$this->age_row( $aged_tombstone, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert: the order lane learns of the lossy prune; the catalogue does not.
		$this->assertSame( $aged_tombstone, $this->journal->prune_watermark( array( 'order' ) ) );
		$this->assertSame( 0, $this->journal->prune_watermark( Sync_Journal::catalogue_object_types() ) );
		$this->assertGreaterThan( $catalogue_head, $aged_tombstone );
	}

	/**
	 * The mirror: a catalogue prune leaves the ORDER lane's horizon unmoved.
	 */
	public function test_purge_expired_with_pruned_catalogue_tombstone_keeps_order_horizon_unmoved(): void {
		// Arrange: an order lane sitting below a busier catalogue.
		$this->journal->record( 'order', 101, false, 'rev', 'hook:update', false );
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert.
		$this->assertSame( $aged_tombstone, $this->journal->prune_watermark( Sync_Journal::catalogue_object_types() ) );
		$this->assertSame( 0, $this->journal->prune_watermark( array( 'order' ) ) );
	}

	/**
	 * A prune advances only the pruned types, even inside one batch.
	 */
	public function test_prune_tombstones_advances_each_object_type_to_its_own_highest_row(): void {
		// Arrange: interleaved tombstones of two types in one prunable batch.
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$product_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'order', 101, true, 'deleted', 'hook:delete', false );
		$order_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$this->journal->record( 'order', 102, false, 'rev', 'hook:update', false );
		$this->age_row( $product_tombstone, 91 );
		$this->age_row( $order_tombstone, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert: each type carries its OWN boundary, not the batch maximum.
		$this->assertSame( $product_tombstone, $this->journal->prune_watermark( array( 'product' ) ) );
		$this->assertSame( $order_tombstone, $this->journal->prune_watermark( array( 'order' ) ) );
		$this->assertSame( 0, $this->journal->prune_watermark( array( 'coupon' ) ) );
	}

	/**
	 * An aged order tombstone BELOW a newer order row prunes normally and
	 * advances the shared watermark — the clamp protects heads, not history.
	 */
	public function test_purge_expired_prunes_an_aged_order_tombstone_below_a_newer_order_row(): void {
		$this->journal->record( 'order', 101, true, 'deleted', 'hook:delete', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'order', 102, false, 'rev', 'hook:update', false );
		$this->journal->record( 'product', 103, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 91 );

		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		$order_rows = $this->journal->rows_after_sequence( 0, 10 );
		$this->assertCount( 1, $order_rows );
		$this->assertSame( 102, $order_rows[0]['order_id'] );
		$this->assertSame( $aged_tombstone, $this->journal->prune_watermark() );
	}

	/**
	 * The mirror case: an aged catalogue tombstone that is the catalogue
	 * stream's head survives even when a newer ORDER row raises the global head.
	 */
	public function test_purge_expired_keeps_an_aged_catalogue_tombstone_that_is_the_catalogue_head(): void {
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'order', 12, false, 'rev', 'hook:update', false );
		$this->age_row( $aged_tombstone, 91 );

		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		$this->assertSame( $aged_tombstone, $this->journal->head_sequence( Sync_Journal::catalogue_object_types() ) );
		$this->assertSame( 0, $this->journal->prune_watermark() );
	}

	/**
	 * The newest row survives pruning even when it is an expired tombstone.
	 */
	public function test_purge_expired_with_aged_tombstone_at_head_keeps_head_row(): void {
		// Arrange: an idle store whose last event was a deletion long ago.
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$aged_below_head = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$head = $this->journal->head_sequence();
		$this->age_row( $aged_below_head, 91 );
		$this->age_row( $head, 91 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert: rows below head prune; head itself never regresses.
		$this->assertEquals( $head, $this->journal->head_sequence() );
		$this->assertEquals( $aged_below_head, $this->journal->prune_watermark() );
	}

	/**
	 * Non-positive tombstone retention keeps every tombstone forever.
	 */
	public function test_purge_expired_with_zero_retention_filter_keeps_aged_tombstones(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_change_log_tombstone_retention_days', '__return_zero' );
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$aged_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$this->age_row( $aged_tombstone, 400 );

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();

		// Assert.
		$this->assertEquals( $aged_tombstone, $this->journal->oldest_sequence() );
		$this->assertEquals( 0, $this->journal->prune_watermark() );
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
		$this->seed_aged_rows( false, Sync_Journal_Purge::MAX_DELETES_PER_RUN + 2, 2, 11 );
		$this->seed_aged_rows( true, 3, 91 );
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$before = $this->count_rows();

		// Act.
		( new Sync_Journal_Purge( $this->journal ) )->purge_expired();
		$deleted = $before - $this->count_rows();

		// Assert: pruning got its slice, the ceiling held, and the backlog reschedules.
		$this->assertEquals( 0, $this->count_rows( true ) );
		$this->assertGreaterThan( 0, $this->journal->prune_watermark() );
		$this->assertLessThanOrEqual( Sync_Journal_Purge::MAX_DELETES_PER_RUN, $deleted );
		$this->assertNotEquals( false, wp_next_scheduled( Sync_Journal_Purge::PURGE_HOOK ) );
	}

	/**
	 * Settled mutation expiry cannot consume the slice reserved for failures.
	 */
	public function test_purge_expired_reserves_a_slice_for_opted_in_failure_retention(): void {
		add_filter(
			'woocommerce_pos_sync_mutation_failure_retention_days',
			static function (): int {
				return 30;
			}
		);
		$failure_limit = 0;
		$store         = $this->getMockBuilder( Mutation_Store::class )
			->onlyMethods( array( 'prune_settled', 'prune_failed' ) )
			->getMock();
		$store->method( 'prune_settled' )->willReturnCallback(
			static function ( string $settled_gmt, string $create_gmt, int $limit ): int {
				return $limit;
			}
		);
		$store->expects( $this->atLeastOnce() )->method( 'prune_failed' )->willReturnCallback(
			static function ( string $cutoff_gmt, int $limit ) use ( &$failure_limit ): int {
				$failure_limit = $limit;
				return 0;
			}
		);

		( new Sync_Journal_Purge( $this->journal, $store ) )->purge_expired();

		$this->assertSame( Sync_Journal_Purge::MIN_MUTATION_DELETES_PER_RUN, $failure_limit );
	}

	/**
	 * Registering hooks schedules the daily cron exactly once.
	 */
	public function test_register_hooks_without_existing_schedule_registers_daily_purge_event(): void {
		// Arrange.
		$service = new Sync_Journal_Purge( $this->journal );

		// Act: construction alone must not touch cron state.
		$before_registration = wp_next_scheduled( Sync_Journal_Purge::PURGE_HOOK );
		$service->register_hooks();
		$first_run = wp_next_scheduled( Sync_Journal_Purge::PURGE_HOOK );
		( new Sync_Journal_Purge( $this->journal ) )->register_hooks();

		// Assert: second registration reuses the existing schedule.
		$this->assertEquals( false, $before_registration );
		$this->assertNotEquals( false, $first_run );
		$this->assertEquals( $first_run, wp_next_scheduled( Sync_Journal_Purge::PURGE_HOOK ) );
	}
}
