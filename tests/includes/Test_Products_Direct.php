<?php
/**
 * Direct tests for the WCPOS Products class.
 *
 * Tests the Products class with direct method calls for line coverage.
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Simple;
use WC_Product_Variation;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;
use WP_REST_Request;

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

		delete_option( 'woocommerce_pos_settings_visibility' );

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
	 * A storefront product query excludes the POS Only ids.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_hide_pos_only_products_storefront_query_excludes_pos_only_ids(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$product = ProductHelper::create_simple_product();
		$this->set_pos_only_product_ids( array( $product->get_id() ) );
		$products = new Products();
		$query    = new WP_Query();
		$query->set( 'post_type', 'product' );

		// Act.
		$products->hide_pos_only_products( $query );

		// Assert.
		$this->assertSame( array( $product->get_id() ), $query->get( 'post__not_in' ) );
	}

	/**
	 * REST routes and whether the POS Only exclusion applies while they are dispatched.
	 *
	 * The WooCommerce admin namespaces are the merchant operating the shop; the Store API
	 * and the WooCommerce REST API are shopper-facing and stay filtered. A namespace only
	 * counts when it leads the route.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function rest_route_provider(): array {
		return array(
			'wc-analytics report'      => array( '/wc-analytics/reports/products', false ),
			'wc-analytics index'       => array( '/wc-analytics', false ),
			'wc-admin, mixed case'     => array( '/WC-Admin/options', false ),
			'Store API'                => array( '/wc/store/v1/products', true ),
			'WooCommerce REST API'     => array( '/wc/v3/products', true ),
			'namespace not leading'    => array( '/wc/store/v1/wc-analytics/products', true ),
			'namespace as a prefix'    => array( '/wc-analytics-lookalike/products', true ),
		);
	}

	/**
	 * WooCommerce Analytics resolves a category filter with wc_get_products() during a
	 * wc-analytics request. The merchant is reporting on the catalogue, not shopping it,
	 * so the POS Only exclusion must not apply there or the category reads "No data".
	 *
	 * @dataProvider rest_route_provider
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 * @covers \WCPOS\WooCommercePOS\Products::record_rest_route
	 *
	 * @param string $route         The REST route being dispatched.
	 * @param bool   $expect_hidden Whether the POS Only product is excluded from the query.
	 */
	public function test_hide_pos_only_products_by_rest_route( string $route, bool $expect_hidden ): void {
		// Arrange.
		$this->enable_pos_only_products();
		$product = ProductHelper::create_simple_product();
		$this->set_pos_only_product_ids( array( $product->get_id() ) );
		$products = new Products();
		$products->record_rest_route( null, rest_get_server(), new WP_REST_Request( 'GET', $route ) );
		$query = new WP_Query();
		$query->set( 'post_type', 'product' );

		// Act.
		$products->hide_pos_only_products( $query );

		// Assert.
		$expected = $expect_hidden ? array( $product->get_id() ) : '';
		$this->assertSame( $expected, $query->get( 'post__not_in' ) );
	}

	/**
	 * A site can switch the exclusion off for a context of its own through the filter.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_hide_pos_only_products_filter_false_keeps_pos_only_products(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$product = ProductHelper::create_simple_product();
		$this->set_pos_only_product_ids( array( $product->get_id() ) );
		$products = new Products();
		$query    = new WP_Query();
		$query->set( 'post_type', 'product' );
		$seen = array();
		add_filter(
			'woocommerce_pos_hide_pos_only_products',
			function ( $hide, $filtered_query ) use ( &$seen ) {
				$seen = array( $hide, $filtered_query );
				return false;
			},
			10,
			2
		);

		// Act.
		$products->hide_pos_only_products( $query );

		// Assert.
		$this->assertEmpty( $query->get( 'post__not_in' ) );
		$this->assertTrue( $seen[0], 'The filter receives the default decision.' );
		$this->assertSame( $query, $seen[1], 'The filter receives the query being decided.' );
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

		$products->store_api_prevent_pos_only_add_to_cart( $product );

		$this->assertTrue( $product->is_purchasable() );
	}

	/**
	 * Direct test: save_decimal_quantities preserves a manual "out of stock"
	 * status when stock management is disabled.
	 *
	 * WooCommerce respects the manually chosen stock status when "Manage
	 * stock" is unchecked; the decimal-quantity hook must not override it.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_save_decimal_quantities_no_stock_management_preserves_outofstock(): void {
		$this->enable_decimal_quantities();

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertSame( 'outofstock', $product->get_stock_status() );
	}

	/**
	 * Direct test: save_decimal_quantities preserves a manual "on backorder"
	 * status when stock management is disabled.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_save_decimal_quantities_no_stock_management_preserves_onbackorder(): void {
		$this->enable_decimal_quantities();

		$products = new Products();
		$product  = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'onbackorder' );
		$product->save();

		// Call method directly
		$products->save_decimal_quantities( $product );

		$this->assertSame( 'onbackorder', $product->get_stock_status() );
	}

	/**
	 * Integration test: a full product save with the decimal-quantity hook
	 * registered (the wp-admin "Update" flow) must not revert a manual
	 * "out of stock" status to "in stock" when stock management is disabled.
	 *
	 * Regression test for the reported bug: decimal quantities enabled +
	 * "Manage stock" unchecked + status "Out of stock" reverted to
	 * "In stock" on save.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_product_save_no_stock_management_persists_outofstock(): void {
		$this->enable_decimal_quantities();

		// Registers save_decimal_quantities on woocommerce_before_product_object_save.
		new Products();

		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$persisted = wc_get_product( $product->get_id() );

		$this->assertSame( 'outofstock', $persisted->get_stock_status() );
	}

	/**
	 * Integration test: same as above for a variation without stock management.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_variation_save_no_stock_management_persists_outofstock(): void {
		$this->enable_decimal_quantities();

		// Registers save_decimal_quantities on woocommerce_before_product_object_save.
		new Products();

		$variation = $this->create_test_variation();
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'outofstock' );

		// Guard against fixture drift: a stock-managed PARENT makes
		// get_manage_stock() return 'parent' and this test would silently
		// exercise the managed branch instead.
		$this->assertFalse( $variation->get_manage_stock() );

		$variation->save();

		$persisted = wc_get_product( $variation->get_id() );

		$this->assertSame( 'outofstock', $persisted->get_stock_status() );
	}

	/**
	 * Integration test: the decimal-quantity feature itself still works
	 * through a full save — a managed product with fractional stock above
	 * zero persists as "in stock".
	 *
	 * Feature smoke test: with the floatval stock filter active, WC core's
	 * validate_props() also resolves 0.5 to instock, so this does not pin
	 * the hook alone — the direct tests above do that.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::save_decimal_quantities
	 */
	public function test_product_save_managed_decimal_stock_persists_instock(): void {
		$this->enable_decimal_quantities();

		// Registers save_decimal_quantities on woocommerce_before_product_object_save.
		new Products();

		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0.5 );
		$product->save();

		$persisted = wc_get_product( $product->get_id() );

		$this->assertSame( 'instock', $persisted->get_stock_status() );
		$this->assertEquals( 0.5, $persisted->get_stock_quantity() );
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
	 * Store a POS Only product id list the way the settings screen does.
	 *
	 * @param int[] $ids Product ids.
	 */
	private function set_pos_only_product_ids( array $ids ): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products'   => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array() ),
						'online_only' => array( 'ids' => array() ),
					),
				),
			)
		);
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
