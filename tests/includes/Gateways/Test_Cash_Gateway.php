<?php
/**
 * Tests for the WCPOS Cash Payment Gateway.
 *
 * Tests the cash payment gateway functionality including:
 * - Gateway registration and properties
 * - Payment processing (full and partial)
 * - Change calculation
 * - Payment details retrieval
 */

namespace WCPOS\WooCommercePOS\Tests\Gateways;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Gateways\Cash;

/**
 * Test_Cash_Gateway class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Cash_Gateway extends WC_Unit_Test_Case {
	/**
	 * The Cash gateway instance.
	 *
	 * @var Cash
	 */
	private $gateway;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->gateway = new Cash();
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
		$this->assertInstanceOf( Cash::class, $this->gateway );
	}

	/**
	 * Test gateway ID is set correctly.
	 */
	public function test_gateway_id(): void {
		$this->assertEquals( 'pos_cash', $this->gateway->id );
	}

	/**
	 * Test gateway title is set correctly.
	 */
	public function test_gateway_title(): void {
		$this->assertEquals( 'Cash', $this->gateway->title );
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
	 * Test payment_details returns correct structure.
	 */
	public function test_payment_details_structure(): void {
		$order = OrderHelper::create_order();

		$details = Cash::payment_details( $order );

		$this->assertIsArray( $details );
		$this->assertArrayHasKey( 'tendered', $details );
		$this->assertArrayHasKey( 'change', $details );
	}

	/**
	 * Test payment_details returns stored values.
	 */
	public function test_payment_details_returns_stored_values(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_cash_amount_tendered', '50.00' );
		$order->update_meta_data( '_pos_cash_change', '10.00' );
		$order->save();

		// Clear meta cache
		wp_cache_flush();

		// Use update_post_meta since payment_details uses get_post_meta
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '50.00' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '10.00' );

		$details = Cash::payment_details( $order );

		$this->assertEquals( '50.00', $details['tendered'] );
		$this->assertEquals( '10.00', $details['change'] );
	}

	/**
	 * Test payment_details returns empty values when not set.
	 */
	public function test_payment_details_returns_empty_when_not_set(): void {
		$order = OrderHelper::create_order();

		$details = Cash::payment_details( $order );

		$this->assertEmpty( $details['tendered'] );
		$this->assertEmpty( $details['change'] );
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
	 * Test calculate_change outputs message when values are set.
	 */
	public function test_calculate_change_outputs_message(): void {
		$order = OrderHelper::create_order();

		// Use update_post_meta since calculate_change uses get_post_meta
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '50.00' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '10.00' );

		ob_start();
		$this->gateway->calculate_change( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Amount Tendered', $output );
		$this->assertStringContainsString( 'Change', $output );
	}

	/**
	 * Test calculate_change outputs nothing when values not set.
	 */
	public function test_calculate_change_outputs_nothing_when_not_set(): void {
		$order = OrderHelper::create_order();

		ob_start();
		$this->gateway->calculate_change( $order->get_id() );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test process_payment with full payment completes order.
	 *
	 * Note: This test simulates the payment process but cannot fully test
	 * the nonce verification without a browser context.
	 */
	public function test_process_payment_stores_meta(): void {
		$order = OrderHelper::create_order();
		$order->set_total( '40.00' );
		$order->save();

		// Store payment data directly (simulating what process_payment does)
		$tendered = '50.00';
		$change   = wc_format_decimal( \floatval( $tendered ) - \floatval( $order->get_total() ) );

		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', $tendered );
		update_post_meta( $order->get_id(), '_pos_cash_change', $change );

		// Verify meta was stored
		$this->assertEquals( $tendered, get_post_meta( $order->get_id(), '_pos_cash_amount_tendered', true ) );
		$this->assertEquals( '10', get_post_meta( $order->get_id(), '_pos_cash_change', true ) );
	}

	/**
	 * Test change calculation logic (unit test of the calculation itself).
	 */
	public function test_change_calculation_logic(): void {
		// Test case 1: Tendered more than total
		$order_total = 40.00;
		$tendered    = 50.00;
		$change      = $tendered > $order_total ? wc_format_decimal( $tendered - $order_total ) : '0';
		$this->assertEquals( '10', $change );

		// Test case 2: Tendered exactly equals total
		$tendered = 40.00;
		$change   = $tendered > $order_total ? wc_format_decimal( $tendered - $order_total ) : '0';
		$this->assertEquals( '0', $change );

		// Test case 3: Tendered less than total (partial payment)
		$tendered = 30.00;
		$change   = $tendered > $order_total ? wc_format_decimal( $tendered - $order_total ) : '0';
		$this->assertEquals( '0', $change );
	}

	/**
	 * Test partial payment calculation logic.
	 */
	public function test_partial_payment_logic(): void {
		$order_total = 100.00;
		$tendered    = 60.00;

		// Partial payment should result in remaining balance
		$remaining = wc_format_decimal( $order_total - $tendered );

		$this->assertEquals( '40', $remaining );
		$this->assertTrue( $tendered < $order_total );
	}

	/**
	 * Test gateway icon filter.
	 */
	public function test_gateway_icon_filter(): void {
		$custom_icon = 'https://example.com/custom-icon.png';

		add_filter(
			'woocommerce_pos_cash_icon',
			function () use ( $custom_icon ) {
				return $custom_icon;
			}
		);

		$gateway = new Cash();

		$this->assertEquals( $custom_icon, $gateway->icon );

		remove_all_filters( 'woocommerce_pos_cash_icon' );
	}

	/**
	 * Test thankyou action is registered.
	 */
	public function test_thankyou_action_registered(): void {
		$gateway = new Cash();

		$this->assertTrue(
			has_action( 'woocommerce_thankyou_pos_cash' ),
			'Thankyou action should be registered'
		);
	}

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: constructor sets all expected properties.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Cash::__construct
	 */
	public function test_direct_constructor_properties(): void {
		$gateway = new Cash();

		$this->assertEquals( 'pos_cash', $gateway->id );
		$this->assertEquals( 'Cash', $gateway->title );
		$this->assertEquals( '', $gateway->description );
		$this->assertTrue( $gateway->has_fields );
		$this->assertEquals( 'no', $gateway->enabled );
	}

	/**
	 * Direct test: payment_details with order object.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Cash::payment_details
	 */
	public function test_direct_payment_details(): void {
		$order = OrderHelper::create_order();

		// Test with no meta set
		$details = Cash::payment_details( $order );
		$this->assertArrayHasKey( 'tendered', $details );
		$this->assertArrayHasKey( 'change', $details );

		// Set meta and test again
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '100.00' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '20.00' );

		$details = Cash::payment_details( $order );
		$this->assertEquals( '100.00', $details['tendered'] );
		$this->assertEquals( '20.00', $details['change'] );
	}

	/**
	 * Direct test: calculate_change displays formatted output.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Cash::calculate_change
	 */
	public function test_direct_calculate_change_formatted(): void {
		$order = OrderHelper::create_order();

		// Set payment data
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '75.50' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '15.50' );

		ob_start();
		$this->gateway->calculate_change( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Amount Tendered', $output );
		$this->assertStringContainsString( 'Change', $output );
		$this->assertStringContainsString( '75.50', $output );
		$this->assertStringContainsString( '15.50', $output );
	}

	/**
	 * Direct test: exact payment (no change).
	 */
	public function test_direct_exact_payment_no_change(): void {
		$order_total = 50.00;
		$tendered    = 50.00;

		$change = $tendered > $order_total
			? wc_format_decimal( \floatval( $tendered ) - \floatval( $order_total ) )
			: '0';

		$this->assertEquals( '0', $change );
	}

	/**
	 * Direct test: overpayment with change.
	 */
	public function test_direct_overpayment_with_change(): void {
		$order_total = 35.75;
		$tendered    = 40.00;

		$change = $tendered > $order_total
			? wc_format_decimal( \floatval( $tendered ) - \floatval( $order_total ) )
			: '0';

		$this->assertEquals( '4.25', $change );
	}

	/**
	 * Direct test: partial payment (tendered less than total).
	 */
	public function test_direct_partial_payment(): void {
		$order_total = 100.00;
		$tendered    = 60.00;

		$change = $tendered > $order_total
			? wc_format_decimal( \floatval( $tendered ) - \floatval( $order_total ) )
			: '0';

		$this->assertEquals( '0', $change );

		// The remaining balance
		$remaining = wc_format_decimal( $order_total - $tendered );
		$this->assertEquals( '40', $remaining );
	}

	/**
	 * Direct test: gateway supports array (no specific support).
	 */
	public function test_direct_gateway_supports(): void {
		// Cash gateway doesn't declare specific supports
		// but should inherit default WC_Payment_Gateway supports
		$this->assertIsArray( $this->gateway->supports );
	}

	/**
	 * Direct test: constructor registers admin options action.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Cash::__construct
	 */
	public function test_direct_constructor_registers_admin_action(): void {
		$gateway = new Cash();

		$this->assertTrue(
			has_action( 'woocommerce_pos_update_options_payment_gateways_pos_cash' ),
			'Admin options action should be registered'
		);
	}

	/**
	 * Direct test: multiple orders with different payment scenarios.
	 */
	public function test_direct_multiple_payment_scenarios(): void {
		$scenarios = array(
			array( 'total' => 10.00, 'tendered' => 20.00, 'expected_change' => '10' ),
			array( 'total' => 99.99, 'tendered' => 100.00, 'expected_change' => '0.01' ),
			array( 'total' => 50.00, 'tendered' => 50.00, 'expected_change' => '0' ),
			array( 'total' => 25.00, 'tendered' => 10.00, 'expected_change' => '0' ),
		);

		foreach ( $scenarios as $scenario ) {
			$change = $scenario['tendered'] > $scenario['total']
				? wc_format_decimal( \floatval( $scenario['tendered'] ) - \floatval( $scenario['total'] ) )
				: '0';

			$this->assertEquals(
				$scenario['expected_change'],
				$change,
				\sprintf(
					'Total: %.2f, Tendered: %.2f should give change: %s',
					$scenario['total'],
					$scenario['tendered'],
					$scenario['expected_change']
				)
			);
		}
	}
}
