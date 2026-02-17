<?php
/**
 * Tests for safe duplicate postmeta repair.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Meta_Data_Repair;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Meta_Data_Repair_Service extends WP_UnitTestCase {
	/**
	 * Count postmeta rows for a specific post/key pair.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
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
	 * Count postmeta rows for a specific post/key/value triplet.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 *
	 * @return int
	 */
	private function count_meta_rows_by_value( int $post_id, string $meta_key, string $meta_value ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
				$post_id,
				$meta_key,
				$meta_value
			)
		);
	}

	/**
	 * Insert direct duplicate rows for tests.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @param int    $count      Number of rows.
	 */
	private function insert_duplicates( int $post_id, string $meta_key, string $meta_value, int $count ): void {
		global $wpdb;

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $meta_key,
					'meta_value' => $meta_value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Mark a post as POS-touched.
	 *
	 * @param int $post_id Post ID.
	 */
	private function mark_pos_touched( int $post_id ): void {
		add_post_meta( $post_id, '_woocommerce_pos_uuid', wp_generate_uuid4(), true );
	}

	/**
	 * Dry-run should report candidate deletes but not mutate data.
	 */
	public function test_dry_run_reports_without_deleting(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );
		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1', 5 );

		$before  = $this->count_meta_rows( $post_id, '_wpcom_is_markdown' );
		$result  = Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'dry_run'   => true,
				'batch_size'=> 100,
			)
		);
		$after   = $this->count_meta_rows( $post_id, '_wpcom_is_markdown' );

		$this->assertEquals( 5, $before );
		$this->assertGreaterThanOrEqual( 4, (int) $result['rows_would_delete'] );
		$this->assertEquals( $before, $after, 'Dry-run must not delete rows.' );
	}

	/**
	 * Dry-run should not loop and overcount when a full batch is returned.
	 */
	public function test_dry_run_stops_after_first_batch_and_flags_limit(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		// Three duplicate groups with predictable delete counts:
		// _a => 3 rows (2 deletions), _b => 4 rows (3 deletions), _c => 5 rows (4 deletions).
		$this->insert_duplicates( $post_id, '_a', '1', 3 );
		$this->insert_duplicates( $post_id, '_b', '1', 4 );
		$this->insert_duplicates( $post_id, '_c', '1', 5 );

		$result = Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'dry_run'     => true,
				'batch_size'  => 2,
				'max_batches' => 10,
			)
		);

		$this->assertTrue( (bool) $result['hit_batch_limit'], 'Dry-run should flag that the estimate was truncated by batch size.' );
		$this->assertEquals( 2, (int) $result['groups_processed'], 'Dry-run should process only the first batch.' );
		$this->assertEquals( 5, (int) $result['rows_would_delete'], 'Dry-run should not overcount rows across repeated batches.' );
	}

	/**
	 * Safe repair should remove exact duplicates on POS-touched products.
	 */
	public function test_removes_exact_duplicates_on_pos_product(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );
		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1', 6 );

		$this->assertEquals( 6, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );

		Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'batch_size' => 100,
			)
		);

		$this->assertEquals( 1, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Distinct values for the same key should be preserved.
	 */
	public function test_preserves_distinct_values_for_same_meta_key(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$post_id = $product->get_id();
		$this->mark_pos_touched( $post_id );

		$this->insert_duplicates( $post_id, '_wp_old_date', 'A', 3 );
		$this->insert_duplicates( $post_id, '_wp_old_date', 'B', 2 );

		Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'batch_size' => 100,
			)
		);

		$this->assertEquals( 1, $this->count_meta_rows_by_value( $post_id, '_wp_old_date', 'A' ) );
		$this->assertEquals( 1, $this->count_meta_rows_by_value( $post_id, '_wp_old_date', 'B' ) );
		$this->assertEquals( 2, $this->count_meta_rows( $post_id, '_wp_old_date' ) );
	}

	/**
	 * Non-POS products should not be touched by the repair.
	 */
	public function test_does_not_touch_non_pos_products(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$post_id = $product->get_id();
		$this->insert_duplicates( $post_id, '_wpcom_is_markdown', '1', 4 );

		Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'batch_size' => 100,
			)
		);

		$this->assertEquals( 4, $this->count_meta_rows( $post_id, '_wpcom_is_markdown' ) );
	}

	/**
	 * Repair should include product variations that were POS-touched.
	 */
	public function test_repairs_pos_touched_variations(): void {
		$variable_product = ProductHelper::create_variation_product();
		$variation_ids    = $variable_product->get_children();
		$variation_id     = (int) $variation_ids[0];
		$this->mark_pos_touched( $variation_id );
		$this->insert_duplicates( $variation_id, '_wpcom_is_markdown', '1', 5 );

		Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
			array(
				'batch_size' => 100,
			)
		);

		$this->assertEquals( 1, $this->count_meta_rows( $variation_id, '_wpcom_is_markdown' ) );
	}
}
