<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_REST_Unit_Test_Case;
use WP_Query;
use WC_Query;
use WCPOS\WooCommercePOS\Products;
use WC_Product_Variation;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products extends WC_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 *
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
		$queried_ids = wp_list_pluck( $query->get_posts(), 'ID' );

		// Assert that the visible product is in the query
		$this->assertContains( $visible_product->get_id(), $queried_ids );

		// Assert that the hidden product is not in the query
		$this->assertNotContains( $hidden_product->get_id(), $queried_ids );
	}

	/**
	 *
	 */
	public function test_pos_only_variations() {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// create variations
		$product = ProductHelper::create_variation_product();
		$variation_3 = new WC_Product_Variation();
		$variation_3->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => 'DUMMY SKU VARIABLE MEDIUM',
				'regular_price' => 10,
			)
		);
		$variation_3->set_attributes( array( 'pa_size' => 'medium' ) );
		$variation_3->save();

		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_pos_visibility', 'pos_only' );
		update_post_meta( $variation_ids[1], '_pos_visibility', 'online_only' );

		// Mimic the main WooCommerce query for product variations
		$query_args = array(
			'post_type'     => 'product_variation',
			'post_status'   => 'publish',
			'posts_per_page' => -1, // Get all variations for testing
			'post_parent'   => $product->get_id(), // Ensure variations of the specific product are fetched
		);

		$query = new WP_Query( $query_args );
		WC()->query->product_query( $query );
		$queried_variation_ids = wp_list_pluck( $query->get_posts(), 'ID' );

		// Assert that the variation with '_pos_visibility' set to 'pos_only' is NOT in the query
		$this->assertNotContains( $variation_ids[0], $queried_variation_ids );

		// Assert that the variation with '_pos_visibility' set to 'online_only' IS in the query
		$this->assertContains( $variation_ids[1], $queried_variation_ids );

		// Assert that the variation without '_pos_visibility' set is in the query
		$this->assertContains( $variation_ids[2], $queried_variation_ids );
	}

	/**
	 *
	 */
	public function test_pos_only_products_via_store_api() {
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

		// Make WC REST request
		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
		$request = new \WP_REST_Request( 'GET', '/wc/v3/products' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $visible_product->get_id(), $data[0]['id'] );
	}
}
