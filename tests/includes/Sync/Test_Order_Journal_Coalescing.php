<?php
/**
 * Order journal rows are coalesced to one per REST dispatch.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WP_REST_Request;
use WP_REST_Response;

/**
 * One REST dispatch appends one order row, having serialized the order once.
 *
 * WHAT THIS DEFENDS. An order's journal revision is a content hash of its full
 * serialized payload, so `record_order_change()` runs the entire REST read lane
 * to produce one string. A single `POST /wcpos/v1/orders` saves the order four
 * times — `save_object()`, then twice more inside `calculate_totals()` — so the
 * observer fired four times, serialized four times and appended four rows, three
 * of which were superseded before the response was sent. Measured on two HPOS
 * stores: 45-71 ms, 103-139 rows examined and 33-48 queries of pure waste per
 * checkout (#1725's residual).
 *
 * WHY CALL COUNTS ARE THE INSTRUMENT HERE. #1725 itself could not be caught by
 * counting queries — the broken and fixed forms were one query either way, and
 * only rows-examined separated them. This defect is the mirror image: the unit
 * of work is a whole serializer pass, and how many times it runs is exactly the
 * thing that must not regress. So these tests count passes and rows, not time.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Order_Journal_Coalescing extends Sync_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/** REST namespace used by this test's throwaway route. */
	private const TEST_NAMESPACE = 'wcpos-test/v1';

	/** @var Sync_Journal */
	protected $journal;

	/**
	 * Body of the throwaway REST route, set per test.
	 *
	 * Static because the route is registered on `rest_api_init` and its callback
	 * therefore outlives the test instance that registered it — PHPUnit builds a
	 * fresh instance per test method, so a closure capturing `$this` would run
	 * the FIRST test's body (i.e. none) for every later test.
	 *
	 * @var callable|null
	 */
	protected static $route_body;

	/** @var int Serializer passes observed since the last reset. */
	protected $passes = 0;

	public function setUp(): void {
		parent::setUp();

		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->register_hooks();

		$this->passes = 0;
		add_filter( 'woocommerce_pos_sync_serialized_order', array( $this, 'count_pass' ), 10, 1 );

		// A route of our own keeps the assertions on the mechanism under test rather
		// than on WooCommerce's order-controller internals, which move between minor
		// versions. The dispatch path exercised is identical: `rest_do_request()` is
		// what the v2 push uses to forward a mutation to `/wc/v3/orders`.
		//
		// Registered on `rest_api_init` (WordPress emits a `_doing_it_wrong` for any
		// other timing) and the server rebuilt so the action actually fires — it has
		// already run by the time a test body executes.
		self::$route_body = null;
		add_action( 'rest_api_init', array( __CLASS__, 'register_test_route' ) );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_sync_serialized_order', array( $this, 'count_pass' ), 10 );
		remove_action( 'rest_api_init', array( __CLASS__, 'register_test_route' ) );
		self::$route_body          = null;
		$GLOBALS['wp_rest_server'] = null;
		$this->remove_observer_callbacks( array( $this->journal ) );
		parent::tearDown();
	}

	/**
	 * Register the throwaway route that stands in for an order-writing endpoint.
	 */
	public static function register_test_route(): void {
		register_rest_route(
			self::TEST_NAMESPACE,
			'/burst',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( \is_callable( self::$route_body ) ) {
						\call_user_func( self::$route_body, $request );
					}

					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);
	}

	/**
	 * Count one journal serializer pass.
	 *
	 * @param array $payload Serialized order payload, untouched.
	 *
	 * @return array
	 */
	public function count_pass( $payload ) {
		++$this->passes;

		return $payload;
	}

	/**
	 * Journal rows recorded for one order.
	 *
	 * @param int $order_id Order to count rows for.
	 *
	 * @return array<int, object>
	 */
	protected function rows_for( int $order_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT origin, deleted, revision FROM ' . $this->journal->table_name() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
				. ' WHERE object_type = %s AND object_id = %d ORDER BY sequence ASC',
				'order',
				$order_id
			)
		);
	}

	/**
	 * Dispatch the throwaway route with $body as its handler.
	 *
	 * @param callable $body Work to run inside the dispatch.
	 */
	protected function dispatch( callable $body ): void {
		self::$route_body = $body;
		rest_do_request( new WP_REST_Request( 'POST', '/' . self::TEST_NAMESPACE . '/burst' ) );
	}

	/**
	 * An order saved repeatedly inside one dispatch yields one row, serialized once.
	 *
	 * Four saves is the count a real `POST /wcpos/v1/orders` performs.
	 */
	public function test_repeated_saves_in_one_dispatch_append_one_row_serialized_once(): void {
		$order_id = 0;

		$this->dispatch(
			function () use ( &$order_id ): void {
				$order    = wc_create_order();
				$order_id = $order->get_id();
				for ( $i = 0; $i < 3; $i++ ) {
					$order->set_customer_note( 'burst ' . $i );
					$order->save();
				}
			}
		);

		$rows = $this->rows_for( $order_id );
		$this->assertCount( 1, $rows, 'four order saves in one dispatch must append exactly one journal row' );
		$this->assertSame( 1, $this->passes, 'the order must be serialized once per dispatch, not once per save' );
	}

	/** The surviving row keeps create semantics and carries the order's final revision. */
	public function test_the_single_row_is_a_create_carrying_the_settled_revision(): void {
		$order_id = 0;

		$this->dispatch(
			function () use ( &$order_id ): void {
				$order    = wc_create_order();
				$order_id = $order->get_id();
				$order->set_customer_note( 'settled' );
				$order->save();
			}
		);

		$rows = $this->rows_for( $order_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'hook:create', $rows[0]->origin, 'a create followed by an update in one request is a create' );
		$this->assertSame( '0', (string) $rows[0]->deleted );

		// The revision must be the one a reader computing it now would get — i.e. the
		// LAST pass's, not an intermediate one frozen before the customer note landed.
		$this->journal->record_order_change( $order_id, 'test:settled', false );
		$after = $this->rows_for( $order_id );
		$this->assertSame( $after[1]->revision, $rows[0]->revision, 'the coalesced row must carry the settled revision' );
	}

	/** Outside a REST dispatch nothing changes: every save writes its own row at once. */
	public function test_a_save_outside_rest_still_writes_its_row_immediately(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertCount( 1, $this->rows_for( $order_id ), 'a non-REST create must be journalled immediately' );

		$order->set_customer_note( 'direct' );
		$order->save();
		$this->assertCount( 2, $this->rows_for( $order_id ), 'a non-REST update must be journalled immediately' );
	}

	/**
	 * A delete inside a dispatch supersedes the buffered row — no resurrection.
	 *
	 * The row COUNT is deliberately not asserted: a force delete fires both
	 * `woocommerce_before_trash_order` and `woocommerce_before_delete_order`, so
	 * the journal has always written two tombstones here. Two tombstones are
	 * idempotent and predate this buffering. What must hold is that no PRESENT
	 * row survives — a buffered create flushed after the tombstone would tell
	 * every client to re-add an order that no longer exists.
	 */
	public function test_a_delete_during_a_dispatch_supersedes_the_buffered_row(): void {
		$order_id = 0;

		$this->dispatch(
			function () use ( &$order_id ): void {
				$order    = wc_create_order();
				$order_id = $order->get_id();
				$order->delete( true );
			}
		);

		$rows    = $this->rows_for( $order_id );
		$present = array_values(
			array_filter(
				$rows,
				static function ( $row ): bool {
					return ! (int) $row->deleted;
				}
			)
		);

		$this->assertNotSame( array(), $rows, 'the delete must still be journalled' );
		$this->assertSame( array(), $present, 'a buffered present row must never outlive the tombstone' );
	}

	/**
	 * The real nesting shape: a v2 push forwards to the route that does the writes.
	 *
	 * `Write_Controller::dispatch_write()` calls `rest_do_request()` on
	 * `/wc/v3/orders` from inside the `/wcpos/v2/write` handler, so the saves all
	 * happen one level in. That inner handler closing is what collapses them.
	 */
	public function test_a_nested_dispatch_journals_the_order_once(): void {
		$order_id  = 0;
		$forwarded = false;

		self::$route_body = function () use ( &$order_id, &$forwarded ): void {
			if ( $forwarded ) {
				// The inner (forwarded-to) handler: this is where the writes happen.
				$order    = wc_create_order();
				$order_id = $order->get_id();
				for ( $i = 0; $i < 3; $i++ ) {
					$order->set_customer_note( 'forwarded ' . $i );
					$order->save();
				}

				return;
			}
			$forwarded = true;
			rest_do_request( new WP_REST_Request( 'POST', '/' . self::TEST_NAMESPACE . '/burst' ) );
		};
		rest_do_request( new WP_REST_Request( 'POST', '/' . self::TEST_NAMESPACE . '/burst' ) );

		$this->assertCount( 1, $this->rows_for( $order_id ), 'a forwarded write must still collapse to one row' );
		$this->assertSame( 1, $this->passes, 'and must be serialized once' );
	}

	/**
	 * Writes on both sides of an internal forward are all journalled.
	 *
	 * Two rows, not one, and deliberately so: each handler flushes what it buffered
	 * as it returns, rather than every level waiting for the outermost. That costs
	 * one extra row in the uncommon straddling case and buys immunity from a single
	 * unbalanced open silently deferring every write in the process to `shutdown`
	 * (see the missing-route test above). What must never happen is a LOST row.
	 */
	public function test_writes_straddling_a_nested_dispatch_are_all_journalled(): void {
		$order_id  = 0;
		$forwarded = false;

		self::$route_body = function () use ( &$order_id, &$forwarded ): void {
			if ( $forwarded ) {
				return; // the inner handler does no work of its own
			}
			$order    = wc_create_order();
			$order_id = $order->get_id();

			$forwarded = true;
			rest_do_request( new WP_REST_Request( 'POST', '/' . self::TEST_NAMESPACE . '/burst' ) );

			$order->set_customer_note( 'after the forward' );
			$order->save();
		};
		rest_do_request( new WP_REST_Request( 'POST', '/' . self::TEST_NAMESPACE . '/burst' ) );

		$rows = $this->rows_for( $order_id );
		$this->assertCount( 2, $rows, 'one row per handler that buffered a write — none lost' );
		$this->assertSame( 'hook:create', $rows[0]->origin );
		$this->assertSame( 'hook:update', $rows[1]->origin );
	}

	/**
	 * A dispatch that never reaches its closing filter still gets its row.
	 *
	 * `rest_pre_dispatch` and `rest_request_after_callbacks` are a matched pair only
	 * when a route actually matches; a short-circuit or an unmatched route opens the
	 * buffer and never closes it. `shutdown` is the backstop, and a missing change
	 * row is silent divergence — the one failure mode worth a dedicated test.
	 */
	public function test_an_unbalanced_dispatch_still_writes_the_row_at_shutdown(): void {
		$this->journal->open_order_deferral();
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertSame( array(), $this->rows_for( $order_id ), 'the row is owed, not yet written' );

		$this->journal->flush_deferred_order_rows(); // what `shutdown` calls
		$this->assertCount( 1, $this->rows_for( $order_id ), 'the backstop must not drop the change' );
	}

	/**
	 * A dispatch to a route that does not exist must not poison later dispatches.
	 *
	 * THE BUG THIS PINS, found only by running the change against a live store.
	 * The buffer was first opened on `rest_pre_dispatch`, which fires in
	 * `dispatch()` BEFORE route matching — while its partner
	 * `rest_request_after_callbacks` fires in `respond_to_request()`, which an
	 * unmatched route never reaches. WP-CLI's own bootstrap performs exactly such
	 * a dispatch, so every CLI process started life one level deep, the counter
	 * never returned to zero, and no order row was written inside a request again
	 * for the life of the process — silently, with the tests all green because the
	 * test environment happened to be balanced.
	 */
	public function test_a_dispatch_to_a_missing_route_does_not_disable_later_flushes(): void {
		rest_do_request( new WP_REST_Request( 'GET', '/' . self::TEST_NAMESPACE . '/no-such-route' ) );

		$order_id = 0;
		$this->dispatch(
			function () use ( &$order_id ): void {
				$order    = wc_create_order();
				$order_id = $order->get_id();
				$order->set_customer_note( 'after a 404' );
				$order->save();
			}
		);

		$this->assertCount(
			1,
			$this->rows_for( $order_id ),
			'an unmatched route must not leave the buffer permanently open'
		);
	}

	/** Flushing twice must not double-write. */
	public function test_flushing_an_empty_buffer_writes_nothing(): void {
		$this->journal->open_order_deferral();
		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->journal->flush_deferred_order_rows();
		$this->journal->flush_deferred_order_rows();

		$this->assertCount( 1, $this->rows_for( $order_id ) );
	}

	/** Two orders touched in one dispatch each get their own row. */
	public function test_each_order_in_a_dispatch_gets_its_own_row(): void {
		$first  = 0;
		$second = 0;

		$this->dispatch(
			function () use ( &$first, &$second ): void {
				$a     = wc_create_order();
				$first = $a->get_id();
				$a->set_customer_note( 'a' );
				$a->save();

				$b      = wc_create_order();
				$second = $b->get_id();
				$b->set_customer_note( 'b' );
				$b->save();
			}
		);

		$this->assertCount( 1, $this->rows_for( $first ) );
		$this->assertCount( 1, $this->rows_for( $second ) );
		$this->assertSame( 2, $this->passes, 'one pass per order, not one per save' );
	}
}
