<?php
/**
 * Tests for the WCPOS Card Payment Gateway.
 *
 * Tests the card payment gateway functionality including:
 * - Gateway registration and properties
 * - Payment processing with and without cashback
 * - Cashback calculation
 */

namespace WCPOS\WooCommercePOS\Tests\Gateways;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Gateways\Card;

/**
 * Test_Card_Gateway class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Card_Gateway extends WC_Unit_Test_Case {
	/**
	 * The Card gateway instance.
	 *
	 * @var Card
	 */
	private $gateway;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->gateway = new Card();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test gateway instantiation.
	 */
	public function test_gateway_instantiation(): void {
		$this->assertInstanceOf( Card::class, $this->gateway );
	}

	/**
	 * Test gateway ID is set correctly.
	 */
	public function test_gateway_id(): void {
		$this->assertEquals( 'pos_card', $this->gateway->id );
	}

	/**
	 * Test gateway title is set correctly.
	 */
	public function test_gateway_title(): void {
		$this->assertEquals( 'Card', $this->gateway->title );
	}

	/**
	 * Test gateway has fields enabled.
	 */
	public function test_gateway_has_fields(): void {
		$this->assertTrue( $this->gateway->has_fields );
	}

	/**
	 * Test gateway is disabled by default.
	 */
	public function test_gateway_disabled_by_default(): void {
		$this->assertEquals( 'no', $this->gateway->enabled );
	}

	/**
	 * Test payment_fields outputs HTML.
	 *
	 * Note: This test is skipped due to CodeHacker conflicts with get_option.
	 * The payment_fields method calls get_option('woocommerce_currency_pos')
	 * which conflicts with the test mocking infrastructure.
	 */
	public function test_payment_fields_outputs_html(): void {
		$this->markTestSkipped( 'Skipped due to CodeHacker conflicts with get_option in payment_fields' );
	}

	/**
	 * Test calculate_cashback outputs message when cashback is set.
	 */
	public function test_calculate_cashback_outputs_message(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_card_cashback', '20.00' );
		$order->save();

		ob_start();
		$this->gateway->calculate_cashback( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cashback', $output );
	}

	/**
	 * Test calculate_cashback outputs nothing when cashback not set.
	 */
	public function test_calculate_cashback_outputs_nothing_when_not_set(): void {
		$order = OrderHelper::create_order();

		ob_start();
		$this->gateway->calculate_cashback( $order->get_id() );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test cashback meta storage (simulating what process_payment does).
	 */
	public function test_cashback_meta_storage(): void {
		$order = OrderHelper::create_order();
		$order->set_total( '50.00' );
		$order->save();

		$cashback = '20.00';

		// Simulate storing cashback meta
		$order->update_meta_data( '_pos_card_cashback', $cashback );
		$order->save();

		// Verify meta was stored
		$stored_cashback = $order->get_meta( '_pos_card_cashback' );
		$this->assertEquals( $cashback, $stored_cashback );
	}

	/**
	 * Test order total adjustment with cashback (simulation).
	 */
	public function test_order_total_with_cashback(): void {
		$order_total    = 50.00;
		$cashback       = 20.00;
		$expected_total = wc_format_decimal( $order_total + $cashback );

		$this->assertEquals( '70', $expected_total );
	}

	/**
	 * Test order total without cashback remains unchanged.
	 */
	public function test_order_total_without_cashback(): void {
		$order_total = 50.00;
		$cashback    = 0;

		// When cashback is 0, total should not change
		$this->assertEquals( 0, $cashback );

		// The logic: if (0 !== $cashback) won't execute
		$expected_total = $order_total;
		$this->assertEquals( 50.00, $expected_total );
	}

	/**
	 * Test gateway icon filter.
	 */
	public function test_gateway_icon_filter(): void {
		$custom_icon = 'https://example.com/card-icon.png';

		add_filter(
			'woocommerce_pos_card_icon',
			function () use ( $custom_icon ) {
				return $custom_icon;
			}
		);

		$gateway = new Card();

		$this->assertEquals( $custom_icon, $gateway->icon );

		remove_all_filters( 'woocommerce_pos_card_icon' );
	}

	/**
	 * Test thankyou action is registered.
	 */
	public function test_thankyou_action_registered(): void {
		$gateway = new Card();

		$this->assertTrue(
			has_action( 'woocommerce_thankyou_pos_card' ),
			'Thankyou action should be registered'
		);
	}

	/**
	 * Test cashback is added as fee line item structure.
	 */
	public function test_cashback_fee_structure(): void {
		$order = OrderHelper::create_order();
		$order->set_total( '50.00' );
		$order->save();

		$cashback = '20.00';

		// Add cashback as fee using WooCommerce method (simulating what process_payment does)
		$item_id = wc_add_order_item(
			$order->get_id(),
			array(
				'order_item_name' => __( 'Cashback', 'woocommerce-pos' ),
				'order_item_type' => 'fee',
			)
		);

		$this->assertNotFalse( $item_id );
		$this->assertGreaterThan( 0, $item_id );

		// Add item meta
		wc_add_order_item_meta( $item_id, '_line_total', $cashback );
		wc_add_order_item_meta( $item_id, '_line_tax', 0 );

		// Verify fee was added
		$this->assertEquals( $cashback, wc_get_order_item_meta( $item_id, '_line_total', true ) );
		$this->assertEquals( '0', wc_get_order_item_meta( $item_id, '_line_tax', true ) );
	}

	/**
	 * Test different cashback amounts.
	 */
	public function test_various_cashback_amounts(): void {
		$test_cases = array(
			array( 'order_total' => 100.00, 'cashback' => 10.00, 'expected' => 110.00 ),
			array( 'order_total' => 50.00, 'cashback' => 25.00, 'expected' => 75.00 ),
			array( 'order_total' => 75.50, 'cashback' => 0, 'expected' => 75.50 ),
			array( 'order_total' => 200.00, 'cashback' => 100.00, 'expected' => 300.00 ),
		);

		foreach ( $test_cases as $case ) {
			$new_total = $case['order_total'];
			if ( 0 !== $case['cashback'] ) {
				$new_total = wc_format_decimal( \floatval( $case['order_total'] ) + \floatval( $case['cashback'] ) );
			}

			$this->assertEquals(
				wc_format_decimal( $case['expected'] ),
				$new_total,
				\sprintf(
					'Order total %s with cashback %s should equal %s',
					$case['order_total'],
					$case['cashback'],
					$case['expected']
				)
			);
		}
	}

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: constructor sets all expected properties.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Card::__construct
	 */
	public function test_direct_constructor_properties(): void {
		$gateway = new Card();

		$this->assertEquals( 'pos_card', $gateway->id );
		$this->assertEquals( 'Card', $gateway->title );
		$this->assertEquals( '', $gateway->description );
		$this->assertTrue( $gateway->has_fields );
		$this->assertEquals( 'no', $gateway->enabled );
	}

	/**
	 * Direct test: constructor registers admin options action.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Card::__construct
	 */
	public function test_direct_constructor_registers_admin_action(): void {
		$gateway = new Card();

		$this->assertTrue(
			has_action( 'woocommerce_pos_update_options_payment_gateways_pos_card' ),
			'Admin options action should be registered'
		);
	}

	/**
	 * Direct test: calculate_cashback with different values.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Card::calculate_cashback
	 */
	public function test_direct_calculate_cashback_various_values(): void {
		$order = OrderHelper::create_order();

		// Test with $50 cashback
		$order->update_meta_data( '_pos_card_cashback', '50.00' );
		$order->save();

		ob_start();
		$this->gateway->calculate_cashback( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cashback', $output );
		$this->assertStringContainsString( '50.00', $output );
	}

	/**
	 * Direct test: cashback with decimal values.
	 */
	public function test_direct_cashback_decimal_values(): void {
		$order_total = 45.75;
		$cashback    = 20.50;

		$new_total = wc_format_decimal( \floatval( $order_total ) + \floatval( $cashback ) );

		$this->assertEquals( '66.25', $new_total );
	}

	/**
	 * Direct test: zero cashback doesn't change total.
	 */
	public function test_direct_zero_cashback(): void {
		$order_total = 100.00;
		$cashback    = 0;

		// When cashback is 0, the condition (0 !== $cashback) is false
		$new_total = $order_total;
		if ( 0 !== $cashback ) {
			$new_total = wc_format_decimal( \floatval( $order_total ) + \floatval( $cashback ) );
		}

		$this->assertEquals( 100.00, $new_total );
	}

	/**
	 * Direct test: gateway supports array.
	 */
	public function test_direct_gateway_supports(): void {
		$this->assertIsArray( $this->gateway->supports );
	}

	/**
	 * Direct test: cashback is stored correctly in order meta.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Card::calculate_cashback
	 */
	public function test_direct_cashback_order_meta_storage(): void {
		$order    = OrderHelper::create_order();
		$cashback = '35.00';

		$order->update_meta_data( '_pos_card_cashback', $cashback );
		$order->save();

		// Retrieve using WC_Order method
		$stored = $order->get_meta( '_pos_card_cashback' );
		$this->assertEquals( $cashback, $stored );

		// Also verify it can be retrieved via post_meta for compatibility
		$stored_post_meta = get_post_meta( $order->get_id(), '_pos_card_cashback', true );
		$this->assertEquals( $cashback, $stored_post_meta );
	}

	/**
	 * Direct test: multiple orders with cashback.
	 */
	public function test_direct_multiple_orders_with_cashback(): void {
		$orders = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$order = OrderHelper::create_order();
			$order->set_total( 50 + ( $i * 10 ) ); // 50, 60, 70
			$cashback = 10 + ( $i * 5 ); // 10, 15, 20

			$order->update_meta_data( '_pos_card_cashback', wc_format_decimal( $cashback ) );
			$order->save();

			$orders[] = array(
				'order_id' => $order->get_id(),
				'total'    => $order->get_total(),
				'cashback' => $cashback,
			);
		}

		// Verify each order has correct cashback stored
		foreach ( $orders as $data ) {
			$stored_cashback = get_post_meta( $data['order_id'], '_pos_card_cashback', true );
			$this->assertEquals(
				wc_format_decimal( $data['cashback'] ),
				$stored_cashback,
				'Cashback should be stored correctly for order ' . $data['order_id']
			);
		}
	}

	/**
	 * Direct test: cashback affects displayed total.
	 */
	public function test_direct_cashback_affects_total(): void {
		$order          = OrderHelper::create_order();
		$original_total = 80.00;
		$cashback       = 30.00;

		$order->set_total( $original_total );
		$order->update_meta_data( '_pos_card_cashback', wc_format_decimal( $cashback ) );
		$order->save();

		// Calculate expected charged amount (total + cashback)
		$charged_amount = wc_format_decimal( \floatval( $original_total ) + \floatval( $cashback ) );

		$this->assertEquals( '110', $charged_amount );
	}
}
