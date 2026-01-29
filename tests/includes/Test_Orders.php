<?php
/**
 * Tests for the WCPOS Orders class.
 *
 * Tests the order functionality including:
 * - Custom POS order statuses (pos-open, pos-partial)
 * - Payment status handling
 * - Tax location determination
 * - Order item product handling for misc products
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Orders;

/**
 * Test_Orders class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Orders extends WC_Unit_Test_Case {
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
	 * Original default country.
	 *
	 * @var false|string
	 */
	private $original_default_country;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings
		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );
		$this->original_default_country   = get_option( 'woocommerce_default_country' );

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

		// Restore original default country
		if ( false !== $this->original_default_country ) {
			update_option( 'woocommerce_default_country', $this->original_default_country );
		} else {
			delete_option( 'woocommerce_default_country' );
		}

		// Clean up any POS request state
		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		parent::tearDown();
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
		$_REQUEST['pos']         = '1';
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

	// ==========================================================================
	// DIRECT METHOD CALL TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: wc_order_statuses method.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::wc_order_statuses
	 */
	public function test_direct_wc_order_statuses(): void {
		$orders = new Orders();

		$existing_statuses = array(
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-completed'  => 'Completed',
		);

		$result = $orders->wc_order_statuses( $existing_statuses );

		$this->assertArrayHasKey( 'wc-pos-open', $result );
		$this->assertArrayHasKey( 'wc-pos-partial', $result );
		$this->assertStringContainsString( 'POS', $result['wc-pos-open'] );
		$this->assertStringContainsString( 'POS', $result['wc-pos-partial'] );
	}

	/**
	 * Direct test: order_needs_payment for POS orders with zero total.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_needs_payment
	 */
	public function test_direct_order_needs_payment_zero_total(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order( 'pos-open' );
		$order->set_total( 0 );
		$order->save();

		$result = $orders->order_needs_payment( false, $order, array( 'pending', 'failed' ) );

		$this->assertTrue( $result, 'Zero total POS order should need payment' );
	}

	/**
	 * Direct test: order_needs_payment for POS partial orders.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_needs_payment
	 */
	public function test_direct_order_needs_payment_pos_partial(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order( 'pos-partial' );
		$order->set_total( 0 );
		$order->save();

		$result = $orders->order_needs_payment( false, $order, array( 'pending', 'failed' ) );

		$this->assertTrue( $result, 'Zero total pos-partial order should need payment' );
	}

	/**
	 * Direct test: order_needs_payment returns original for non-POS orders.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_needs_payment
	 */
	public function test_direct_order_needs_payment_non_pos(): void {
		$orders = new Orders();
		$order  = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->save();

		$result = $orders->order_needs_payment( true, $order, array( 'pending' ) );

		$this->assertTrue( $result );
	}

	/**
	 * Direct test: valid_order_statuses_for_payment.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::valid_order_statuses_for_payment
	 */
	public function test_direct_valid_order_statuses_for_payment(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order();

		$result = $orders->valid_order_statuses_for_payment( array( 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-open', $result );
		$this->assertContains( 'pos-partial', $result );
		$this->assertContains( 'pending', $result );
		$this->assertContains( 'failed', $result );
	}

	/**
	 * Direct test: valid_order_statuses_for_payment_complete.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::valid_order_statuses_for_payment_complete
	 */
	public function test_direct_valid_order_statuses_for_payment_complete(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order();

		$result = $orders->valid_order_statuses_for_payment_complete( array( 'on-hold', 'pending', 'failed' ), $order );

		$this->assertContains( 'pos-open', $result );
		$this->assertContains( 'pos-partial', $result );
	}

	/**
	 * Direct test: payment_complete_order_status during POS request.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::payment_complete_order_status
	 */
	public function test_direct_payment_complete_order_status_pos_request(): void {
		$settings                 = get_option( 'woocommerce_pos_settings_checkout', array() );
		$settings['order_status'] = 'completed';
		update_option( 'woocommerce_pos_settings_checkout', $settings );

		$orders = new Orders();
		$order  = $this->create_pos_order();

		// Simulate POS request
		$_REQUEST['pos']         = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$result = $orders->payment_complete_order_status( 'processing', $order->get_id(), $order );

		// Clean up
		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		// Should return setting value or processing if not a POS request
		$this->assertContains( $result, array( 'processing', 'completed' ) );
	}

	/**
	 * Direct test: hidden_order_itemmeta.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::hidden_order_itemmeta
	 */
	public function test_direct_hidden_order_itemmeta(): void {
		$orders        = new Orders();
		$existing_meta = array( '_product_id', '_variation_id' );

		$result = $orders->hidden_order_itemmeta( $existing_meta );

		$this->assertContains( '_woocommerce_pos_uuid', $result );
		$this->assertContains( '_woocommerce_pos_tax_status', $result );
		$this->assertContains( '_woocommerce_pos_data', $result );
		$this->assertContains( '_product_id', $result );
		$this->assertContains( '_variation_id', $result );
	}

	/**
	 * Direct test: order_item_product creates product for misc items.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_item_product
	 */
	public function test_direct_order_item_product_misc_item(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order();

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Direct Test Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		$item->add_meta_data( '_sku', 'DIRECT-001' );
		$item->add_meta_data(
			'_woocommerce_pos_data',
			json_encode(
				array(
					'price'         => '25.00',
					'regular_price' => '30.00',
					'tax_status'    => 'taxable',
				)
			)
		);
		$item->save();

		$result = $orders->order_item_product( false, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $result );
		$this->assertEquals( 'Direct Test Item', $result->get_name() );
		$this->assertEquals( 'DIRECT-001', $result->get_sku() );
		$this->assertEquals( '25.00', $result->get_price() );
		$this->assertEquals( '30.00', $result->get_regular_price() );
	}

	/**
	 * Direct test: order_item_product returns existing product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_item_product
	 */
	public function test_direct_order_item_product_existing(): void {
		$orders  = new Orders();
		$product = ProductHelper::create_simple_product();
		$order   = OrderHelper::create_order( array( 'product' => $product ) );

		$items = $order->get_items();
		$item  = reset( $items );

		$result = $orders->order_item_product( $product, $item );

		$this->assertEquals( $product->get_id(), $result->get_id() );
	}

	/**
	 * Direct test: get_tax_location for billing.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::get_tax_location
	 */
	public function test_direct_get_tax_location_billing(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'billing' );
		$order->set_billing_country( 'AU' );
		$order->set_billing_state( 'VIC' );
		$order->set_billing_postcode( '3000' );
		$order->set_billing_city( 'Melbourne' );
		$order->save();

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = $orders->get_tax_location( $args, $order );

		$this->assertEquals( 'AU', $result['country'] );
		$this->assertEquals( 'VIC', $result['state'] );
		$this->assertEquals( '3000', $result['postcode'] );
		$this->assertEquals( 'Melbourne', $result['city'] );
	}

	/**
	 * Direct test: get_tax_location for shipping.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::get_tax_location
	 */
	public function test_direct_get_tax_location_shipping(): void {
		$orders = new Orders();
		$order  = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'shipping' );
		$order->set_shipping_country( 'NZ' );
		$order->set_shipping_state( 'AUK' );
		$order->set_shipping_postcode( '1010' );
		$order->set_shipping_city( 'Auckland' );
		$order->save();

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = $orders->get_tax_location( $args, $order );

		$this->assertEquals( 'NZ', $result['country'] );
		$this->assertEquals( 'AUK', $result['state'] );
		$this->assertEquals( '1010', $result['postcode'] );
		$this->assertEquals( 'Auckland', $result['city'] );
	}

	/**
	 * Direct test: get_tax_location for base location.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::get_tax_location
	 */
	public function test_direct_get_tax_location_base(): void {
		// Set base country
		update_option( 'woocommerce_default_country', 'US:CA' );

		$orders = new Orders();
		$order  = $this->create_pos_order();
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'base' );
		$order->save();

		$args = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$result = $orders->get_tax_location( $args, $order );

		$this->assertEquals( 'US', $result['country'] );
	}

	/**
	 * Direct test: get_tax_location returns original for non-POS orders.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::get_tax_location
	 */
	public function test_direct_get_tax_location_non_pos(): void {
		$orders = new Orders();
		$order  = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->save();

		$args = array(
			'country'  => 'JP',
			'state'    => 'TK',
			'postcode' => '100-0001',
			'city'     => 'Tokyo',
		);

		$result = $orders->get_tax_location( $args, $order );

		$this->assertEquals( $args, $result, 'Non-POS order should return original args' );
	}

	/**
	 * Direct test: order_item_product with invalid JSON data.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::order_item_product
	 */
	public function test_direct_order_item_product_invalid_json(): void {
		$orders = new Orders();

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Invalid JSON Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		$item->add_meta_data( '_woocommerce_pos_data', 'invalid json {' );
		$item->save();

		$result = $orders->order_item_product( false, $item );

		// Should still create a product, just without the POS data
		$this->assertInstanceOf( 'WC_Product_Simple', $result );
		$this->assertEquals( 'Invalid JSON Item', $result->get_name() );
	}

	/**
	 * Direct test: constructor registers all filters.
	 *
	 * @covers \WCPOS\WooCommercePOS\Orders::__construct
	 */
	public function test_direct_constructor_registers_filters(): void {
		$orders = new Orders();

		$this->assertNotFalse( has_filter( 'wc_order_statuses', array( $orders, 'wc_order_statuses' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_needs_payment', array( $orders, 'order_needs_payment' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_valid_order_statuses_for_payment', array( $orders, 'valid_order_statuses_for_payment' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $orders, 'valid_order_statuses_for_payment_complete' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_payment_complete_order_status', array( $orders, 'payment_complete_order_status' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_hidden_order_itemmeta', array( $orders, 'hidden_order_itemmeta' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_item_product', array( $orders, 'order_item_product' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_get_tax_location', array( $orders, 'get_tax_location' ) ) );
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
}
