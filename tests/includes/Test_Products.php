<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Unit_Test_Case;
use WP_Query;
use WC_Query;
use WCPOS\WooCommercePOS\Products;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products extends WC_Unit_Test_Case {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * @TODO - I have no idea why this test isn't working
	 */
	public function test_pos_only_products() {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// Create a visible product
		$visible_product = ProductHelper::create_simple_product();

		// Create a product with _pos_visibility set to 'pos_only'
		$hidden_product = ProductHelper::create_simple_product();
		update_post_meta( $hidden_product->get_id(), '_pos_visibility', 'pos_only' );

		// Verify that the meta value is set correctly
		$pos_visibility = get_post_meta( $hidden_product->get_id(), '_pos_visibility', true );
		$this->assertEquals( 'pos_only', $pos_visibility, 'Meta value for _pos_visibility not set correctly' );

		// Mimic the main WooCommerce query
		$query_args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1, // Get all products for testing
		);

		$query = new WP_Query( $query_args );
		WC()->query->product_query( $query );
		$queried_ids = wp_list_pluck( $query->posts, 'ID' );

		// Assert that the visible product is in the query
		$this->assertContains( $visible_product->get_id(), $queried_ids );

		// Assert that the hidden product is not in the query
		$this->assertNotContains( $hidden_product->get_id(), $queried_ids );
	}
}
