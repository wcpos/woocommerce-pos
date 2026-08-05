<?php
/**
 * Tests for change-log compaction and tombstone retention.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Change_Log;

/**
 * Retention removes only rows that are safe or explicitly expired.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Change_Log
 */
class Test_Change_Log_Retention extends Sync_Store_Test_Case {
	/**
	 * The log under test.
	 *
	 * @var Change_Log
	 */
	private $log;

	/**
	 * Start each test against an empty stream and a clean watermark.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->log = new Change_Log();
		delete_option( Change_Log::PRUNE_WATERMARK_OPTION );
	}

	/**
	 * Remove the persisted watermark between tests.
	 */
	public function tearDown(): void {
		delete_option( Change_Log::PRUNE_WATERMARK_OPTION );
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
		$this->log->record( 'product', 11, 'create', 'test', false );
		$inside_cutoff = $this->log->head_sequence();
		$this->log->record( 'product', 11, 'update', 'test', false );
		$above_cutoff = $this->log->head_sequence();
		$this->log->record( 'product', 11, 'update', 'test', false );
		$latest = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'create', 'test', false );
		$only = $this->log->head_sequence();

		// Act.
		$deleted   = $this->log->compact( $inside_cutoff, 500 );
		$sequences = array_column( $this->log->page( array(), 0, 20 )['rows'], 'sequence' );

		// Assert.
		$this->assertEquals( 1, $deleted );
		$this->assertEquals( array( $above_cutoff, $latest, $only ), $sequences );
	}

	/**
	 * Latest tombstones survive compaction, while pruning targets only tombstones.
	 */
	public function test_tombstone_retention_with_cutoffs_preserves_compaction_tombstones_and_prunes_only_deletes(): void {
		// Arrange.
		$this->log->record( 'product', 44, 'update', 'test', false );
		$retained_update = $this->log->head_sequence();
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$first_tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'delete', 'test', false );
		$later_tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 33, 'update', 'test', false );
		$update = $this->log->head_sequence();

		// Act.
		$compacted = $this->log->compact( $update, 500 );
		$after_compaction = $this->log->page( array(), 0, 20 )['rows'];
		$pruned = $this->log->prune_tombstones( $first_tombstone, $this->future_gmt(), 500 );
		$after_pruning = $this->log->page( array(), 0, 20 )['rows'];

		// Assert.
		$this->assertEquals( 1, $compacted );
		$this->assertEquals(
			array( $first_tombstone, $later_tombstone ),
			array_column(
				array_filter(
					$after_compaction,
					static function ( array $row ): bool {
						return 'delete' === $row['change_type'];
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
		$this->log->record( 'product', 11, 'create', 'test', false );
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'product', 22, 'delete', 'test', false );
		$tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 33, 'update', 'test', false );
		$head = $this->log->head_sequence();

		// Act.
		$this->log->compact( $head, 500 );
		$after_compaction = $this->log->head_sequence();
		$this->log->prune_tombstones( $tombstone, $this->future_gmt(), 500 );
		$after_pruning = $this->log->head_sequence();

		// Assert.
		$this->assertEquals( $head, $after_compaction );
		$this->assertEquals( $head, $after_pruning );
	}

	/**
	 * The retention horizon tracks the first row that still exists.
	 */
	public function test_oldest_sequence_on_empty_fresh_and_pruned_logs_returns_first_surviving_sequence(): void {
		// Arrange.
		$empty = $this->log->oldest_sequence();
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$first = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'update', 'test', false );
		$second = $this->log->head_sequence();

		// Act.
		$fresh = $this->log->oldest_sequence();
		$this->log->prune_tombstones( $first, $this->future_gmt(), 500 );
		$pruned = $this->log->oldest_sequence();

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
		$this->log->record( 'product', 11, 'create', 'test', false );
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'product', 11, 'update', 'test', false );
		$head = $this->log->head_sequence();

		// Act.
		$first  = $this->log->compact( $head, 2 );
		$second = $this->log->compact( $head, 2 );
		$third  = $this->log->compact( $head, 2 );

		// Assert.
		$this->assertEquals( 2, $first );
		$this->assertEquals( 1, $second );
		$this->assertEquals( 0, $third );
		$this->assertEquals( array( $head ), array_column( $this->log->page( array(), 0, 20 )['rows'], 'sequence' ) );
	}

	/**
	 * Pruning is gated on each row's own wall clock, not sequence order alone.
	 */
	public function test_prune_tombstones_with_row_inside_wall_clock_window_keeps_it(): void {
		// Arrange: a tombstone below the sequence cutoff but created "now".
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$tombstone = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'update', 'test', false );

		// Act: sequence cutoff includes the tombstone, wall clock excludes it.
		$result = $this->log->prune_tombstones( $tombstone, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 500 );

		// Assert.
		$this->assertEquals( 0, $result['deleted'] );
		$this->assertEquals( $tombstone, $this->log->oldest_sequence() );
	}

	/**
	 * The watermark persists the highest pruned sequence and never regresses.
	 */
	public function test_advance_prune_watermark_with_lower_value_keeps_highest(): void {
		// Arrange.
		$this->assertEquals( 0, $this->log->prune_watermark() );

		// Act.
		$this->log->advance_prune_watermark( 40 );
		$this->log->advance_prune_watermark( 25 );

		// Assert.
		$this->assertEquals( 40, $this->log->prune_watermark() );
	}

	/**
	 * A capped prune batch reports the highest sequence it actually removed.
	 */
	public function test_prune_tombstones_with_small_batch_reports_partial_watermark(): void {
		// Arrange.
		$this->log->record( 'product', 11, 'delete', 'test', false );
		$first = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'delete', 'test', false );
		$second = $this->log->head_sequence();
		$this->log->record( 'product', 33, 'delete', 'test', false );

		// Act.
		$result = $this->log->prune_tombstones( $second, $this->future_gmt(), 1 );

		// Assert: lowest sequence pruned first, watermark tracks it exactly.
		$this->assertEquals( 1, $result['deleted'] );
		$this->assertEquals( $first, $result['watermark'] );
	}

	/**
	 * A cursor before a removed row can still observe that object's later row.
	 */
	public function test_page_with_cursor_before_compacted_row_returns_surviving_later_object_change(): void {
		// Arrange.
		$this->log->record( 'product', 11, 'create', 'test', false );
		$compacted = $this->log->head_sequence();
		$this->log->record( 'product', 22, 'update', 'test', false );
		$this->log->record( 'product', 11, 'update', 'test', false );
		$survivor = $this->log->head_sequence();

		// Act.
		$this->log->compact( $compacted, 500 );
		$rows = $this->log->page( array(), $compacted - 1, 20 )['rows'];

		// Assert.
		$this->assertEquals( array( 22, 11 ), array_column( $rows, 'object_id' ) );
		$this->assertEquals( $survivor, $rows[1]['sequence'] );
	}
}
