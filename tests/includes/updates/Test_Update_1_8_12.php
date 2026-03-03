<?php
/**
 * Tests for the 1.8.12 database migration that removes duplicate postmeta rows.
 *
 * @see https://github.com/wcpos/woocommerce-pos/pull/519
 *
 * @package WCPOS\WooCommercePOS\Tests\Updates
 */

namespace WCPOS\WooCommercePOS\Tests\Updates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WP_UnitTestCase;

/**
 * Tests for update-1.8.12.php duplicate meta cleanup.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Update_1_8_12 extends WP_UnitTestCase {
	/**
	 * Helper to count postmeta rows for a given post + meta_key.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $meta_key The meta key.
	 *
	 * @return int
	 */
	private function count_meta_rows( int $post_id, string $meta_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$meta_key
			)
		);
	}

	/**
	 * Insert duplicate postmeta rows directly into the database.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $meta_key The meta key.
	 * @param mixed  $value    The meta value.
	 * @param int    $count    Number of rows to insert.
	 */
	private function insert_duplicates( int $post_id, string $meta_key, $value, int $count = 25 ): void {
		global $wpdb;

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $meta_key,
					'meta_value' => $value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Mark a post as POS-touched by adding the UUID meta.
	 *
	 * @param int $post_id The post ID.
	 */
	private function mark_pos_touched( int $post_id ): void {
		add_post_meta( $post_id, '_woocommerce_pos_uuid', wp_generate_uuid4(), true );
	}

	/**
	 * Run the migration script.
	 */
	private function run_migration(): void {
		include __DIR__ . '/../../../includes/updates/update-1.8.12.php';
	}

	/**
	 * Test: Migration removes duplicate meta rows on POS-touched posts.
	 */
	public function test_removes_duplicates_on_pos_touched_post(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1' );
		$this->assertEquals( 25, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		$this->run_migration();

		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Test: Migration does NOT touch posts without _woocommerce_pos_uuid.
	 */
	public function test_does_not_touch_non_pos_posts(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		// Deliberately NOT calling mark_pos_touched().

		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1' );
		$this->assertEquals( 25, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		$this->run_migration();

		// Should remain untouched — no POS UUID on this post.
		$this->assertEquals( 25, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Test: Migration keeps the most recent value (highest meta_id).
	 */
	public function test_keeps_most_recent_value(): void {
		global $wpdb;

		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		// Insert rows with different values — the last one has the highest meta_id.
		$this->insert_duplicates( $post_id, '_wp_old_date', 'OLD_VALUE', 24 );
		$this->insert_duplicates( $post_id, '_wp_old_date', 'NEWEST_VALUE', 1 );
		$this->assertEquals( 25, $this->count_meta_rows( $post_id, '_wp_old_date' ) );

		$this->run_migration();

		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wp_old_date' ) );

		$surviving_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				'_wp_old_date'
			)
		);
		$this->assertEquals( 'NEWEST_VALUE', $surviving_value );
	}

	/**
	 * Test: Migration does not touch keys with 20 or fewer rows.
	 */
	public function test_does_not_touch_rows_at_threshold(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		// Insert exactly 20 rows — at the threshold, should NOT be cleaned.
		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1', 20 );
		$this->assertEquals( 20, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		$this->run_migration();

		$this->assertEquals( 20, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Test: Migration cleans up across multiple POS products.
	 */
	public function test_cleans_up_across_multiple_products(): void {
		$product_1 = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$product_2 = ProductHelper::create_simple_product( array( 'regular_price' => 22, 'price' => 22 ) );

		$this->mark_pos_touched( $product_1->get_id() );
		$this->mark_pos_touched( $product_2->get_id() );

		$this->insert_duplicates( $product_1->get_id(), '_astra_sites_flavor', 'flavor', 30 );
		$this->insert_duplicates( $product_2->get_id(), '_astra_sites_flavor', 'flavor', 50 );

		$this->run_migration();

		$this->assertEquals( 1, $this->count_meta_rows( $product_1->get_id(), '_astra_sites_flavor' ) );
		$this->assertEquals( 1, $this->count_meta_rows( $product_2->get_id(), '_astra_sites_flavor' ) );
	}

	/**
	 * Test: Running migration twice is safe (idempotent).
	 */
	public function test_migration_is_idempotent(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1' );
		$this->assertEquals( 25, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		$this->run_migration();
		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		// Running again should not change anything.
		$this->run_migration();
		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Test: Migration does not touch unrelated meta on POS posts.
	 */
	public function test_does_not_touch_non_duplicate_meta(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		// Insert a single row — should not be affected.
		$this->insert_duplicates( $post_id, '_some_unique_key', 'value', 1 );

		// Insert duplicates for another key to trigger the migration.
		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1' );

		$this->run_migration();

		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_some_unique_key' ) );
		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}
}
