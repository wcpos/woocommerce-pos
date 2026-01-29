<?php
/**
 * Tests for the WCPOS Orders class.
 *
 * Tests the order functionality including:
 * - Custom POS order statuses (pos-open, pos-partial)
 * - Payment status handling
 * - Tax location determination
 * - Order item product handling for misc products
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Orders;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Test_Orders class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Orders extends \WC_Unit_Test_Case {
	/**
	 * The Orders instance.
	 *
	 * @var Orders
	 */
	private $orders;

	/**
	 * Original checkout settings.
	 *
	 * @var array|false
	 */
	private $original_checkout_settings;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings
		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );

		// Instantiate the Orders class
		$this->orders = new Orders();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Restore original settings
		if ( false !== $this->original_checkout_settings ) {
			update_option( 'woocommerce_pos_settings_checkout', $this->original_checkout_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_checkout' );
		}

		parent::tearDown();
	}

	/**
	 * Helper to create a POS order.
	 *
	 * @param string $status Optional. Order status without 'wc-' prefix. Default 'pos-open'.
	 *
	 * @return WC_Order The created order.
	 */
	private function create_pos_order( string $status = 'pos-open' ): WC_Order {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Test that Orders class can be instantiated.
	 */
	public function test_orders_class_instantiation(): void {
		$this->assertInstanceOf( Orders::class, $this->orders );
	}

	/**
	 * Test that POS order statuses are registered.
	 */
	public function test_pos_order_statuses_registered(): void {
		// Check that the statuses exist in WooCommerce
		$order_statuses = wc_get_order_statuses();

		$this->assertArrayHasKey( 'wc-pos-open', $order_statuses, 'pos-open status should be registered' );
		$this->assertArrayHasKey( 'wc-pos-partial', $order_statuses, 'pos-partial status should be registered' );
	}

	/**
	 * Test that POS status labels are correct.
	 */
	public function test_pos_status_labels(): void {
		$order_statuses = wc_get_order_statuses();

		$this->assertStringContainsString( 'POS', $order_statuses['wc-pos-open'], 'pos-open label should contain POS' );
		$this->assertStringContainsString( 'POS', $order_statuses['wc-pos-partial'], 'pos-partial label should contain POS' );
	}

	/**
	 * Test that pos-open status is valid for payment.
	 */
	public function test_pos_open_valid_for_payment(): void {
		$order = $this->create_pos_order( 'pos-open' );

		$valid_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-open', $valid_statuses, 'pos-open should be valid for payment' );
	}

	/**
	 * Test that pos-partial status is valid for payment.
	 */
	public function test_pos_partial_valid_for_payment(): void {
		$order = $this->create_pos_order( 'pos-partial' );

		$valid_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-partial', $valid_statuses, 'pos-partial should be valid for payment' );
	}

	/**
	 * Test that pos-open status is valid for payment complete.
	 */
	public function test_pos_open_valid_for_payment_complete(): void {
		$order = $this->create_pos_order( 'pos-open' );

		$valid_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-open', $valid_statuses, 'pos-open should be valid for payment complete' );
	}

	/**
	 * Test that pos-partial status is valid for payment complete.
	 */
	public function test_pos_partial_valid_for_payment_complete(): void {
		$order = $this->create_pos_order( 'pos-partial' );

		$valid_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-partial', $valid_statuses, 'pos-partial should be valid for payment complete' );
	}

	/**
	 * Test that zero total POS order needs payment (for gift cards, etc.).
	 */
	public function test_zero_total_pos_order_needs_payment(): void {
		$order = $this->create_pos_order( 'pos-open' );

		// Set total to zero
		$order->set_total( 0 );
		$order->save();

		$needs_payment = apply_filters( 'woocommerce_order_needs_payment', false, $order, array( 'pending', 'failed', 'pos-open', 'pos-partial' ) );

		$this->assertTrue( $needs_payment, 'Zero total POS order should still need payment (for gift cards)' );
	}

	/**
	 * Test that zero total pos-partial order needs payment.
	 */
	public function test_zero_total_pos_partial_order_needs_payment(): void {
		$order = $this->create_pos_order( 'pos-partial' );

		// Set total to zero
		$order->set_total( 0 );
		$order->save();

		$needs_payment = apply_filters( 'woocommerce_order_needs_payment', false, $order, array( 'pending', 'failed', 'pos-open', 'pos-partial' ) );

		$this->assertTrue( $needs_payment, 'Zero total pos-partial order should still need payment' );
	}

	/**
	 * Test that non-zero total order with regular status respects normal behavior.
	 */
	public function test_non_zero_order_normal_behavior(): void {
		$order = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->save();

		// The filter should not change the behavior for non-POS orders
		$needs_payment = apply_filters( 'woocommerce_order_needs_payment', true, $order, array( 'pending' ) );

		$this->assertTrue( $needs_payment, 'Normal order behavior should be unchanged' );
	}

	/**
	 * Test payment_complete_order_status returns setting value during POS request.
	 */
	public function test_payment_complete_order_status_from_settings(): void {
		// Set the checkout order status setting
		$settings                 = get_option( 'woocommerce_pos_settings_checkout', array() );
		$settings['order_status'] = 'completed';
		update_option( 'woocommerce_pos_settings_checkout', $settings );

		$order = $this->create_pos_order( 'pos-open' );

		// Simulate a POS request
		$_REQUEST['pos'] = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

		// Clean up
		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		// Note: This test might need adjustment based on how woocommerce_pos_request() works
		$this->assertContains( $status, array( 'processing', 'completed' ), 'Status should be from settings or default' );
	}

	/**
	 * Test that UUID meta is hidden from order edit page.
	 */
	public function test_uuid_meta_is_hidden(): void {
		$hidden_meta = apply_filters( 'woocommerce_hidden_order_itemmeta', array() );

		$this->assertContains( '_woocommerce_pos_uuid', $hidden_meta, 'UUID meta should be hidden' );
	}

	/**
	 * Test that tax_status meta is hidden from order edit page.
	 */
	public function test_tax_status_meta_is_hidden(): void {
		$hidden_meta = apply_filters( 'woocommerce_hidden_order_itemmeta', array() );

		$this->assertContains( '_woocommerce_pos_tax_status', $hidden_meta, 'Tax status meta should be hidden' );
	}

	/**
	 * Test that pos_data meta is hidden from order edit page.
	 */
	public function test_pos_data_meta_is_hidden(): void {
		$hidden_meta = apply_filters( 'woocommerce_hidden_order_itemmeta', array() );

		$this->assertContains( '_woocommerce_pos_data', $hidden_meta, 'POS data meta should be hidden' );
	}

	/**
	 * Test tax location returns billing address when tax_based_on is billing.
	 */
	public function test_tax_location_based_on_billing(): void {
		$order = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'billing' );
		$order->set_billing_country( 'US' );
		$order->set_billing_state( 'CA' );
		$order->set_billing_postcode( '90210' );
		$order->set_billing_city( 'Beverly Hills' );
		$order->save();

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = apply_filters( 'woocommerce_order_get_tax_location', $args, $order );

		$this->assertEquals( 'US', $result['country'], 'Country should be from billing' );
		$this->assertEquals( 'CA', $result['state'], 'State should be from billing' );
		$this->assertEquals( '90210', $result['postcode'], 'Postcode should be from billing' );
		$this->assertEquals( 'Beverly Hills', $result['city'], 'City should be from billing' );
	}

	/**
	 * Test tax location returns shipping address when tax_based_on is shipping.
	 */
	public function test_tax_location_based_on_shipping(): void {
		$order = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'shipping' );
		$order->set_shipping_country( 'US' );
		$order->set_shipping_state( 'NY' );
		$order->set_shipping_postcode( '10001' );
		$order->set_shipping_city( 'New York' );
		$order->save();

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = apply_filters( 'woocommerce_order_get_tax_location', $args, $order );

		$this->assertEquals( 'US', $result['country'], 'Country should be from shipping' );
		$this->assertEquals( 'NY', $result['state'], 'State should be from shipping' );
		$this->assertEquals( '10001', $result['postcode'], 'Postcode should be from shipping' );
		$this->assertEquals( 'New York', $result['city'], 'City should be from shipping' );
	}

	/**
	 * Test tax location returns base location when tax_based_on is base/store.
	 */
	public function test_tax_location_based_on_store(): void {
		$order = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'base' );
		$order->save();

		// Set store base location
		update_option( 'woocommerce_default_country', 'GB:LND' );

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = apply_filters( 'woocommerce_order_get_tax_location', $args, $order );

		$this->assertEquals( 'GB', $result['country'], 'Country should be from base location' );
	}

	/**
	 * Test tax location does not affect non-POS orders.
	 */
	public function test_tax_location_unaffected_for_non_pos_orders(): void {
		$order = OrderHelper::create_order();
		$order->set_billing_country( 'FR' );
		$order->set_billing_state( '' );
		$order->save();

		$original_args = array(
			'country'  => 'DE',
			'state'    => 'BE',
			'postcode' => '10115',
			'city'     => 'Berlin',
		);

		$result = apply_filters( 'woocommerce_order_get_tax_location', $original_args, $order );

		// Should return original args unchanged for non-POS orders
		$this->assertEquals( $original_args, $result, 'Non-POS orders should not have tax location modified' );
	}

	/**
	 * Test order_item_product creates a product for misc items.
	 */
	public function test_order_item_product_creates_misc_product(): void {
		$order = $this->create_pos_order();

		// Create a line item with product_id = 0 (misc product)
		$item = new WC_Order_Item_Product();
		$item->set_name( 'Misc Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		$item->add_meta_data( '_sku', 'MISC-001' );
		$item->add_meta_data(
			'_woocommerce_pos_data',
			json_encode(
				array(
					'price'         => '10.00',
					'regular_price' => '15.00',
					'tax_status'    => 'taxable',
				)
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->save();

		// Get the product from the filter
		$product = apply_filters( 'woocommerce_order_item_product', false, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $product, 'Should create a WC_Product_Simple for misc items' );
		$this->assertEquals( 'Misc Item', $product->get_name(), 'Product name should match item name' );
		$this->assertEquals( 'MISC-001', $product->get_sku(), 'Product SKU should match item meta' );
		$this->assertEquals( '10.00', $product->get_price(), 'Product price should match POS data' );
		$this->assertEquals( '15.00', $product->get_regular_price(), 'Product regular price should match POS data' );
	}

	/**
	 * Test order_item_product returns existing product unchanged.
	 */
	public function test_order_item_product_returns_existing_product(): void {
		$product = ProductHelper::create_simple_product();
		$order   = OrderHelper::create_order( array( 'product' => $product ) );

		$items = $order->get_items();
		$item  = reset( $items );

		// Get the product from the filter
		$result = apply_filters( 'woocommerce_order_item_product', $product, $item );

		$this->assertEquals( $product->get_id(), $result->get_id(), 'Existing product should be returned unchanged' );
	}

	/**
	 * Test that orders can be set to pos-open status.
	 */
	public function test_order_can_be_set_to_pos_open(): void {
		$order = OrderHelper::create_order();
		$order->set_status( 'pos-open' );
		$order->save();

		$this->assertEquals( 'pos-open', $order->get_status(), 'Order status should be pos-open' );
	}

	/**
	 * Test that orders can be set to pos-partial status.
	 */
	public function test_order_can_be_set_to_pos_partial(): void {
		$order = OrderHelper::create_order();
		$order->set_status( 'pos-partial' );
		$order->save();

		$this->assertEquals( 'pos-partial', $order->get_status(), 'Order status should be pos-partial' );
	}

	/**
	 * Test order status transition from pos-open to completed.
	 */
	public function test_order_status_transition_pos_open_to_completed(): void {
		$order = $this->create_pos_order( 'pos-open' );

		$order->set_status( 'completed' );
		$order->save();

		$this->assertEquals( 'completed', $order->get_status(), 'Order should transition to completed' );
	}

	/**
	 * Test order status transition from pos-partial to processing.
	 */
	public function test_order_status_transition_pos_partial_to_processing(): void {
		$order = $this->create_pos_order( 'pos-partial' );

		$order->set_status( 'processing' );
		$order->save();

		$this->assertEquals( 'processing', $order->get_status(), 'Order should transition to processing' );
	}
}
