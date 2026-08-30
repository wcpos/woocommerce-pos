<?php
/**
 * Rows-examined budget for the integrity scan's max-id completion query.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\Helpers\Rows_Examined;

/**
 * Judging a scan page complete must not digest the whole collection.
 *
 * WHAT THIS GATES. #1805 item 2: `Digest_Index::bucket_aggregates()` returns
 * `max_id` — the larger of the last live id and the last stored-digest id — so the
 * client knows when its walk is done. For customers, orders and the published
 * product scope it computed the live side by wrapping the UN-WINDOWED per-row
 * digest SELECT (a CRC of every row plus a GROUP_CONCAT of its meta, materialised
 * into an on-disk temp table) as a derived table just to take `MAX(id)` of it.
 * Measured on real stores: 207,336 rows examined and 0.41 s for 6,879 customers;
 * 945,358 rows and 3.1 s per call for 30k products — once per scan page, per
 * device, per walk. The same number comes straight off the base table with the
 * same predicate: `MAX(ID)` over `wp_users`, the orders table, or `wp_posts`
 * under the servable predicate.
 *
 * THE OPERATION. One `bucket_aggregates()` call over a ONE-id window, so the two
 * windowed sides (which are already bounded to the page) cost nothing and what is
 * measured is the completion query.
 *
 * TWO KINDS OF BOUND. `MAX(ID)` over `wp_users` has no predicate and is answered
 * from the primary key end without touching a row, so the customer budget is
 * absolute. The order and product completion queries carry a type/status
 * predicate and are inherently one index pass over the live rows of that type —
 * there is no index that ends on `ID` under a status filter — so their bound is
 * expressed per live row: the invariant is "one index pass, no meta join, no temp
 * table", and the derived-digest shape can never squeeze under it because it
 * costs several reads per live row (the row, its meta, and the temp table copy).
 * Both bounds are met at the base size AND at 4x (the _Large sibling), which is
 * what stops a generous ratio from ratifying a regression.
 *
 * Measured on this fixture (rows examined by one narrow-window call, steady state;
 * the _Large sibling is the 1,024 row):
 *
 *   collection        | live rows | before #1805 | after | budget
 *   ------------------|-----------|--------------|-------|-------
 *   customers         |       256 |        2,331 |     7 |     50
 *   customers         |     1,024 |        9,243 |     7 |     50
 *   orders (CPT)      |       256 |        2,638 |   265 |    868
 *   orders (CPT)      |     1,024 |        9,550 | 1,033 |  3,172
 *   products publish  |       512 |        7,760 | 1,033 |  1,636
 *   products publish  |     2,048 |       30,032 | 4,105 |  6,244
 *
 * The derived-digest shape costs 9–15 rows per live row; the base-table shape
 * costs 1 (orders: one covering index pass) to 2 (products: a posts pass plus one
 * parent probe per variation). A 3-per-live-row ceiling sits between them with
 * room on both sides at both sizes.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index::bucket_aggregates
 */
class Test_Integrity_Scan_Max_Id_Query_Budget extends Sync_Store_Test_Case {
	/**
	 * Rows seeded per collection: this many users, this many orders, and this many
	 * products each with one variation.
	 */
	protected const FIXTURE_SIZE = 256;

	/**
	 * Absolute ceiling for the customer call: `MAX(ID)` off the users primary key
	 * reads no row ("Select tables optimized away"), so the honest figure is the 7
	 * rows the two one-id windowed sides touch. 50 leaves room for a WordPress or
	 * WooCommerce version reading something incidental while sitting 46x below the
	 * smallest derived-digest measurement.
	 */
	protected const CUSTOMER_ROW_BUDGET = 50;

	/**
	 * Ceiling for the order and product calls, as rows examined PER live row of the
	 * collection, plus {@see self::FIXED_SLACK}. Measured: 1.0 (orders) and 2.0
	 * (products) after; 9.3–15 before.
	 */
	protected const ROWS_PER_LIVE_ROW = 3;

	/**
	 * Fixed allowance for the windowed sides, option reads and statistics reads.
	 */
	protected const FIXED_SLACK = 100;

	/**
	 * Marks every fixture row so teardown can find them again.
	 */
	protected const FIXTURE_MARKER = 'WCPOS_MAX_ID_BUDGET_FIXTURE';

	/**
	 * The read half under test.
	 *
	 * @var Digest_Index
	 */
	private $index;

	/**
	 * Seed the store BEFORE any per-test transaction exists (`ANALYZE TABLE`
	 * implicitly COMMITs — see Test_Order_Save_Query_Budget).
	 *
	 * @param mixed $factory WP unit-test factory (unused).
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		static::seed_users( static::FIXTURE_SIZE );
		static::seed_orders( static::FIXTURE_SIZE );
		static::seed_products( static::FIXTURE_SIZE );
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
		$wpdb->query( "DELETE m FROM {$wpdb->usermeta} m JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.user_url = '{$marker}'" );
		$wpdb->query( "DELETE FROM {$wpdb->users} WHERE user_url = '{$marker}'" );
		// phpcs:enable
	}

	/**
	 * Build the collaborator for each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->index = new Digest_Index();
	}

	/**
	 * Seed $count users, each with the digested usermeta a real customer carries.
	 *
	 * @param int $count Users to seed.
	 */
	protected static function seed_users( int $count ): void {
		global $wpdb;

		$marker   = static::FIXTURE_MARKER;
		$first_id = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk test-fixture seeding; every value tuple is prepared.
		$rows = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$login  = 'wcpos_budget_' . $i . '_' . wp_generate_password( 6, false );
			$rows[] = $wpdb->prepare(
				"( %s, 'x', %s, %s, %s, '2026-01-01 00:00:00', '', 0, %s )",
				$login,
				$login,
				$login . '@example.test',
				$marker,
				$login
			);
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$wpdb->users} (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES "
				. implode( ',', $chunk )
			);
			if ( 0 === $first_id ) {
				$first_id = (int) $wpdb->insert_id;
			}
		}

		$tuples = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id = $first_id + $i;
			foreach ( Digest_Index::CUSTOMER_DIGESTED_META_KEYS as $key ) {
				$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, $key, 'v' );
			}
			$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, $wpdb->prefix . 'capabilities', serialize( array( 'customer' => true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirrors how WordPress stores capabilities.
			$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, Pos_Uuid::META_KEY, wp_generate_uuid4() );
		}
		foreach ( array_chunk( $tuples, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		// phpcs:enable
	}

	/**
	 * Seed $count CPT orders, each with the digested order meta.
	 *
	 * @param int $count Orders to seed.
	 */
	protected static function seed_orders( int $count ): void {
		$first_id = static::seed_posts( 'shop_order', 'wc-completed', $count );
		$tuples   = array();
		foreach ( range( $first_id, $first_id + $count - 1 ) as $id ) {
			foreach ( Digest_Index::ORDER_DIGESTED_META_KEYS as $key ) {
				$tuples[] = array( $id, $key, '1' );
			}
			$tuples[] = array( $id, Pos_Uuid::META_KEY, wp_generate_uuid4() );
		}
		static::insert_postmeta( $tuples );
	}

	/**
	 * Seed $count published products, each with one published variation and the
	 * digested product meta on both.
	 *
	 * @param int $count Products to seed.
	 */
	protected static function seed_products( int $count ): void {
		global $wpdb;

		$first_product = static::seed_posts( 'product', 'publish', $count );
		$first_variant = static::seed_posts( 'product_variation', 'publish', $count );

		// Parent each variation on one seeded product.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture wiring.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_parent = ID - %d WHERE ID BETWEEN %d AND %d",
				$first_variant - $first_product,
				$first_variant,
				$first_variant + $count - 1
			)
		);

		$tuples = array();
		foreach ( array_merge( range( $first_product, $first_product + $count - 1 ), range( $first_variant, $first_variant + $count - 1 ) ) as $id ) {
			foreach ( Digest_Index::DIGESTED_META_KEYS as $key ) {
				$tuples[] = array( $id, $key, '1' );
			}
			$tuples[] = array( $id, Pos_Uuid::META_KEY, wp_generate_uuid4() );
		}
		static::insert_postmeta( $tuples );
	}

	/**
	 * Insert $count posts of one type and status; returns the first id.
	 *
	 * @param string $post_type   Post type.
	 * @param string $post_status Post status.
	 * @param int    $count       Posts to insert.
	 */
	protected static function seed_posts( string $post_type, string $post_status, int $count ): int {
		global $wpdb;

		$marker   = static::FIXTURE_MARKER;
		$first_id = 0;
		$rows     = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = $wpdb->prepare(
				"( %s, %s, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00', %s, '', '' )",
				$post_type,
				$post_status,
				$marker
			);
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk test-fixture seeding; every value tuple is prepared.
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$wpdb->posts} (post_type, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_title, post_content, post_excerpt) VALUES "
				. implode( ',', $chunk )
			);
			if ( 0 === $first_id ) {
				$first_id = (int) $wpdb->insert_id;
			}
		}
		// phpcs:enable

		return $first_id;
	}

	/**
	 * Bulk-insert `(post_id, meta_key, meta_value)` tuples.
	 *
	 * @param array<int, array{0: int, 1: string, 2: string}> $tuples Rows to insert.
	 */
	protected static function insert_postmeta( array $tuples ): void {
		global $wpdb;

		$prepared = array();
		foreach ( $tuples as $tuple ) {
			$prepared[] = $wpdb->prepare( '(%d,%s,%s)', $tuple[0], $tuple[1], $tuple[2] );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk test-fixture seeding; every value tuple is prepared.
		foreach ( array_chunk( $prepared, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		// phpcs:enable
	}

	/**
	 * Refresh optimizer statistics for every seeded table.
	 */
	protected static function analyze_fixture_tables(): void {
		global $wpdb;

		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->users, $wpdb->usermeta ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture statistics refresh.
			$wpdb->query( "ANALYZE TABLE {$table}" );
		}
	}

	/**
	 * A window covering exactly one id, so the windowed sides cost nothing.
	 */
	private function one_id_window(): array {
		return array(
			'bucket_size' => 1,
			'start' => 0,
			'end' => 1,
		);
	}

	/**
	 * Live rows the collection's completion query has to consider.
	 *
	 * @param string $collection Collection name.
	 */
	private function live_rows( string $collection ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture size check.
		if ( 'orders' === $collection ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status NOT IN ('trash','auto-draft')" );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')" );
		// phpcs:enable
	}

	/**
	 * `EXPLAIN` of every SELECT the scan page issued, for failure messages — the
	 * completion query is the one under defence, but a red build must also show
	 * whether one of the windowed sides picked a bad plan. Captured off the `query`
	 * filter rather than rebuilt, so it cannot go stale.
	 *
	 * @param string $collection Collection to scan.
	 * @param array  $filters    Scan filters.
	 */
	private function diagnostic_page_explain( string $collection, array $filters ): string {
		$captured = array();
		$capture  = static function ( $query ) use ( &$captured ) {
			if ( 0 === stripos( ltrim( (string) $query ), 'SELECT' ) ) {
				$captured[] = (string) $query;
			}

			return $query;
		};
		add_filter( 'query', $capture );
		try {
			$this->index->bucket_aggregates( $this->one_id_window(), $collection, $filters );
		} finally {
			remove_filter( 'query', $capture );
		}

		$out = array();
		foreach ( $captured as $sql ) {
			$out[] = substr( $sql, 0, 160 ) . ( \strlen( $sql ) > 160 ? '…' : '' ) . "\n" . Rows_Examined::explain( $sql );
		}

		return implode( "\n", $out );
	}

	/**
	 * Rows examined by one narrow-window scan call, at steady state.
	 *
	 * The FIRST scan call in a process pays a constant ~326 rows on top of the page
	 * cost — measured identically for every collection and both fixture sizes
	 * (333→7, 589→265, 1,359→1,033, 1,357→1,033, 4,431→4,105 on two consecutive
	 * calls), so it is a one-off statistics/dictionary load after `ANALYZE TABLE`,
	 * not anything the page does. One warm-up call absorbs it; what is measured is
	 * the cost every subsequent page pays.
	 *
	 * @param string $collection Collection to scan.
	 * @param array  $filters    Scan filters.
	 */
	private function measure( string $collection, array $filters = array() ): int {
		$page = function () use ( $collection, $filters ) {
			return $this->index->bucket_aggregates( $this->one_id_window(), $collection, $filters );
		};
		$page();

		return Rows_Examined::measure( $page );
	}

	/**
	 * The fixture must be there, or the budgets below gate nothing.
	 */
	public function test_fixture_is_seeded(): void {
		global $wpdb;

		$marker = static::FIXTURE_MARKER;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture size check.
		$this->assertSame( static::FIXTURE_SIZE, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_url = '{$marker}'" ) );
		$this->assertSame( static::FIXTURE_SIZE, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = '{$marker}' AND post_type = 'shop_order'" ) );
		$this->assertSame( static::FIXTURE_SIZE, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = '{$marker}' AND post_type = 'product'" ) );
		$this->assertSame( static::FIXTURE_SIZE, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = '{$marker}' AND post_type = 'product_variation' AND post_parent > 0" ) );
		// phpcs:enable
	}

	/**
	 * The customer completion query reads no customer row.
	 */
	public function test_customer_scan_page_stays_inside_the_absolute_row_budget(): void {
		$rows = $this->measure( 'customers' );

		$this->assertLessThanOrEqual(
			static::CUSTOMER_ROW_BUDGET,
			$rows,
			sprintf( 'A one-id customer scan page examined %d rows on a %d-user fixture (budget %d): the completion query is digesting the collection again. ', $rows, static::FIXTURE_SIZE, static::CUSTOMER_ROW_BUDGET )
			. $this->diagnostic_page_explain( 'customers', array() )
		);
	}

	/**
	 * The order completion query is one index pass over live orders.
	 */
	public function test_order_scan_page_costs_at_most_one_index_pass(): void {
		$live   = $this->live_rows( 'orders' );
		$budget = static::ROWS_PER_LIVE_ROW * $live + static::FIXED_SLACK;
		$rows   = $this->measure( 'orders' );

		$this->assertLessThanOrEqual(
			$budget,
			$rows,
			sprintf( 'A one-id order scan page examined %d rows over %d live orders (budget %d): the completion query is digesting the collection again. ', $rows, $live, $budget )
			. $this->diagnostic_page_explain( 'orders', array() )
		);
	}

	/**
	 * The published-product completion query is one index pass over the catalog.
	 */
	public function test_published_product_scan_page_costs_at_most_one_index_pass(): void {
		$filters = array( 'status' => 'publish' );
		$live    = $this->live_rows( 'products' );
		$budget  = static::ROWS_PER_LIVE_ROW * $live + static::FIXED_SLACK;
		$rows    = $this->measure( 'products', $filters );

		$this->assertLessThanOrEqual(
			$budget,
			$rows,
			sprintf( 'A one-id published-product scan page examined %d rows over %d live product-space rows (budget %d): the completion query is digesting the collection again. ', $rows, $live, $budget )
			. $this->diagnostic_page_explain( 'products', $filters )
		);
	}
}
