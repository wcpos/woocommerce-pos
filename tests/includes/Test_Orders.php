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
use WC_Product_Simple;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Orders;
use WCPOS\WooCommercePOS\Tests\Helpers\POSLineItemHelper;

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
	 * Original payment gateways settings.
	 *
	 * @var array|false
	 */
	private $original_payment_gateways_settings;

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
		$this->original_checkout_settings          = get_option( 'woocommerce_pos_settings_checkout' );
		$this->original_default_country             = get_option( 'woocommerce_default_country' );
		$this->original_payment_gateways_settings = get_option( 'woocommerce_pos_settings_payment_gateways' );

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

		// Restore payment gateways settings
		if ( false !== $this->original_payment_gateways_settings ) {
			update_option( 'woocommerce_pos_settings_payment_gateways', $this->original_payment_gateways_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_payment_gateways' );
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
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( 'pos_cash' );
		$order->save();

		$_REQUEST['pos']         = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		// This filter expects statuses without the 'wc-' prefix.
		$this->assertEquals( 'completed', $status, 'Status should be from per-gateway settings (without wc- prefix)' );
	}

	/**
	 * Data provider: offline gateway process payment status filters.
	 *
	 * @return array[]
	 */
	public function offline_gateway_process_payment_status_filters_provider(): array {
		return array(
			'bacs'   => array(
				'filter'         => 'woocommerce_bacs_process_payment_order_status',
				'default_status' => 'on-hold',
			),
			'cheque' => array(
				'filter'         => 'woocommerce_cheque_process_payment_order_status',
				'default_status' => 'on-hold',
			),
			'cod'    => array(
				'filter'         => 'woocommerce_cod_process_payment_order_status',
				'default_status' => 'processing',
			),
		);
	}

	/**
	 * Test offline gateways respect POS checkout order status in POS requests.
	 *
	 * @dataProvider offline_gateway_process_payment_status_filters_provider
	 *
	 * @param string $filter         Hook name.
	 * @param string $default_status Gateway default status.
	 */
	public function test_offline_gateway_process_payment_order_status_from_settings_during_pos_request( string $filter, string $default_status ): void {
		preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
		$gateway_id = $matches[1];

		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( $gateway_id );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( $filter, $default_status, $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'completed', $status );
	}

	/**
	 * Test offline gateways support unprefixed checkout status values.
	 *
	 * @dataProvider offline_gateway_process_payment_status_filters_provider
	 *
	 * @param string $filter         Hook name.
	 * @param string $default_status Gateway default status.
	 */
	public function test_offline_gateway_process_payment_order_status_from_unprefixed_setting( string $filter, string $default_status ): void {
		preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
		$gateway_id = $matches[1];

		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( $gateway_id );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( $filter, $default_status, $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'completed', $status );
	}

	/**
	 * Test offline gateways keep default status outside POS requests.
	 *
	 * @dataProvider offline_gateway_process_payment_status_filters_provider
	 *
	 * @param string $filter         Hook name.
	 * @param string $default_status Gateway default status.
	 */
	public function test_offline_gateway_process_payment_order_status_returns_default_outside_pos_request( string $filter, string $default_status ): void {
		preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
		$gateway_id = $matches[1];

		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( $gateway_id );
		$order->save();

		$status = apply_filters( $filter, $default_status, $order );

		$this->assertEquals( $default_status, $status );
	}

	/**
	 * Test offline gateways keep default status for invalid checkout setting values.
	 *
	 * @dataProvider offline_gateway_process_payment_status_filters_provider
	 *
	 * @param string $filter         Hook name.
	 * @param string $default_status Gateway default status.
	 */
	public function test_offline_gateway_process_payment_order_status_returns_default_for_invalid_setting( string $filter, string $default_status ): void {
		preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
		$gateway_id = $matches[1];

		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'not-a-real-status',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( $gateway_id );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( $filter, $default_status, $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( $default_status, $status );
	}

	/**
	 * Test offline gateways keep default status for non-POS orders even if request looks like POS.
	 *
	 * @dataProvider offline_gateway_process_payment_status_filters_provider
	 *
	 * @param string $filter         Hook name.
	 * @param string $default_status Gateway default status.
	 */
	public function test_offline_gateway_process_payment_order_status_returns_default_for_non_pos_order( string $filter, string $default_status ): void {
		preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
		$gateway_id = $matches[1];

		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = OrderHelper::create_order();
		$order->set_payment_method( $gateway_id );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( $filter, $default_status, $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( $default_status, $status );
	}

	/**
	 * Test per-gateway order status is applied for POS payment complete.
	 */
	public function test_per_gateway_order_status_for_payment_complete(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( 'pos_cash' );
		$order->save();

		$_REQUEST['pos']         = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		// This filter expects statuses without the 'wc-' prefix.
		$this->assertEquals( 'completed', $status );
	}

	/**
	 * Test per-gateway order status returns different statuses for different gateways.
	 */
	public function test_per_gateway_order_status_differs_by_gateway(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
					'bacs'     => array(
						'order'        => 1,
						'enabled'      => true,
						'order_status' => 'wc-on-hold',
					),
				),
			)
		);

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$bacs_order = $this->create_pos_order( 'pos-open' );
		$bacs_order->set_payment_method( 'bacs' );
		$bacs_order->save();

		$bacs_status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $bacs_order );
		$this->assertEquals( 'on-hold', $bacs_status );

		$cash_order = $this->create_pos_order( 'pos-open' );
		$cash_order->set_payment_method( 'pos_cash' );
		$cash_order->save();

		$_REQUEST['pos'] = '1';
		$cash_status     = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $cash_order->get_id(), $cash_order );
		// This filter expects statuses without the 'wc-' prefix.
		$this->assertEquals( 'completed', $cash_status );

		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );
	}

	/**
	 * Test per-gateway order status falls back to wc-completed when gateway has no setting.
	 */
	public function test_per_gateway_order_status_fallback_to_completed(): void {
		delete_option( 'woocommerce_pos_settings_payment_gateways' );

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( 'pos_cash' );
		$order->save();

		$_REQUEST['pos']         = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		// This filter expects statuses without the 'wc-' prefix.
		$this->assertEquals( 'completed', $status );
	}

	/**
	 * Test per-gateway order status with invalid status falls back to gateway default.
	 */
	public function test_per_gateway_order_status_invalid_falls_back(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'bacs' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-not-a-real-status',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'on-hold', $status );
	}

	/**
	 * Test non-POS orders are unaffected by per-gateway settings.
	 */
	public function test_per_gateway_order_status_non_pos_unaffected(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'bacs' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$order = OrderHelper::create_order();
		$order->set_payment_method( 'bacs' );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'on-hold', $status );
	}

	/**
	 * Test per-gateway status normalization (accepts both wc-completed and completed).
	 */
	public function test_per_gateway_order_status_normalization(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'bacs' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'completed',
					),
				),
			)
		);

		$order = $this->create_pos_order( 'pos-open' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$_SERVER['HTTP_X_WCPOS'] = '1';

		$status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

		unset( $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'completed', $status );
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
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'         => '10.00',
				'regular_price' => '15.00',
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
	 * BUG: Miscellaneous product shows $0 price on order receipts and emails.
	 *
	 * When wc_get_product(0) returns a WC_Product object instead of false,
	 * the filter condition `! $product` fails and the price from
	 * _woocommerce_pos_data is never applied.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/432
	 */
	public function test_order_item_product_overrides_truthy_product_for_misc_item(): void {
		$order = $this->create_pos_order();

		// Create a line item with product_id = 0 (misc product)
		$item = new WC_Order_Item_Product();
		$item->set_name( 'Misc Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'         => '4.99',
				'regular_price' => '8.00',
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->save();

		// Simulate wc_get_product(0) returning a WC_Product_Simple with price 0
		// instead of false — this happens in some WC versions/configurations.
		$empty_product = new WC_Product_Simple();
		$product       = apply_filters( 'woocommerce_order_item_product', $empty_product, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $product );
		$this->assertEquals( '4.99', $product->get_price(), 'Misc product price should come from POS data, not the empty product' );
		$this->assertEquals( '8.00', $product->get_regular_price(), 'Misc product regular_price should come from POS data' );
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
	 * Test that virtual misc products return needs_shipping = false.
	 */
	public function test_order_item_product_misc_virtual(): void {
		$order = $this->create_pos_order();

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Virtual Misc Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'   => '10.00',
				'virtual' => true,
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->save();

		$product = apply_filters( 'woocommerce_order_item_product', false, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $product );
		$this->assertTrue( $product->is_virtual(), 'Virtual misc product should be virtual' );
		$this->assertFalse( $product->needs_shipping(), 'Virtual misc product should not need shipping' );
	}

	/**
	 * Test that downloadable misc products return is_downloadable = true.
	 */
	public function test_order_item_product_misc_downloadable(): void {
		$order = $this->create_pos_order();

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Downloadable Misc Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'        => '5.00',
				'downloadable' => true,
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->save();

		$product = apply_filters( 'woocommerce_order_item_product', false, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $product );
		$this->assertTrue( $product->is_downloadable(), 'Downloadable misc product should be downloadable' );
	}

	/**
	 * Test that misc products with categories have correct category IDs.
	 */
	public function test_order_item_product_misc_categories(): void {
		$order = $this->create_pos_order();

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Categorized Misc Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'      => '20.00',
				'categories' => array(
					array(
						'id'   => 42,
						'name' => 'Clothing',
					),
					array(
						'id'   => 99,
						'name' => 'Accessories',
					),
				),
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->save();

		$product = apply_filters( 'woocommerce_order_item_product', false, $item );

		$this->assertInstanceOf( 'WC_Product_Simple', $product );
		$this->assertEquals( array( 42, 99 ), $product->get_category_ids(), 'Misc product should have category IDs from POS data' );
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

	/**
	 * Test migration from global checkout order_status to per-gateway settings.
	 */
	public function test_migration_global_order_status_to_per_gateway(): void {
		// Set up old-style global checkout order_status.
		update_option( 'woocommerce_pos_settings_checkout', array(
			'order_status' => 'wc-processing',
		) );

		// No per-gateway order_status set.
		update_option( 'woocommerce_pos_settings_payment_gateways', array(
			'default_gateway' => 'pos_cash',
			'gateways'        => array(
				'pos_cash' => array(
					'order'   => 0,
					'enabled' => true,
				),
			),
		) );

		$settings_service = \WCPOS\WooCommercePOS\Services\Settings::instance();
		$gw_settings      = $settings_service->get_payment_gateways_settings();

		// The global value should have been applied to all gateways.
		$this->assertEquals( 'wc-processing', $gw_settings['gateways']['pos_cash']['order_status'] );

		// The global checkout setting should have been removed.
		$checkout = get_option( 'woocommerce_pos_settings_checkout', array() );
		$this->assertArrayNotHasKey( 'order_status', $checkout );
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
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'order'        => 0,
						'enabled'      => true,
						'order_status' => 'wc-completed',
					),
				),
			)
		);

		$orders = new Orders();
		$order  = $this->create_pos_order();
		$order->set_payment_method( 'pos_cash' );
		$order->save();

		$_REQUEST['pos']         = '1';
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$result = $orders->payment_complete_order_status( 'processing', $order->get_id(), $order );

		unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

		$this->assertEquals( 'completed', $result );
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

		$item = new WC_Order_Item_Product();
		$item->set_name( 'Direct Test Item' );
		$item->set_quantity( 1 );
		$item->set_product_id( 0 );
		$item->add_meta_data( '_sku', 'DIRECT-001' );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'         => '25.00',
				'regular_price' => '30.00',
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
	 * Saving an order-item product object must not mutate DB price meta or duplicate postmeta.
	 *
	 * This simulates stock update paths that call $item->get_product() and then
	 * save the returned product object.
	 */
	public function test_order_item_product_save_does_not_persist_sale_price(): void {
		global $wpdb;

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 25,
				'price'         => 25,
			)
		);
		$original_id = $product->get_id();
		add_post_meta( $original_id, '_wpcom_is_markdown', '1', true );

		// Verify no sale_price initially.
		$this->assertEquals( '', $product->get_sale_price(), 'Product should have no sale_price initially' );
		$meta_rows_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$original_id,
				'_wpcom_is_markdown'
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 25 );
		$item->set_total( 20 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'         => '20.00',
				'regular_price' => '25.00',
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		// Get the filtered product (simulates what wc_reduce_stock_levels does).
		$items           = $order->get_items();
		$first_item      = reset( $items );
		$filtered_product = $first_item->get_product();

		// Returned product should keep catalog sale state untouched.
		$this->assertEquals( '', $filtered_product->get_sale_price(), 'Returned product should not be mutated with POS sale_price' );

		// The product keeps its real ID (needed by WC stock functions).
		$this->assertEquals( $original_id, $filtered_product->get_id(), 'Filtered product should keep its original ID' );

		// Simulate what wc_update_product_stock() does: save the filtered product.
		$filtered_product->save();
		$meta_rows_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$original_id,
				'_wpcom_is_markdown'
			)
		);

		// Reload from DB and verify sale_price was NOT persisted.
		$db_product = wc_get_product( $original_id );
		$this->assertEquals( '', $db_product->get_sale_price(), 'DB sale_price must not be set after saving filtered product' );
		$this->assertEquals( '25', $db_product->get_price(), 'DB price must remain at original value' );
		$this->assertEquals( $meta_rows_before, $meta_rows_after, 'Saving returned product must not duplicate unrelated postmeta rows' );
	}

	/**
	 * Stock-reduction lifecycle must not duplicate unrelated product postmeta.
	 *
	 * Simulates WooCommerce stock handling paths that call get_product() on order
	 * items and then save product objects while reducing stock.
	 */
	public function test_stock_reduction_lifecycle_does_not_duplicate_postmeta(): void {
		global $wpdb;

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 25,
				'price'         => 25,
				'manage_stock'  => true,
				'stock_quantity' => 20,
			)
		);
		$product_id = $product->get_id();
		add_post_meta( $product_id, '_wpcom_is_markdown', '1', true );

		$meta_rows_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$product_id,
				'_wpcom_is_markdown'
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pending' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 25 );
		$item->set_total( 20 );
		POSLineItemHelper::add_pos_data_to_item(
			$item,
			array(
				'price'         => '20.00',
				'regular_price' => '25.00',
				'tax_status'    => 'none',
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		// Trigger stock reduction via normal status transition path.
		$order->set_status( 'processing' );
		$order->save();

		$meta_rows_after_processing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$product_id,
				'_wpcom_is_markdown'
			)
		);
		$this->assertEquals( $meta_rows_before, $meta_rows_after_processing, 'Processing transition must not duplicate product meta.' );

		// A second transition should remain safe as well.
		$order->set_status( 'completed' );
		$order->save();

		$meta_rows_after_completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$product_id,
				'_wpcom_is_markdown'
			)
		);
		$this->assertEquals( $meta_rows_before, $meta_rows_after_completed, 'Completed transition must not duplicate product meta.' );
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
		$this->assertNotFalse( has_filter( 'woocommerce_bacs_process_payment_order_status', array( $orders, 'offline_process_payment_order_status' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_cheque_process_payment_order_status', array( $orders, 'offline_process_payment_order_status' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_cod_process_payment_order_status', array( $orders, 'offline_process_payment_order_status' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_hidden_order_itemmeta', array( $orders, 'hidden_order_itemmeta' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_item_product', array( $orders, 'order_item_product' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_get_tax_location', array( $orders, 'get_tax_location' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_coupon_get_items_to_validate', array( $orders, 'coupon_get_items_to_validate' ) ) );
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
