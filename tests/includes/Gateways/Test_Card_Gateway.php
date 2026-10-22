<?php
/**
 * Tests for the WCPOS Card Payment Gateway.
 *
 * @package WCPOS\WooCommercePOS\Tests
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
	 * Test calculate_cashback uses the order currency.
	 */
	public function test_calculate_cashback_order_currency_differs_from_store_renders_order_currency_symbol(): void {
		// Arrange.
		$order_currency = 'EUR' === get_woocommerce_currency() ? 'USD' : 'EUR';
		$order          = OrderHelper::create_order();
		$order->set_currency( $order_currency );
		$order->update_meta_data( '_pos_card_cashback', '20.00' );
		$order->save();

		// Act.
		ob_start();
		$this->gateway->calculate_cashback( $order->get_id() );
		$output = ob_get_clean();

		// Assert.
		$this->assertEquals( 1, substr_count( $output, get_woocommerce_currency_symbol( $order_currency ) ) );
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
	 * Test gateway icon filter.
	 */
	public function test_gateway_icon_filter(): void {
		$custom_icon = 'https://example.com/card-icon.png';

		add_filter(
			'wcpos_card_icon',
			function () use ( $custom_icon ) {
				return $custom_icon;
			}
		);

		$gateway = new Card();

		$this->assertEquals( $custom_icon, $gateway->icon );

		remove_all_filters( 'wcpos_card_icon' );
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

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage).
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
			has_action( 'wcpos_update_options_payment_gateways_pos_card' ),
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

		// Test with $50 cashback.
		$order->update_meta_data( '_pos_card_cashback', '50.00' );
		$order->save();

		ob_start();
		$this->gateway->calculate_cashback( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cashback', $output );
		$this->assertStringContainsString( '50.00', $output );
	}

	/**
	 * Direct test: gateway supports refunds.
	 */
	public function test_direct_gateway_supports(): void {
		$this->assertIsArray( $this->gateway->supports );
		$this->assertContains( 'products', $this->gateway->supports );
		$this->assertContains( 'refunds', $this->gateway->supports );
	}

	/**
	 * Test process_refund returns true.
	 */
	public function test_process_refund_returns_true(): void {
		$order = OrderHelper::create_order();

		$result = $this->gateway->process_refund( $order->get_id(), 10.00, 'Test refund' );

		$this->assertTrue( $result );

		// Also test default-argument path.
		$result_defaults = $this->gateway->process_refund( $order->get_id() );

		$this->assertTrue( $result_defaults );
	}

}
