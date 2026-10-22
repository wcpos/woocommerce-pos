<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Install;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Products;
use WP_Query;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products extends WP_UnitTestCase {
	public function setup(): void {
		parent::setup();

		WC_Install::create_pages();
		$shop_page_id = wc_get_page_id( 'shop' );
		$this->assertTrue(
			$shop_page_id && 'publish' == get_post_status( $shop_page_id ),
			'The WooCommerce "shop" page is not set up.'
		);
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_pos_only_products(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// Create a visible product
		$visible_product = ProductHelper::create_simple_product();

		// Create a product with _pos_visibility set to 'pos_only'
		$hidden_product = ProductHelper::create_simple_product();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $hidden_product->get_id() ),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		// Mimic the main WooCommerce query
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // Get all products for testing
		);

		$this->go_to( wc_get_page_id( 'shop' ) );

		$query = new WP_Query( $query_args );
		WC()->query->product_query( $query );
		$queried_ids = wp_list_pluck( $query->get_posts(), 'ID' );

		// Assert that the visible product is in the query
		$this->assertContains( $visible_product->get_id(), $queried_ids );

		// Assert that the hidden product is not in the query
		$this->assertNotContains( $hidden_product->get_id(), $queried_ids );
	}

	public function test_pos_only_variations(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// create variations
		$product     = ProductHelper::create_variation_product();
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

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $variation_ids[0] ),
						),
						'online_only' => array(
							'ids' => array( $variation_ids[1] ),
						),
					),
				),
			)
		);

		// Mimic the main WooCommerce query for product variations
		$query_args = array(
			'post_type'      => 'product_variation',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // Get all variations for testing
			'post_parent'    => $product->get_id(), // Ensure variations of the specific product are fetched
		);

		$this->go_to( wc_get_page_id( 'shop' ) );

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
	 * WordPress ignores post__not_in when post__in is set; the SQL guard must still exclude POS Only ids.
	 */
	public function test_pos_only_products_post_in_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'post__in'  => array( $visible->get_id(), $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * WooCommerce maps wc_get_products() include to WordPress post__in.
	 */
	public function test_pos_only_products_wc_include_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = wc_get_products(
			array(
				'include' => array( $visible->get_id(), $hidden->get_id() ),
				'return'  => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $ids );
	}

	/**
	 * WooCommerce related products, upsells and cross-sells use WC_Product::is_visible().
	 */
	public function test_pos_only_products_shop_visibility_hides_only_pos_only_product(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$hidden_visible = wc_get_product( $hidden->get_id() )->is_visible();
		$normal_visible = wc_get_product( $visible->get_id() )->is_visible();

		// Assert.
		$this->assertFalse( $hidden_visible );
		$this->assertTrue( $normal_visible );
	}

	/**
	 * WooCommerce variation is_visible() uses the product visibility hook, not variation_is_visible().
	 */
	public function test_pos_only_variations_shop_visibility_hides_only_pos_only_variation(): void {
		// Arrange.
		$product = ProductHelper::create_variation_product();
		$ids     = $product->get_children();
		$this->configure_storefront_visibility( array(), array( $ids[0] ) );

		// Act.
		$hidden_visible  = wc_get_product( $ids[0] )->is_visible();
		$sibling_visible = wc_get_product( $ids[1] )->is_visible();

		// Assert.
		$this->assertFalse( $hidden_visible );
		$this->assertTrue( $sibling_visible );
	}

	/**
	 * get_posts() sets suppress_filters, so posts_where never runs; the id list itself must be trimmed.
	 */
	public function test_pos_only_products_get_posts_include_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = get_posts(
			array(
				'post_type' => 'product',
				'include'   => array( $visible->get_id(), $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $ids );
	}

	/**
	 * An id list made of POS Only products only must return nothing, not every product.
	 */
	public function test_pos_only_products_get_posts_include_all_hidden_returns_nothing(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = get_posts(
			array(
				'post_type' => 'product',
				'include'   => array( $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array(), $ids );
	}

	/**
	 * Enable the registered storefront hooks and store visibility settings for these fixtures.
	 */
	private function configure_storefront_visibility( array $product_ids, array $variation_ids = array() ): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products();
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products'   => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $product_ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $variation_ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
			)
		);
		$this->go_to( wc_get_page_id( 'shop' ) );
	}
}
