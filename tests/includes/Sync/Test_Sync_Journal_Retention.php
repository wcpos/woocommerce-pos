<?php
/**
 * Tests for unified-journal compaction and tombstone retention.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * Retention removes only rows that are safe or explicitly expired.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Sync_Journal_Retention extends Sync_Store_Test_Case {
	/**
	 * The log under test.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * Start each test against an empty stream and a clean watermark.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		delete_option( Sync_Journal::PRUNE_WATERMARK_OPTION );
	}

	/**
	 * Remove the persisted watermark between tests.
	 */
	public function tearDown(): void {
		delete_option( Sync_Journal::PRUNE_WATERMARK_OPTION );
		parent::tearDown();
	}

	/**
	 * A wall-clock cutoff safely in the future, so age never excludes a row.
	 */
	private function future_gmt(): string {
		return gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );
	}

	/**
	 * Compaction removes only superseded rows inside its sequence window.
	 */
	public function test_compact_with_cutoff_deletes_only_superseded_rows_at_or_below_cutoff(): void {
		// Arrange.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$inside_cutoff = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$above_cutoff = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$latest = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$only = $this->journal->head_sequence();

		// Act.
		$deleted   = $this->journal->compact( $inside_cutoff, $this->future_gmt(), 500 );
		$sequences = array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert.
		$this->assertEquals( 1, $deleted );
		$this->assertEquals( array( $above_cutoff, $latest, $only ), $sequences );
	}

	/**
	 * Compaction checks each row's wall clock when sequence and time order differ.
	 */
	public function test_compact_with_recent_lower_sequence_keeps_it_when_higher_sequence_is_old(): void {
		global $wpdb;

		// Arrange: a recent superseded row sorts below an unrelated old row.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$recent = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$old = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$wpdb->update(
			$this->journal->table_name(),
			array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ),
			array( 'sequence' => $old )
		);

		// Act.
		$deleted = $this->journal->compact( $this->journal->sequence_at_or_before( $cutoff_gmt ), $cutoff_gmt, 500 );

		// Assert.
		$this->assertEquals( 0, $deleted );
		$this->assertContains( $recent, array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' ) );
	}

	/**
	 * Latest tombstones survive compaction, while pruning targets only tombstones.
	 */
	public function test_tombstone_retention_with_cutoffs_preserves_compaction_tombstones_and_prunes_only_deletes(): void {
		// Arrange.
		$this->journal->record( 'product', 44, false, '', 'test', false );
		$retained_update = $this->journal->head_sequence();
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$first_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$later_tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 33, false, '', 'test', false );
		$update = $this->journal->head_sequence();

		// Act.
		$compacted = $this->journal->compact( $update, $this->future_gmt(), 500 );
		$after_compaction = $this->journal->page( array(), 0, 20 )['rows'];
		$pruned = $this->journal->prune_tombstones( $first_tombstone, $this->future_gmt(), 500 );
		$after_pruning = $this->journal->page( array(), 0, 20 )['rows'];

		// Assert.
		$this->assertEquals( 1, $compacted );
		$this->assertEquals(
			array( $first_tombstone, $later_tombstone ),
			array_column(
				array_filter(
					$after_compaction,
					static function ( array $row ): bool {
						return 1 === $row['deleted'];
					}
				),
				'sequence'
			)
		);
		$this->assertEquals( 1, $pruned['deleted'] );
		$this->assertEquals( $first_tombstone, $pruned['watermark'] );
		$this->assertEquals( array( $retained_update, $later_tombstone, $update ), array_column( $after_pruning, 'sequence' ) );
	}

	/**
	 * Retention does not move the head when a later retained row exists.
	 */
	public function test_retention_with_retained_head_keeps_head_sequence_unchanged(): void {
		// Arrange.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 33, false, '', 'test', false );
		$head = $this->journal->head_sequence();

		// Act.
		$this->journal->compact( $head, $this->future_gmt(), 500 );
		$after_compaction = $this->journal->head_sequence();
		$this->journal->prune_tombstones( $tombstone, $this->future_gmt(), 500 );
		$after_pruning = $this->journal->head_sequence();

		// Assert.
		$this->assertEquals( $head, $after_compaction );
		$this->assertEquals( $head, $after_pruning );
	}

	/**
	 * The retention horizon tracks the first row that still exists.
	 */
	public function test_oldest_sequence_on_empty_fresh_and_pruned_logs_returns_first_surviving_sequence(): void {
		// Arrange.
		$empty = $this->journal->oldest_sequence();
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$first = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$second = $this->journal->head_sequence();

		// Act.
		$fresh = $this->journal->oldest_sequence();
		$this->journal->prune_tombstones( $first, $this->future_gmt(), 500 );
		$pruned = $this->journal->oldest_sequence();

		// Assert.
		$this->assertEquals( 0, $empty );
		$this->assertEquals( $first, $fresh );
		$this->assertEquals( $second, $pruned );
	}

	/**
	 * One compaction call never deletes more than its requested batch.
	 */
	public function test_compact_with_small_batch_deletes_at_most_batch_and_repeated_calls_finish(): void {
		// Arrange.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$head = $this->journal->head_sequence();

		// Act.
		$first  = $this->journal->compact( $head, $this->future_gmt(), 2 );
		$second = $this->journal->compact( $head, $this->future_gmt(), 2 );
		$third  = $this->journal->compact( $head, $this->future_gmt(), 2 );

		// Assert.
		$this->assertEquals( 2, $first );
		$this->assertEquals( 1, $second );
		$this->assertEquals( 0, $third );
		$this->assertEquals( array( $head ), array_column( $this->journal->page( array(), 0, 20 )['rows'], 'sequence' ) );
	}

	/**
	 * Pruning is gated on each row's own wall clock, not sequence order alone.
	 */
	public function test_prune_tombstones_with_row_inside_wall_clock_window_keeps_it(): void {
		// Arrange: a tombstone below the sequence cutoff but created "now".
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );

		// Act: sequence cutoff includes the tombstone, wall clock excludes it.
		$result = $this->journal->prune_tombstones( $tombstone, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 500 );

		// Assert.
		$this->assertEquals( 0, $result['deleted'] );
		$this->assertEquals( $tombstone, $this->journal->oldest_sequence() );
	}

	/**
	 * The watermark persists the highest pruned sequence and never regresses.
	 */
	public function test_advance_prune_watermark_with_lower_value_keeps_highest(): void {
		// Arrange.
		$this->assertEquals( 0, $this->journal->prune_watermark() );

		// Act.
		$this->journal->advance_prune_watermark( 40 );
		$this->journal->advance_prune_watermark( 25 );

		// Assert.
		$this->assertEquals( 40, $this->journal->prune_watermark() );
	}

	/**
	 * A worker with a stale option cache cannot overwrite a newer watermark.
	 */
	public function test_advance_prune_watermark_with_stale_cache_keeps_concurrent_higher_value(): void {
		global $wpdb;

		// Arrange: this worker cached 10 while another worker persisted 50.
		update_option( Sync_Journal::PRUNE_WATERMARK_OPTION, 10, true );
		$this->assertEquals( 10, $this->journal->prune_watermark() );
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => 50 ),
			array( 'option_name' => Sync_Journal::PRUNE_WATERMARK_OPTION )
		);

		// Act.
		$this->journal->advance_prune_watermark( 40 );

		// Assert.
		$this->assertEquals( 50, $this->journal->prune_watermark() );
	}

	/**
	 * A prune batch cannot lower a watermark another worker raised mid-batch.
	 *
	 * The batch's own watermark is computed in PHP from the rows it selected, so
	 * two overlapping purge workers can hold different candidates at once. The
	 * persisted horizon is the client-facing completeness boundary: moving it
	 * backwards would tell a client it had missed nothing when it had.
	 */
	public function test_prune_tombstones_with_concurrent_higher_watermark_keeps_higher_value(): void {
		global $wpdb;

		// Arrange: two prunable tombstones, plus a retained row above them.
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$second = $this->journal->head_sequence();
		$this->journal->record( 'product', 33, false, '', 'test', false );
		$concurrent = $second + 1000;
		update_option( Sync_Journal::PRUNE_WATERMARK_OPTION, 1, true );

		// A second worker persists a higher watermark between our select and our
		// write; its write lands in the table, not in this process's option cache.
		$fired      = false;
		$interleave = static function ( string $query ) use ( $wpdb, $concurrent, &$fired ): string {
			if ( ! $fired && false !== strpos( $query, 'deleted = 1' ) ) {
				$fired = true;
				$wpdb->update(
					$wpdb->options,
					array( 'option_value' => $concurrent ),
					array( 'option_name' => Sync_Journal::PRUNE_WATERMARK_OPTION )
				);
			}

			return $query;
		};

		// Act.
		add_filter( 'query', $interleave );
		$result = $this->journal->prune_tombstones( $second, $this->future_gmt(), 500 );
		remove_filter( 'query', $interleave );
		$persisted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				Sync_Journal::PRUNE_WATERMARK_OPTION
			)
		);

		// Assert: the batch still prunes, and the persisted horizon never regresses.
		$this->assertEquals( true, $fired );
		$this->assertEquals( 2, $result['deleted'] );
		$this->assertEquals( $concurrent, $persisted );
		$this->assertEquals( $concurrent, $this->journal->prune_watermark() );
	}

	/**
	 * A capped prune batch reports the highest sequence it actually removed.
	 */
	public function test_prune_tombstones_with_small_batch_reports_partial_watermark(): void {
		// Arrange.
		$this->journal->record( 'product', 11, true, '', 'test', false );
		$first = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, true, '', 'test', false );
		$second = $this->journal->head_sequence();
		$this->journal->record( 'product', 33, true, '', 'test', false );

		// Act.
		$result = $this->journal->prune_tombstones( $second, $this->future_gmt(), 1 );

		// Assert: lowest sequence pruned first, watermark tracks it exactly.
		$this->assertEquals( 1, $result['deleted'] );
		$this->assertEquals( $first, $result['watermark'] );
	}

	/**
	 * A cursor before a removed row can still observe that object's later row.
	 */
	public function test_page_with_cursor_before_compacted_row_returns_surviving_later_object_change(): void {
		// Arrange.
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$compacted = $this->journal->head_sequence();
		$this->journal->record( 'product', 22, false, '', 'test', false );
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$survivor = $this->journal->head_sequence();

		// Act.
		$this->journal->compact( $compacted, $this->future_gmt(), 500 );
		$rows = $this->journal->page( array(), $compacted - 1, 20 )['rows'];

		// Assert.
		$this->assertEquals( array( 22, 11 ), array_column( $rows, 'object_id' ) );
		$this->assertEquals( $survivor, $rows[1]['sequence'] );
	}

	public function test_order_tombstone_pruning_advances_the_shared_watermark(): void {
		$this->journal->record( 'order', 71, true, 'deleted', 'hook:delete', false );
		$tombstone = $this->journal->head_sequence();
		$this->journal->record( 'product', 72, false, '', 'test', false );

		$result = $this->journal->prune_tombstones( $tombstone, $this->future_gmt(), 10 );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertSame( $tombstone, $result['watermark'] );
		$this->assertSame( $tombstone, $this->journal->prune_watermark() );
	}

	public function test_compaction_keeps_the_newest_order_row(): void {
		$this->journal->record( 'order', 81, false, 'sha256:old', 'hook:update', false );
		$old = $this->journal->head_sequence();
		$this->journal->record( 'order', 81, false, 'sha256:new', 'hook:update', false );
		$new = $this->journal->head_sequence();

		$this->assertSame( 1, $this->journal->compact( $new, $this->future_gmt(), 10 ) );
		$rows = $this->journal->rows_after_sequence( 0, 10 );
		$this->assertSame( array( $new ), array_column( $rows, 'sequence' ) );
		$this->assertNotContains( $old, array_column( $rows, 'sequence' ) );
	}

	public function test_order_cursor_between_compacted_sequences_sees_surviving_row(): void {
		$this->journal->record( 'order', 91, false, 'sha256:old', 'hook:update', false );
		$compacted = $this->journal->head_sequence();
		$this->journal->record( 'product', 92, false, '', 'test', false );
		$this->journal->record( 'order', 91, false, 'sha256:new', 'hook:update', false );
		$survivor = $this->journal->head_sequence();

		$this->journal->compact( $compacted, $this->future_gmt(), 10 );
		$rows = $this->journal->rows_after_sequence( $compacted - 1, 10 );

		$this->assertSame( array( 91 ), array_column( $rows, 'order_id' ) );
		$this->assertSame( $survivor, $rows[0]['sequence'] );
	}
}
