<?php
/**
 * The journal writes ONE `hook:update` row per order per request.
 *
 * Measured 2026-09-03 on dev-next (see
 * .claude/research/2026-09-03-online-store-footprint.md): a single Store API
 * checkout fires `woocommerce_update_order` eleven times while WooCommerce
 * builds the order, and the journal appended a row (~3 ms fsync each, plus a
 * three-query `wc_get_order()` refetch) on every one — 12 rows and ~66 ms for
 * ONE online order. These tests pin the coalesced contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Sync_Journal_Order_Write_Coalescing extends Sync_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/** @var Sync_Journal */
	private $journal;

	public function setUp(): void {
		parent::setUp();
		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->register_hooks();
	}

	public function tearDown(): void {
		// Never let a pending row or the shutdown flag from one test leak into the next.
		Sync_Journal::reset_request_state();
		$this->remove_observer_callbacks( array( $this->journal ) );
		parent::tearDown();
	}

	public function test_repeated_saves_in_one_request_append_one_update_row_at_flush(): void {
		// Arrange: the create row stays immediate.
		$cursor   = $this->journal->head_sequence();
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertSame( array( 'hook:create' ), $this->origins_for( $order_id, $cursor ) );

		// Act: the eleven-save checkout shape, collapsed to five saves.
		$cursor = $this->journal->head_sequence();
		for ( $i = 1; $i <= 5; $i++ ) {
			$order->set_customer_note( "save {$i}" );
			$order->save();
		}

		// Assert: nothing lands until the request boundary...
		$this->assertSame( array(), $this->origins_for( $order_id, $cursor ), 'Update rows must be deferred to the flush, not appended per save.' );

		// ...then exactly one row, read from the settled order.
		$this->journal->flush_pending_order_updates();
		$rows = $this->rows_for( $order_id, $cursor );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'hook:update', $rows[0]['origin'] );
		$this->assertSame( 0, $rows[0]['deleted'] );
		$this->assertSame( '', $rows[0]['revision'] );
		$settled = wc_get_order( $order_id )->get_date_modified();
		$this->assertNotNull( $settled );
		$this->assertEqualsWithDelta( $settled->getTimestamp(), strtotime( $rows[0]['modified_gmt'] . ' UTC' ), 5 );

		// A second flush is a no-op.
		$this->journal->flush_pending_order_updates();
		$this->assertCount( 1, $this->rows_for( $order_id, $cursor ) );
	}

	public function test_pending_update_lands_before_a_delete_row_for_the_same_order(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$cursor   = $this->journal->head_sequence();

		$order->set_customer_note( 'edited then trashed' );
		$order->save();
		$order->delete( false );

		// The stream must never show delete-then-update: a client replaying it
		// would resurrect a trashed order. (A CPT trash appends several
		// `hook:delete` rows — one per WP/WC trash hook — which is out of scope
		// here; what is pinned is that the single update row precedes them all.)
		$origins = $this->origins_for( $order_id, $cursor );
		$this->assertSame( 'hook:update', $origins[0] ?? null, 'The owed update row must land before the delete row.' );
		$this->assertCount( 1, array_keys( $origins, 'hook:update', true ), 'Exactly one update row for the whole request.' );
		$this->assertContains( 'hook:delete', $origins );
		$this->assertSame( array( 'hook:delete' ), array_values( array_unique( array_slice( $origins, 1 ) ) ), 'No update row may follow the delete row.' );
	}

	public function test_the_ordering_guarantee_holds_across_journal_instances(): void {
		// The hook-registered instance owes the update; a DIFFERENT instance
		// (the backfill, an invalidation handler) writes the other-origin row.
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$cursor   = $this->journal->head_sequence();

		$order->set_customer_note( 'edited' );
		$order->save();
		( new Sync_Journal() )->record_order_change( $order_id, 'invalidate', false );

		$this->assertSame( array( 'hook:update', 'invalidate' ), $this->origins_for( $order_id, $cursor ) );
	}

	public function test_a_create_row_drops_the_owed_update_row_for_the_same_order(): void {
		// The Store API saves a checkout-draft several times before
		// `woocommerce_new_order` fires. Both rows point at the same live
		// record, so the create row makes the pending update redundant.
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$cursor   = $this->journal->head_sequence();

		$this->journal->record_order_updated( $order_id, $order );
		$this->journal->record_order_updated( $order_id, $order );
		$this->journal->record_order_created( $order_id );
		$this->journal->flush_pending_order_updates();

		$this->assertSame( array( 'hook:create' ), $this->origins_for( $order_id, $cursor ) );
	}

	public function test_a_save_of_a_different_order_flushes_the_pending_one(): void {
		$first  = wc_create_order();
		$second = wc_create_order();
		$cursor = $this->journal->head_sequence();

		$first->set_customer_note( 'first' );
		$first->save();
		$this->assertSame( array(), $this->origins_for( $first->get_id(), $cursor ) );

		// A bulk loop (WP-CLI import, Action Scheduler runner) moves on to the
		// next order: the previous order's row must land now, not at process end.
		$second->set_customer_note( 'second' );
		$second->save();
		$this->assertSame( array( 'hook:update' ), $this->origins_for( $first->get_id(), $cursor ) );
		$this->assertSame( array(), $this->origins_for( $second->get_id(), $cursor ) );

		$this->journal->flush_pending_order_updates();
		$this->assertSame( array( 'hook:update' ), $this->origins_for( $second->get_id(), $cursor ) );
	}

	public function test_an_update_after_the_shutdown_flush_is_written_immediately(): void {
		// WooCommerce saves the customer on `shutdown` at priority 10; a save
		// that fires after our flush has run must not be lost.
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->journal->flush_pending_order_updates_at_shutdown();

		$cursor = $this->journal->head_sequence();
		$order->set_customer_note( 'late save' );
		$order->save();

		$this->assertSame( array( 'hook:update' ), $this->origins_for( $order_id, $cursor ), 'No flush follows the shutdown flush, so the row must land at once.' );
	}

	public function test_recording_an_update_with_the_hook_order_object_runs_no_queries(): void {
		global $wpdb;
		$order = wc_create_order();
		$this->journal->flush_pending_order_updates();

		$before = $wpdb->num_queries;
		$this->journal->record_order_updated( $order->get_id(), $order );
		$this->journal->record_order_updated( $order->get_id(), $order );
		$this->assertSame( $before, $wpdb->num_queries, 'Marking an order dirty must not touch the database.' );

		$cursor = $this->journal->head_sequence();
		$this->journal->flush_pending_order_updates();
		$this->assertSame( array( 'hook:update' ), $this->origins_for( $order->get_id(), $cursor ) );
	}

	public function test_recording_an_update_without_the_order_object_still_writes_one_row(): void {
		$order  = wc_create_order();
		$cursor = $this->journal->head_sequence();

		$this->journal->record_order_updated( $order->get_id() );
		$this->journal->flush_pending_order_updates();

		$this->assertSame( array( 'hook:update' ), $this->origins_for( $order->get_id(), $cursor ) );
	}

	public function test_register_hooks_flushes_last_on_shutdown_and_accepts_the_order_argument(): void {
		// LAST: WooCommerce saves the customer at 10 and the session at 20.
		$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( $this->journal, 'flush_pending_order_updates_at_shutdown' ) ) );

		global $wp_filter;
		$hook = $wp_filter['woocommerce_update_order'];
		$id   = _wp_filter_build_unique_id( 'woocommerce_update_order', array( $this->journal, 'record_order_updated' ), 10 );
		$this->assertArrayHasKey( $id, $hook->callbacks[10] );
		$this->assertSame( 2, $hook->callbacks[10][ $id ]['accepted_args'], 'The hook passes ($order_id, $order); the object must reach the observer so the flush never refetches.' );
	}

	private function origins_for( int $order_id, int $since ): array {
		return array_map(
			static function ( array $row ): string {
				return $row['origin'];
			},
			$this->rows_for( $order_id, $since )
		);
	}

	private function rows_for( int $order_id, int $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->journal->table_name() . ' WHERE object_type = %s AND object_id = %d AND sequence > %d ORDER BY sequence ASC LIMIT 250', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
				'order',
				$order_id,
				$since
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['deleted'] = (int) $row['deleted'];
				return $row;
			},
			\is_array( $rows ) ? $rows : array()
		);
	}
}
