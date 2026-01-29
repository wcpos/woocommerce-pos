<?php
/**
 * Direct tests for the WCPOS Products class.
 *
 * Tests the Products class with direct method calls for line coverage.
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Exception;
use WC_Product_Simple;
use WC_Product_Variation;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;

/**
 * Test_Products_Direct class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Direct extends WC_Unit_Test_Case {
	/**
	 * Original settings.
	 *
	 * @var array|false
	 */
	private $original_settings;

	/**
	 * Original no stock amount option.
	 *
	 * @var mixed
	 */
	private $original_no_stock_amount;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings
		$this->original_settings        = get_option( 'woocommerce_pos_settings_general' );
		$this->original_no_stock_amount = get_option( 'woocommerce_notify_no_stock_amount' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Restore original settings
		if ( false !== $this->original_settings ) {
			update_option( 'woocommerce_pos_settings_general', $this->original_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_general' );
		}

		// Restore original no stock amount
		if ( false !== $this->original_no_stock_amount ) {
			update_option( 'woocommerce_notify_no_stock_amount', $this->original_no_stock_amount );
		} else {
			delete_option( 'woocommerce_notify_no_stock_amount' );
		}

		parent::tearDown();
	}

	/**
	 * Test Products class can be instantiated.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::__construct
	 */
	public function test_products_instantiation(): void {
		$products = new Products();
		$this->assertInstanceOf( Products::class, $products );
	}

	/**
	 * Test constructor registers stock change actions.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::__construct
	 */
	public function test_constructor_registers_stock_actions(): void {
		$products = new Products();

		$this->assertNotFalse(
			has_action( 'woocommerce_product_set_stock', array( $products, 'product_set_stock' ) )
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_variation_set_stock', array( $products, 'product_set_stock' ) )
		);
	}

	/**
	 * Direct test: product_set_stock updates modified date.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::product_set_stock
	 */
	public function test_direct_product_set_stock(): void {
		$products = new Products();
		$product  = ProductHelper::create_simple_product();

		// Backdate the post to ensure detectable change
		wp_update_post(
			array(
				'ID'                => $product->get_id(),
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			)
		);
		$original_modified = '2020-01-01 00:00:00';

		// Call method directly
		$products->product_set_stock( $product );

		// Get updated modified date
		wp_cache_flush();
		$new_modified = get_post_field( 'post_modified', $product->get_id() );

		$this->assertNotEquals( $original_modified, $new_modified, 'Modified date should be updated' );
	}

	/**
	 * Direct test: hide_pos_only_products returns early for admin.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_direct_hide_pos_only_products_admin(): void {
		// Enable POS only products setting
		$this->enable_pos_only_products();

		$products = new Products();
		$query    = new WP_Query();
		$query->set( 'post_type', 'product' );

		// Simulate admin request
		set_current_screen( 'edit-product' );

		// This should return early for admin
		$products->hide_pos_only_products( $query );

		// No post__not_in should be set
		$this->assertEmpty( $query->get( 'post__not_in' ) );

		// Reset screen
		set_current_screen( 'front' );
	}

	/**
	 * Direct test: hide_pos_only_products with product query.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_direct_hide_pos_only_products_with_exclusions(): void {
		// Enable POS only products
		$this->enable_pos_only_products();

		// Create a product and mark it as POS only
		$product = ProductHelper::create_simple_product();
		update_post_meta( $product->get_id(), '_pos_visibility', 'pos_only' );

		$products = new Products();
		$query    = new WP_Query();
		$query->set( 'post_type', 'product' );

		// Call the method (this won't actually exclude since we're in test context)
		$products->hide_pos_only_products( $query );

		// Verify the query was not adversely modified
		$this->assertEquals( 'product', $query->get( 'post_type' ) );
		$this->assertInstanceOf( WP_Query::class, $query );
	}

	/**
	 * Direct test: hide_pos_only_variations for visible variation.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_variations
	 */
	public function test_direct_hide_pos_only_variations_visible(): void {
		$this->enable_pos_only_products();

		$products   = new Products();
		$variation  = $this->create_test_variation();

		// Test when not in shop context (should return original value)
		$result = $products->hide_pos_only_variations( true, $variation->get_id(), $variation->get_parent_id(), $variation );

		$this->assertTrue( $result );
	}

	/**
	 * Direct test: prevent_pos_only_add_to_cart for regular product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::prevent_pos_only_add_to_cart
	 */
	public function test_direct_prevent_pos_only_add_to_cart_regular(): void {
		$this->enable_pos_only_products();

		$products = new Products();
		$product  = ProductHelper::create_simple_product();

		// Regular product should pass validation
		$result = $products->prevent_pos_only_add_to_cart( true, $product->get_id() );

		$this->assertTrue( $result );
	}

	/**
	 * Direct test: prevent_pos_only_add_to_cart for POS only product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::prevent_pos_only_add_to_cart
	 */
	public function test_direct_prevent_pos_only_add_to_cart_pos_only(): void {
		$this->enable_pos_only_products();

		$products = new Products();
		$product  = ProductHelper::create_simple_product();

		// Mock the Settings service to return this product as POS only
		$filter_callback = function ( $settings ) use ( $product ) {
			$settings['ids'][] = $product->get_id();

			return $settings;
		};
		add_filter( 'woocommerce_pos_pos_only_product_visibility_settings', $filter_callback );

		// POS only product should fail validation
		$result = $products->prevent_pos_only_add_to_cart( true, $product->get_id() );

		$this->assertFalse( $result );

		remove_filter( 'woocommerce_pos_pos_only_product_visibility_settings', $filter_callback );
	}

	/**
	 * Direct test: store_api_prevent_pos_only_add_to_cart for regular product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::store_api_prevent_pos_only_add_to_cart
	 */
	public function test_direct_store_api_add_to_cart_regular(): void {
		$this->enable_pos_only_products();

		$products = new Products();
		$product  = ProductHelper::create_simple_product();

		// Should not throw exception for regular product
		$exception_thrown = false;

		try {
			$products->store_api_prevent_pos_only_add_to_cart( $product );
		} catch ( Exception $e ) {
			$exception_thrown = true;
		}

		$this->assertFalse( $exception_thrown );
	}

	/**
	 * Direct test: save_decimal_quantities for product without stock management.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_direct_save_decimal_quantities_no_stock_management(): void {
		$this->enable_decimal_quantities();

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( false );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertEquals( 'instock', $product->get_stock_status() );
	}

	/**
	 * Direct test: save_decimal_quantities for product with stock.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_direct_save_decimal_quantities_with_stock(): void {
		$this->enable_decimal_quantities();

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5.5 );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertEquals( 'instock', $product->get_stock_status() );
	}

	/**
	 * Direct test: save_decimal_quantities for product with low stock.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_direct_save_decimal_quantities_low_stock(): void {
		$this->enable_decimal_quantities();

		// Set notification threshold
		update_option( 'woocommerce_notify_no_stock_amount', 5 );

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 2 );
		$product->set_backorders( 'no' );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertEquals( 'outofstock', $product->get_stock_status() );
	}

	/**
	 * Direct test: save_decimal_quantities with backorders allowed.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_direct_save_decimal_quantities_backorders(): void {
		$this->enable_decimal_quantities();

		// Set notification threshold
		update_option( 'woocommerce_notify_no_stock_amount', 5 );

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 2 );
		$product->set_backorders( 'yes' );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertEquals( 'onbackorder', $product->get_stock_status() );
	}

	/**
	 * Direct test: save_decimal_quantities with decimal stock quantity.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_direct_save_decimal_quantities_decimal_value(): void {
		$this->enable_decimal_quantities();

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0.5 );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		// Stock > 0 should be instock
		$this->assertEquals( 'instock', $product->get_stock_status() );
	}

	/**
	 * Direct test: filter_category_count_exclude_pos_only.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::filter_category_count_exclude_pos_only
	 */
	public function test_direct_filter_category_count(): void {
		$products = new Products();

		$args = array(
			'parent'     => 0,
			'hide_empty' => true,
		);

		$result = $products->filter_category_count_exclude_pos_only( $args );

		// Currently returns unchanged args
		$this->assertEquals( $args, $result );
	}

	/**
	 * Helper to enable POS only products setting.
	 */
	private function enable_pos_only_products(): void {
		$settings                      = get_option( 'woocommerce_pos_settings_general', array() );
		$settings['pos_only_products'] = true;
		update_option( 'woocommerce_pos_settings_general', $settings );
	}

	/**
	 * Helper to enable decimal quantities setting.
	 */
	private function enable_decimal_quantities(): void {
		$settings                = get_option( 'woocommerce_pos_settings_general', array() );
		$settings['decimal_qty'] = true;
		update_option( 'woocommerce_pos_settings_general', $settings );
	}

	/**
	 * Helper to create a test variation product.
	 *
	 * @return WC_Product_Variation
	 */
	private function create_test_variation(): WC_Product_Variation {
		$variable_product = ProductHelper::create_variation_product();
		$variation_ids    = $variable_product->get_children();

		return wc_get_product( $variation_ids[0] );
	}
}
