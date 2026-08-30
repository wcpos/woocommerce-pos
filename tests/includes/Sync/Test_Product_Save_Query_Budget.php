<?php
/**
 * Rows-examined budget for the identity check inside a product save.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\Helpers\Rows_Examined;
use WP_UnitTestCase;

/**
 * Saving a product must not scale with how many products the store already has.
 *
 * WHAT THIS GATES. #1805: `stamp_on_save` ran the uuid ownership scan on EVERY
 * product and variation save — `SELECT COUNT(*) FROM wp_postmeta … WHERE meta_key =
 * '_woocommerce_pos_uuid' AND meta_value = ?` — to catch a clone that copied the
 * meta. `wp_postmeta` indexes `meta_key` but never `meta_value`, so the scan walks
 * every uuid row in the store: 30,251 rows examined and 0.46 s per save on a
 * 30k-product store, 114 times an hour, one per product save (a stock change on a
 * sale is a save). A uuid this record LOADED from its own meta row is already this
 * record's identity, so the hook now skips the scan for it and runs it only for a
 * uuid that arrived some other way — a cloned object (meta ids cleared), an importer
 * rewriting the value, an unsaved record.
 *
 * THE OPERATION. Load a saved product the way every save path does, then run the
 * before-save hook over it. That is the exact sequence a stock update, a price edit
 * or a REST update performs before the data store writes.
 *
 * WHY THE BOUND IS ABSOLUTE. "Does not scale" is the property. The SAME ceiling
 * applies at 256 and at 1,024 seeded products: the scan blows it at both sizes and
 * harder as the store grows; the skip issues no query at all.
 *
 * THE FIXTURE VALIDITY CHECK. A gate is only evidence when the thing it guards
 * against is expensive on the fixture. {@see self::test_the_ownership_scan_walks_the_fixture}
 * pins that the scan the hook now avoids still costs at least one row per seeded
 * product here. If that ever stops being true (a plugin-owned uuid index, say), this
 * class and the skip it gates are due for a rethink together.
 *
 * Measured on this fixture (rows examined by the before-save hook on a loaded product):
 *
 *   products | before #1805 | after | budget
 *   ---------|--------------|-------|-------
 *        256 |          258 |     0 |     50
 *      1,024 |        1,026 |     0 |     50
 *
 * Before: one row per uuid in the store plus the record's own (the `meta_key`
 * index walk). After: no query — the hook decides from the loaded meta entry.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::stamp_on_save
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::uuid_owned_by_other
 */
class Test_Product_Save_Query_Budget extends WP_UnitTestCase {
	/**
	 * Products seeded before measuring, each carrying its own uuid.
	 */
	protected const PRODUCT_FIXTURE_SIZE = 256;

	/**
	 * Non-uuid meta rows per seeded product. A real catalog row carries dozens of
	 * meta rows; the ratio is what makes "walks the uuid rows" and "walks every meta
	 * row" measurably different plans.
	 */
	protected const FILLER_META_PER_PRODUCT = 30;

	/**
	 * Absolute ceiling on rows examined by the before-save identity hook over a
	 * product loaded from the database, at ANY fixture size.
	 *
	 * The skip path issues no query, so the honest figure is 0. The slack is for a
	 * future WooCommerce version reading something incidental inside the hook — it
	 * still sits 5x below the smallest scan measurement, which a returning scan
	 * cannot squeeze under.
	 */
	protected const ROW_BUDGET = 50;

	/**
	 * Marks every fixture row so teardown can find them again.
	 */
	protected const FIXTURE_MARKER = 'WCPOS_PRODUCT_BUDGET_FIXTURE';

	/**
	 * Seed the store BEFORE any per-test transaction exists — `ANALYZE TABLE`
	 * implicitly COMMITs, so it must never run inside a test (see
	 * Test_Order_Save_Query_Budget for the scar).
	 *
	 * @param mixed $factory WP unit-test factory (unused).
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		static::seed_products( static::PRODUCT_FIXTURE_SIZE );
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
		// phpcs:enable
	}

	/**
	 * Seed $count published products, each with a distinct uuid plus filler meta.
	 *
	 * Raw SQL on purpose: the fixture only needs the SHAPE of a real store's tables,
	 * not a thousand hydrated WooCommerce objects, and a hydrated save would run the
	 * very hook under measurement.
	 *
	 * @param int $count Products to seed.
	 */
	protected static function seed_products( int $count ): void {
		global $wpdb;

		$marker   = static::FIXTURE_MARKER;
		$first_id = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk test-fixture seeding; every value tuple is prepared.
		$posts = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$posts[] = $wpdb->prepare(
				"( 'product', 'publish', %s, %s, %s, %s, %s, '', '' )",
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				'2026-01-01 00:00:00',
				$marker
			);
		}
		foreach ( array_chunk( $posts, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$wpdb->posts} (post_type, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_title, post_content, post_excerpt) VALUES "
				. implode( ',', $chunk )
			);
			if ( 0 === $first_id ) {
				$first_id = (int) $wpdb->insert_id;
			}
		}

		$tuples = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id       = $first_id + $i;
			$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, Pos_Uuid::META_KEY, wp_generate_uuid4() );
			for ( $k = 0; $k < static::FILLER_META_PER_PRODUCT; $k++ ) {
				$tuples[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_wcpos_budget_filler_' . $k, 'x' );
			}
		}
		foreach ( array_chunk( $tuples, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		// phpcs:enable
	}

	/**
	 * Refresh optimizer statistics for the seeded tables. Without them the planner
	 * guesses, and a plan-sensitive gate measured against a guess proves nothing.
	 */
	protected static function analyze_fixture_tables(): void {
		global $wpdb;

		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture statistics refresh.
			$wpdb->query( "ANALYZE TABLE {$table}" );
		}
	}

	/**
	 * Uuid rows the fixture contributed.
	 */
	protected function fixture_uuid_rows(): int {
		global $wpdb;

		$marker = static::FIXTURE_MARKER;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture size check.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_title = %s AND m.meta_key = %s",
				$marker,
				Pos_Uuid::META_KEY
			)
		);
	}

	/**
	 * A product persisted with a uuid, then loaded fresh from the database — the
	 * state every save path starts from.
	 *
	 * @return array{0: \WC_Product, 1: string} The loaded product and its persisted uuid.
	 */
	protected function loaded_product_with_uuid(): array {
		$product = ProductHelper::create_simple_product();
		$uuid    = Pos_Uuid::ensure_uuid( $product, array( 'collides' => array( Pos_Uuid::class, 'uuid_owned_by_other' ) ) );
		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );

		$loaded = wc_get_product( $product->get_id() );
		$this->assertSame( $uuid, $loaded->get_meta( Pos_Uuid::META_KEY ) );

		return array( $loaded, $uuid );
	}

	/**
	 * The ownership scan production issues, for `EXPLAIN` in failure messages.
	 * Read back off `$wpdb->last_query` so the diagnostic can never go stale.
	 *
	 * @param \WC_Product $product Product to run the scan against.
	 */
	protected function diagnostic_scan_sql( $product ): string {
		global $wpdb;

		Pos_Uuid::uuid_owned_by_other( wp_generate_uuid4(), $product );

		return (string) $wpdb->last_query;
	}

	/**
	 * The fixture must be there, or the budget below gates nothing.
	 */
	public function test_fixture_is_seeded(): void {
		$this->assertSame( static::PRODUCT_FIXTURE_SIZE, $this->fixture_uuid_rows() );
	}

	/**
	 * The scan the hook now avoids is genuinely linear on this fixture — the
	 * validity condition for the budget below.
	 */
	public function test_the_ownership_scan_walks_the_fixture(): void {
		list( $loaded, $uuid ) = $this->loaded_product_with_uuid();

		$rows = Rows_Examined::measure(
			static function () use ( $loaded, $uuid ) {
				return Pos_Uuid::uuid_owned_by_other( $uuid, $loaded );
			},
			$owned_by_other
		);

		$this->assertFalse( $owned_by_other );
		if ( $rows < static::PRODUCT_FIXTURE_SIZE ) {
			$this->fail(
				'The ownership scan examined fewer rows than the fixture holds, so the budget below would pass for the wrong reason. '
				. Rows_Examined::explain( $this->diagnostic_scan_sql( $loaded ) )
			);
		}
		$this->assertGreaterThanOrEqual( static::PRODUCT_FIXTURE_SIZE, $rows );
	}

	/**
	 * The before-save hook over a loaded product stays inside the budget.
	 */
	public function test_before_save_hook_on_a_loaded_product_stays_inside_the_row_budget(): void {
		list( $loaded, $uuid ) = $this->loaded_product_with_uuid();

		$rows = Rows_Examined::measure(
			static function () use ( $loaded ) {
				Pos_Uuid::stamp_on_save( $loaded );
			}
		);

		$this->assertSame( $uuid, $loaded->get_meta( Pos_Uuid::META_KEY ), 'The hook must keep the persisted identity.' );
		// The EXPLAIN is built only on failure: it costs a full scan of the fixture.
		if ( $rows > static::ROW_BUDGET ) {
			$this->fail(
				sprintf(
					'stamp_on_save examined %d rows on a %d-product fixture (budget %d): the uuid ownership scan is back on the save path. ',
					$rows,
					static::PRODUCT_FIXTURE_SIZE,
					static::ROW_BUDGET
				) . Rows_Examined::explain( $this->diagnostic_scan_sql( $loaded ) )
			);
		}
		$this->assertLessThanOrEqual( static::ROW_BUDGET, $rows );
	}
}
