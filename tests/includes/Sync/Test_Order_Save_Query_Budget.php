<?php
/**
 * Rows-examined budget for saving a `pos-open` order.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\Helpers\Rows_Examined;
use WP_UnitTestCase;

/**
 * Saving a POS order must not scale with how many orders the store already has.
 *
 * WHAT THIS GATES. #1725: `ORDER BY <join column> ASC LIMIT 2` on the uuid collision
 * check made the optimizer abandon the `meta_key` index for an id-ordered walk that
 * expects to stop at two matches. The uuid being checked belongs to the record being
 * stamped, so there is at most ONE match and it never stops early — a full scan of
 * `wp_postmeta`, twice per order save, ~2.8 s of a 3.4 s save on a real store.
 *
 * THE OPERATION. Persist a `pos-open` order carrying the uuid the client minted, then
 * run the POS identity check over it — `save()` plus `ensure_uuid()` with the
 * order-aware collision detector. That pair is what every order-serving path performs
 * and is where the regression lived. It is measured directly rather than through a
 * REST controller deliberately: the property under defence is server-side and
 * lane-independent, and pinning it to a namespace would tie a permanent gate to
 * whichever controller happens to be current. Budgets for the v2 push and pull
 * handlers are their own work (#1726, item 2).
 *
 * WHY THE BOUND IS ABSOLUTE. The invariant is "this does not scale with store size".
 * A budget expressed relative to the fixture would ratify the next full scan the
 * moment someone grew the fixture. So the SAME ceiling applies at 128 orders and at
 * 512: an implementation that walks the meta table blows it at both sizes and blows
 * it harder as the store grows, while a correct one barely moves.
 *
 * WHY 128 IS THE FLOOR. The bad plan does not exist below ~64 orders — the optimizer
 * picks the good index anyway and the assertion passes for the wrong reason, gating
 * nothing. 128 is 2x the measured flip threshold, for headroom against optimizer
 * differences across MySQL/MariaDB versions. {@see self::ORDER_FIXTURE_SIZE}.
 *
 * WHY NOT `EXPLAIN`, AND WHY NOT THE SQL STRING. Both are covered in
 * {@see Rows_Examined} — in short, the plan is not portable across `mariadb:lts`
 * image bumps, and pinning the SQL literal makes a test fail on the fix rather than
 * on the bug. `EXPLAIN` appears in the failure message instead.
 *
 * PER-DATASTORE BOUNDS. This class runs on the suite-default CPT datastore. HPOS gets
 * its own pair with its own, far tighter ceiling — `wc_orders_meta` carries a
 * composite `(meta_key, meta_value)` index, so the same operation costs a fraction as
 * much there and one shared bound would gate neither.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::get_order_ids_by_uuid
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::uuid_owned_by_other_order
 */
class Test_Order_Save_Query_Budget extends WP_UnitTestCase {
	/**
	 * Orders seeded before measuring. Must stay above the ~64-order plan-flip
	 * threshold or the gate stops gating.
	 */
	protected const ORDER_FIXTURE_SIZE = 128;

	/**
	 * Non-uuid meta rows per seeded order, mirroring the ~31:1 ratio measured on a
	 * real store. The ratio is what separates the two plans: the bad one walks all
	 * meta rows, the good one only the uuid ones.
	 */
	protected const FILLER_META_PER_ORDER = 30;

	/**
	 * Absolute ceiling on rows examined while saving one `pos-open` order, on the
	 * CPT order datastore, at ANY fixture size.
	 *
	 * Measured on this fixture:
	 *
	 *   orders | before the #1725 fix | after
	 *   -------|----------------------|-------
	 *      128 |                4,090 |   213
	 *      512 |               15,994 |   597
	 *
	 * Decomposed, the fixed figure is ~83 rows of ordinary order save plus one uuid
	 * lookup over the fixture's uuid rows (130 at 128 orders, 514 at 512) — so only
	 * that ~83 moves with the WooCommerce version. 1,500 leaves ~900 rows of slack
	 * for that while sitting 2.7x below the smallest full-scan measurement, which a
	 * returning scan cannot squeeze under.
	 */
	protected const ROW_BUDGET = 1500;

	/**
	 * Marks every fixture row so teardown can find them again.
	 */
	protected const FIXTURE_MARKER = 'WCPOS_ORDER_BUDGET_FIXTURE';

	/**
	 * Lowest id of the seeded fixture rows.
	 *
	 * @var int
	 */
	protected static $fixture_first_id = 0;

	/**
	 * Seed the store BEFORE any per-test transaction exists.
	 *
	 * This has to run here, not in `setUp()`. `ANALYZE TABLE` implicitly COMMITs, and
	 * a commit mid-`setUp()` ends the transaction that `tearDown()` rolls back — every
	 * fixture created before it becomes permanent and leaks into later classes. The
	 * scar is written up at length in HPOSToggleTrait's docblock. `wpSetUpBeforeClass()`
	 * is the sanctioned seam: WordPress runs it before the first transaction opens and
	 * commits straight afterwards.
	 *
	 * And the `ANALYZE` is not optional. Without current statistics the optimizer
	 * guesses, picks a third plan entirely, and both the broken and the fixed query
	 * measure the same — a green gate that proves nothing. Verified: pre-`ANALYZE`
	 * both variants examined ~516 rows; post-`ANALYZE`, 3,972 versus 132.
	 *
	 * @param mixed $factory WP unit-test factory (unused).
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		static::seed_orders( static::ORDER_FIXTURE_SIZE );
		static::analyze_fixture_tables();
	}

	/**
	 * Remove the fixture, outside any test transaction.
	 */
	public static function wpTearDownAfterClass() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture teardown, keyed on a marker this class wrote.
		$marker = static::FIXTURE_MARKER;
		$wpdb->query( "DELETE m FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_title = '{$marker}'" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title = '{$marker}'" );

		$orders      = $wpdb->prefix . 'wc_orders';
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$orders}'" ) === $orders ) {
			$wpdb->query( "DELETE m FROM {$orders_meta} m JOIN {$orders} o ON o.id = m.order_id WHERE o.transaction_id = '{$marker}'" );
			$wpdb->query( "DELETE FROM {$orders} WHERE transaction_id = '{$marker}'" );
		}
		// phpcs:enable

		self::$fixture_first_id = 0;
	}

	/**
	 * True when the fixture and the measurement run on the HPOS datastore.
	 */
	protected static function uses_hpos(): bool {
		return false;
	}

	/**
	 * Seed $count orders, each carrying a distinct uuid plus filler meta.
	 *
	 * Raw SQL on purpose: `wc_create_order()` x512 would dominate the runtime of the
	 * class, and the fixture only needs to be shaped like a real store's meta tables,
	 * not to be a set of fully-hydrated WooCommerce objects.
	 *
	 * @param int $count Orders to seed.
	 */
	protected static function seed_orders( int $count ): void {
		global $wpdb;

		$marker = static::FIXTURE_MARKER;

		// The property is declared once on this class, so every paired subclass shares
		// one slot. Reset on entry rather than relying only on the previous class's
		// teardown — a stale non-zero value would make the CPT branch below skip its
		// insert_id capture and every id derived from it would be wrong.
		self::$fixture_first_id = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk test-fixture seeding; every value tuple is prepared.
		if ( static::uses_hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';

			// `wc_orders.id` is NOT auto-increment — HPOS mints order ids from the
			// posts table and writes them in. Pick a base clear of both id spaces so
			// the fixture cannot collide with anything WordPress or WooCommerce mints
			// later in the class.
			$max_post  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
			$max_order = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$orders}" );

			self::$fixture_first_id = max( $max_post, $max_order ) + 1000000;

			$rows = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$rows[] = $wpdb->prepare(
					"( %d, 'shop_order', %s, 'USD', 0, 0, %s, %s, %s )",
					self::$fixture_first_id + $i,
					'wc-completed',
					'2026-01-01 00:00:00',
					'2026-01-01 00:00:00',
					$marker
				);
			}
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				$wpdb->query(
					"INSERT INTO {$orders} (id, type, status, currency, tax_amount, total_amount, date_created_gmt, date_updated_gmt, transaction_id) VALUES "
					. implode( ',', $chunk )
				);
			}

			$orders_meta = $wpdb->prefix . 'wc_orders_meta';
			$meta        = static::meta_tuples( self::$fixture_first_id, $count );
			foreach ( array_chunk( $meta, 2000 ) as $chunk ) {
				$wpdb->query( "INSERT INTO {$orders_meta} (order_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
			}

			return;
		}

		$posts = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$posts[] = $wpdb->prepare(
				"( 'shop_order', 'wc-completed', %s, %s, %s, %s, %s, '' )",
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				$marker
			);
		}
		foreach ( array_chunk( $posts, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$wpdb->posts} (post_type, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_title, post_content) VALUES "
				. implode( ',', $chunk )
			);
			if ( 0 === self::$fixture_first_id ) {
				self::$fixture_first_id = (int) $wpdb->insert_id;
			}
		}

		$meta = static::meta_tuples( self::$fixture_first_id, $count );
		foreach ( array_chunk( $meta, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		// phpcs:enable
	}

	/**
	 * Prepared `(id, meta_key, meta_value)` tuples for the seeded orders.
	 *
	 * @param int $first_id First seeded order id.
	 * @param int $count    Orders seeded.
	 *
	 * @return array
	 */
	protected static function meta_tuples( int $first_id, int $count ): array {
		global $wpdb;

		$tuples = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id       = $first_id + $i;
			$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, Pos_Uuid::META_KEY, wp_generate_uuid4() );
			for ( $k = 0; $k < static::FILLER_META_PER_ORDER; $k++ ) {
				$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_wcpos_budget_filler_' . $k, 'x' );
			}
		}

		return $tuples;
	}

	/**
	 * Refresh optimizer statistics for the seeded tables.
	 */
	protected static function analyze_fixture_tables(): void {
		global $wpdb;

		$tables = static::uses_hpos()
			? array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta' )
			: array( $wpdb->posts, $wpdb->postmeta );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture statistics refresh.
			$wpdb->query( "ANALYZE TABLE {$table}" );
		}
	}

	/**
	 * Meta rows belonging to the seeded fixture.
	 *
	 * Bounded at both ends: anything the test itself creates lands above the fixture's
	 * id range, and counting those would make the seed check below inexact.
	 */
	protected function fixture_meta_rows(): int {
		global $wpdb;

		$table  = static::uses_hpos() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$column = static::uses_hpos() ? 'order_id' : 'post_id';
		$first  = (int) self::$fixture_first_id;
		$last   = $first + static::ORDER_FIXTURE_SIZE - 1;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture size check.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$column} BETWEEN {$first} AND {$last}" );
	}

	/**
	 * The uuid lookup production actually issued, for `EXPLAIN` in failure messages.
	 *
	 * Read back off `$wpdb->last_query` after calling the real function rather than
	 * rebuilt here. Two reasons: a hand-copied SQL literal in a test goes stale the
	 * first time production is edited, and — worse — a literal is the thing this
	 * whole gate must NOT be pinned to. Pinning the SQL text would make the test fail
	 * on a fix instead of on a bug. This reads whatever production issued, whatever
	 * that turns out to be.
	 *
	 * Diagnostic only. Never asserted on. It exists so a red build says WHICH index
	 * the optimizer reached for — the difference between "someone reintroduced the
	 * #1725 plan" and "the rest of the save got heavier".
	 */
	protected function diagnostic_lookup_sql(): string {
		global $wpdb;

		Pos_Uuid::get_order_ids_by_uuid( wp_generate_uuid4() );

		return (string) $wpdb->last_query;
	}

	/**
	 * Save one `pos-open` order the way the POS does, and report rows examined.
	 *
	 * The uuid is set before the save because that is the real sequence: the client
	 * mints the identity, the server persists it and then verifies no OTHER order
	 * already owns it. A freshly-minted uuid on an unsaved order takes the mint path
	 * instead and never reaches the detector — measuring that would gate nothing.
	 *
	 * @return int Rows examined.
	 */
	protected function measure_pos_open_order_save(): int {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);

		$uuid  = wp_generate_uuid4();
		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->set_status( 'pos-open' );
		$order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$order->calculate_totals();

		$examined = Rows_Examined::measure(
			function () use ( $order ) {
				$order->save();

				return Pos_Uuid::ensure_uuid(
					$order,
					array( 'collides' => array( Pos_Uuid::class, 'uuid_owned_by_other_order' ) )
				);
			},
			$resolved_uuid
		);

		$this->assertSame( $uuid, $resolved_uuid, 'The identity path must keep the client uuid, or the collision detector did not run over it.' );

		return $examined;
	}

	/**
	 * The fixture has to be big enough for the plan flip to be reachable, or the
	 * budget below passes for the wrong reason.
	 */
	public function test_fixture_is_seeded_past_the_plan_flip_threshold(): void {
		$expected_meta = static::ORDER_FIXTURE_SIZE * ( static::FILLER_META_PER_ORDER + 1 );

		$this->assertGreaterThanOrEqual( 128, static::ORDER_FIXTURE_SIZE, 'Seed at least 2x the ~64-order plan-flip threshold.' );
		$this->assertGreaterThan( 0, self::$fixture_first_id, 'The fixture did not seed.' );
		$this->assertSame( $expected_meta, $this->fixture_meta_rows(), 'The fixture meta rows are missing; the budget below would gate nothing.' );
	}

	/**
	 * THE GATE. Saving a `pos-open` order stays under an absolute row budget,
	 * whatever the store already holds.
	 */
	public function test_pos_open_order_save_stays_within_the_rows_examined_budget(): void {
		// Arrange / Act.
		$examined = $this->measure_pos_open_order_save();

		// Assert. The diagnostic runs after the measurement, so it cannot pollute it.
		$lookup_sql = $this->diagnostic_lookup_sql();
		$this->assertLessThanOrEqual(
			static::ROW_BUDGET,
			$examined,
			sprintf(
				"Saving one pos-open order examined %s rows against a budget of %s, with %s orders / %s meta rows seeded.\n"
				. "This is what a query-plan regression looks like: the query COUNT will be unchanged.\n"
				. "The uuid lookup production issued, and its plan:\n  %s\n%s\n",
				number_format( $examined ),
				number_format( static::ROW_BUDGET ),
				number_format( static::ORDER_FIXTURE_SIZE ),
				number_format( $this->fixture_meta_rows() ),
				$lookup_sql,
				Rows_Examined::explain( $lookup_sql )
			)
		);
	}
}
