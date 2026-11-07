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

	/**
	 * Symbol positions for the payment_fields() currency tests.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function currency_position_provider(): array {
		return array(
			'left'        => array( 'left' ),
			'left_space'  => array( 'left_space' ),
			'right'       => array( 'right' ),
			'right_space' => array( 'right_space' ),
		);
	}

	/**
	 * Test payment_fields shows the order currency symbol when it differs from the site's.
	 *
	 * @dataProvider currency_position_provider
	 *
	 * @param string $currency_pos Value of the woocommerce_currency_pos option.
	 */
	public function test_payment_fields_order_currency_differs_from_site_shows_order_symbol( string $currency_pos ): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_currency( 'EUR' );
		$order->save();

		// Act.
		$output = $this->render_payment_fields( $order->get_id(), $currency_pos );

		// Assert.
		$this->assertStringContainsString( $this->addon( 'EUR' ), $output );
		$this->assertStringNotContainsString( $this->addon( 'USD' ), $output );
	}

	/**
	 * Test payment_fields shows the site currency symbol when there is no order.
	 */
	public function test_payment_fields_without_an_order_shows_site_symbol(): void {
		// Arrange: no order-pay query var.

		// Act.
		$output = $this->render_payment_fields( null, 'left' );

		// Assert.
		$this->assertStringContainsString( $this->addon( 'USD' ), $output );
	}

	/**
	 * Test payment_fields shows the site currency symbol when the order-pay id is not an order.
	 */
	public function test_payment_fields_with_an_id_that_is_not_an_order_shows_site_symbol(): void {
		// Arrange.
		$not_an_order_id = 999999;

		// Act.
		$output = $this->render_payment_fields( $not_an_order_id, 'left' );

		// Assert.
		$this->assertStringContainsString( $this->addon( 'USD' ), $output );
	}

	/**
	 * Render payment_fields() on a USD site, restoring the query var and options afterwards.
	 *
	 * @param int|null $order_pay    Value for the order-pay query var, or null to leave it unset.
	 * @param string   $currency_pos Value of the woocommerce_currency_pos option.
	 *
	 * @return string The rendered output.
	 */
	private function render_payment_fields( ?int $order_pay, string $currency_pos ): string {
		$previous_currency     = get_option( 'woocommerce_currency' );
		$previous_currency_pos = get_option( 'woocommerce_currency_pos' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency_pos', $currency_pos );
		if ( null !== $order_pay ) {
			$GLOBALS['wp']->query_vars['order-pay'] = $order_pay;
		}

		try {
			ob_start();
			$this->gateway->payment_fields();

			return ob_get_clean();
		} finally {
			unset( $GLOBALS['wp']->query_vars['order-pay'] );
			update_option( 'woocommerce_currency', $previous_currency );
			update_option( 'woocommerce_currency_pos', $previous_currency_pos );
		}
	}

	/**
	 * The addon markup payment_fields() renders for a currency, built through wp_kses_post() like the gateway.
	 *
	 * @param string $currency Currency code.
	 *
	 * @return string The addon markup.
	 */
	private function addon( string $currency ): string {
		return wp_kses_post( '<span class="input-group-addon">' . get_woocommerce_currency_symbol( $currency ) . '</span>' );
	}

	/**
	 * Test payment_fields places the symbol where the order's POS store says, not the site.
	 */
	public function test_payment_fields_store_position_overrides_the_site_position(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_currency( 'EUR' );
		$order->save();
		$store_filter = $this->add_store( 'right' );

		try {
			// Act.
			$output = $this->render_payment_fields( $order->get_id(), 'left' );
		} finally {
			remove_filter( 'wcpos_get_store', $store_filter );
		}

		// Assert.
		$this->assertGreaterThan( strpos( $output, '<input' ), strpos( $output, $this->addon( 'EUR' ) ) );
	}

	/**
	 * Test payment_fields uses the site position when there is no order.
	 */
	public function test_payment_fields_without_an_order_uses_the_site_position(): void {
		// Arrange.
		$store_filter = $this->add_store( 'right' );

		try {
			// Act.
			$output = $this->render_payment_fields( null, 'left' );
		} finally {
			remove_filter( 'wcpos_get_store', $store_filter );
		}

		// Assert.
		$this->assertLessThan( strpos( $output, '<input' ), strpos( $output, $this->addon( 'USD' ) ) );
	}

	/**
	 * Test payment_fields uses the site position when the store has none.
	 */
	public function test_payment_fields_store_with_no_position_uses_the_site_position(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_currency( 'EUR' );
		$order->save();
		$store_filter = $this->add_store( '' );

		try {
			// Act.
			$output = $this->render_payment_fields( $order->get_id(), 'left' );
		} finally {
			remove_filter( 'wcpos_get_store', $store_filter );
		}

		// Assert.
		$this->assertLessThan( strpos( $output, '<input' ), strpos( $output, $this->addon( 'EUR' ) ) );
	}

	/**
	 * Test payment_fields uses the site position when the order's store no longer exists.
	 */
	public function test_payment_fields_order_store_gone_uses_the_site_position(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_currency( 'EUR' );
		$order->update_meta_data( '_pos_store', 999999 );
		$order->save();
		$store_filter = static function () {
			return null;
		};
		add_filter( 'wcpos_get_store', $store_filter );

		try {
			// Act.
			$output = $this->render_payment_fields( $order->get_id(), 'left' );
		} finally {
			remove_filter( 'wcpos_get_store', $store_filter );
		}

		// Assert.
		$this->assertLessThan( strpos( $output, '<input' ), strpos( $output, $this->addon( 'EUR' ) ) );
	}

	/**
	 * Add a wcpos_get_store filter that returns a store stub with the given currency position.
	 *
	 * @param string $position Currency position.
	 *
	 * @return callable The filter, for the caller to remove.
	 */
	private function add_store( string $position ): callable {
		$stub = new class( $position ) {
			/**
			 * Currency position.
			 *
			 * @var string
			 */
			private $position;

			/**
			 * Constructor.
			 *
			 * @param string $position Currency position.
			 */
			public function __construct( string $position ) {
				$this->position = $position;
			}

			/**
			 * Currency position.
			 */
			public function get_currency_position(): string {
				return $this->position;
			}

			/**
			 * Decimal separator.
			 */
			public function get_price_decimal_separator(): string {
				return '.';
			}

			/**
			 * Thousand separator.
			 */
			public function get_price_thousand_separator(): string {
				return ',';
			}

			/**
			 * Number of decimals.
			 */
			public function get_price_number_of_decimals(): int {
				return 2;
			}
		};

		$filter = static function () use ( $stub ) {
			return $stub;
		};
		add_filter( 'wcpos_get_store', $filter );

		return $filter;
	}
}
