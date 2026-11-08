<?php
/**
 * Tests for the WCPOS Cash Payment Gateway.
 *
 * @package WCPOS\WooCommercePOS\Tests
 *
 * Tests the cash payment gateway functionality including:
 * - Gateway registration and properties
 * - Payment processing (full amount; a short amount is refused)
 * - Change calculation
 * - Payment details retrieval
 */

namespace WCPOS\WooCommercePOS\Tests\Gateways;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WC_Order_Item_Fee;
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
	use HPOSToggleTrait;

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
		// Arrange: written the way process_payment() writes them.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_cash_amount_tendered', '50.00' );
		$order->update_meta_data( '_pos_cash_change', '10.00' );
		$order->save();

		// Act: on a fresh load, so the values come from storage rather than the object.
		$details = Cash::payment_details( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( '50.00', $details['tendered'] );
		$this->assertSame( '10.00', $details['change'] );
	}

	/**
	 * The details are read from HPOS order meta, where get_post_meta() would find nothing.
	 */
	public function test_payment_details_hpos_returns_stored_values(): void {
		// Arrange.
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();

		try {
			$order = OrderHelper::create_order();
			$order->update_meta_data( '_pos_cash_amount_tendered', '50.00' );
			$order->update_meta_data( '_pos_cash_change', '10.00' );
			$order->save();

			// Act.
			$details = Cash::payment_details( wc_get_order( $order->get_id() ) );

			// Assert.
			$this->assertSame( '', get_post_meta( $order->get_id(), '_pos_cash_amount_tendered', true ) );
			$this->assertSame( '50.00', $details['tendered'] );
			$this->assertSame( '10.00', $details['change'] );
		} finally {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
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
	 * Test calculate_change outputs message when values are set.
	 */
	public function test_calculate_change_outputs_message(): void {
		$order = OrderHelper::create_order();

		// Write via update_post_meta: calculate_change reads through
		// WC_Order::get_meta(), so this verifies legacy post-meta rows stay
		// readable through the order API.
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '50.00' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '10.00' );

		ob_start();
		$this->gateway->calculate_change( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Amount Tendered', $output );
		$this->assertStringContainsString( 'Change', $output );
	}

	/**
	 * Test calculate_change uses the order currency with legacy post meta.
	 */
	public function test_calculate_change_order_currency_differs_from_store_renders_order_currency_symbol(): void {
		// Arrange.
		$order_currency = 'EUR' === get_woocommerce_currency() ? 'USD' : 'EUR';
		$order          = OrderHelper::create_order();
		$order->set_currency( $order_currency );
		$order->save();
		update_post_meta( $order->get_id(), '_pos_cash_amount_tendered', '50.00' );
		update_post_meta( $order->get_id(), '_pos_cash_change', '10.00' );

		// Act.
		ob_start();
		$this->gateway->calculate_change( $order->get_id() );
		$output = ob_get_clean();

		// Assert.
		$this->assertEquals( 2, substr_count( $output, get_woocommerce_currency_symbol( $order_currency ) ) );
	}

	/**
	 * Test calculate_change reads HPOS order meta and uses the order currency.
	 */
	public function test_calculate_change_hpos_order_meta_renders_order_currency_symbol(): void {
		// Arrange.
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();

		try {
			$order_currency = 'EUR' === get_woocommerce_currency() ? 'USD' : 'EUR';
			$order          = OrderHelper::create_order();
			$order->set_currency( $order_currency );
			$order->update_meta_data( '_pos_cash_amount_tendered', '50.00' );
			$order->update_meta_data( '_pos_cash_change', '10.00' );
			$order->save();

			// Act.
			ob_start();
			$this->gateway->calculate_change( $order->get_id() );
			$output = ob_get_clean();

			// Assert.
			$this->assertEquals( 2, substr_count( $output, get_woocommerce_currency_symbol( $order_currency ) ) );
		} finally {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
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
	 * Test gateway icon filter.
	 */
	public function test_gateway_icon_filter(): void {
		$custom_icon = 'https://example.com/custom-icon.png';

		add_filter(
			'wcpos_cash_icon',
			function () use ( $custom_icon ) {
				return $custom_icon;
			}
		);

		$gateway = new Cash();

		$this->assertEquals( $custom_icon, $gateway->icon );

		remove_all_filters( 'wcpos_cash_icon' );
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
	// DIRECT METHOD TESTS (for line coverage).
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

		// Test with no meta set.
		$details = Cash::payment_details( $order );
		$this->assertArrayHasKey( 'tendered', $details );
		$this->assertArrayHasKey( 'change', $details );

		// Set meta the way process_payment() does and test again.
		$order->update_meta_data( '_pos_cash_amount_tendered', '100.00' );
		$order->update_meta_data( '_pos_cash_change', '20.00' );
		$order->save();

		$details = Cash::payment_details( wc_get_order( $order->get_id() ) );
		$this->assertSame( '100.00', $details['tendered'] );
		$this->assertSame( '20.00', $details['change'] );
	}

	/**
	 * Direct test: calculate_change displays formatted output.
	 *
	 * @covers \WCPOS\WooCommercePOS\Gateways\Cash::calculate_change
	 */
	public function test_direct_calculate_change_formatted(): void {
		$order = OrderHelper::create_order();

		// Set payment data.
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
	 * Test a POS cash payment records its tender audit note.
	 */
	public function test_pos_cash_payment_adds_tendered_and_change_note(): void {
		$order = OrderHelper::create_order();
		$order->set_total( '40.00' );
		$order->save();

		$result = $this->gateway->process_pos_checkout_action(
			array(),
			'start',
			array( 'amount_tendered' => '50.00' ),
			$order
		);
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ) => 0 === strpos( $note->content, 'Cash payment received' )
		);
		$note = wp_strip_all_tags( reset( $notes )->content );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertStringContainsString( 'Cash payment received — amount tendered:', $note );
		$this->assertStringContainsString( '50.00', $note );
		$this->assertStringContainsString( '10.00', $note );
	}

	/**
	 * Data provider: tendered amounts below a 100.00 balance.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function short_amount_provider(): array {
		return array(
			'60.00'        => array( '60.00' ),
			'99.99'        => array( '99.99' ),
			'0.00'         => array( '0.00' ),
			'typed 0'      => array( '0' ),
			'not a number' => array( 'not a number' ),
		);
	}

	/**
	 * A short amount on the order-pay form is refused with one line and the order is left as it was.
	 *
	 * @dataProvider short_amount_provider
	 *
	 * @param string $tendered Posted tendered amount.
	 */
	public function test_process_payment_short_amount_is_refused_and_writes_nothing( string $tendered ): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_total( '100.00' );
		$order->set_status( 'pos-open' );
		$order->save();
		$payment_complete = did_action( 'woocommerce_payment_complete' );

		// Act.
		$paid = $this->pay_by_form( $order, $tendered );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ) => 0 === strpos( $note->content, 'Cash payment received' )
		);

		$this->assertSame(
			array(
				'result'   => 'failure',
				'redirect' => '',
			),
			$paid['result']
		);
		$this->assertSame( array( 'Enter the full amount here, or use the Cash tile to take a part payment.' ), $paid['errors'] );
		$this->assertSame( '100.00', $fresh->get_total() );
		$this->assertSame( array(), $fresh->get_fees() );
		$this->assertSame( 'pos-open', $fresh->get_status() );
		$this->assertNull( $fresh->get_date_paid() );
		$this->assertSame( '', $fresh->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertSame( '', $fresh->get_meta( '_pos_cash_change' ) );
		$this->assertSame( array(), $notes );
		$this->assertSame( $payment_complete, did_action( 'woocommerce_payment_complete' ) );
	}

	/**
	 * The exact balance on the order-pay form completes the payment.
	 */
	public function test_process_payment_exact_amount_completes(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_total( '100.00' );
		$order->set_status( 'pos-open' );
		$order->save();

		// Act.
		$paid = $this->pay_by_form( $order, '100.00' );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $paid['result']['result'] );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '100.00', $fresh->get_total() );
		$this->assertSame( array(), $fresh->get_fees() );
		$this->assertSame( '100.00', $fresh->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertSame( '0', $fresh->get_meta( '_pos_cash_change' ) );
		$this->assertSame( array(), $paid['errors'] );
	}

	/**
	 * More than the balance on the order-pay form completes the payment and records the change.
	 */
	public function test_process_payment_over_amount_completes_with_change(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_total( '100.00' );
		$order->set_status( 'pos-open' );
		$order->save();

		// Act.
		$paid = $this->pay_by_form( $order, '120.00' );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $paid['result']['result'] );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '120.00', $fresh->get_meta( '_pos_cash_amount_tendered' ) );
		// The change is wc_format_decimal() of a float, which keeps no trailing zeros.
		$this->assertSame( '20', $fresh->get_meta( '_pos_cash_change' ) );
	}

	/**
	 * Data provider: a missing or blank tendered field.
	 *
	 * @return array<string, array{0: ?string}>
	 */
	public function blank_amount_provider(): array {
		return array(
			'missing' => array( null ),
			'blank'   => array( '' ),
		);
	}

	/**
	 * A missing or blank tendered field on the order-pay form takes the full balance.
	 *
	 * @dataProvider blank_amount_provider
	 *
	 * @param string|null $tendered Posted tendered amount, or null to leave the field out.
	 */
	public function test_process_payment_blank_amount_takes_the_full_balance( ?string $tendered ): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_total( '100.00' );
		$order->set_status( 'pos-open' );
		$order->save();

		// Act.
		$paid = $this->pay_by_form( $order, $tendered );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $paid['result']['result'] );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '100.00', $fresh->get_meta( '_pos_cash_amount_tendered' ) );
	}

	/**
	 * An order the old partial-cash path left behind settles on its lowered total, and only on that.
	 */
	public function test_process_payment_old_part_paid_order_settles_on_its_balance(): void {
		// Arrange: as the old code left it, a -40 Partial Payment fee, total lowered to 60.00, status pos-partial.
		$order = OrderHelper::create_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_props(
			array(
				'name'      => 'Partial Payment',
				'tax_class' => 0,
				'amount'    => '-40',
				'total'     => '-40',
				'total_tax' => 0,
			)
		);
		$fee->set_order_id( $order->get_id() );
		$fee->save();
		$order->add_item( $fee );
		$order->set_total( '60.00' );
		$order->set_status( 'pos-partial' );
		$order->save();

		// Act: one cent short.
		$short = $this->pay_by_form( $order, '59.99' );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( 'failure', $short['result']['result'] );
		$this->assertSame( '60.00', $fresh->get_total() );
		$this->assertCount( 1, $fresh->get_fees() );
		$this->assertSame( 'pos-partial', $fresh->get_status() );

		// Act: the balance.
		$settled = $this->pay_by_form( $order, '60.00' );

		// Assert.
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $settled['result']['result'] );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '60.00', $fresh->get_total() );
		$this->assertCount( 1, $fresh->get_fees() );
	}

	/**
	 * The POS API checkout refuses a short amount with its own message, before anything is written to the order.
	 */
	public function test_pos_checkout_action_short_amount_keeps_its_own_message_and_writes_nothing(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_total( '100.00' );
		$order->set_status( 'pos-open' );
		$order->save();

		// Act.
		$result = $this->gateway->process_pos_checkout_action(
			array(),
			'start',
			array( 'amount_tendered' => '60.00' ),
			$order
		);

		// Assert: on the same object, so a write made before the refusal would show.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpos_partial_cash_not_supported', $result->get_error_code() );
		$this->assertSame( 'Partial cash payments are not supported by the POS API checkout flow.', $result->get_error_message() );
		$this->assertNotSame( 'pos_cash', $order->get_payment_method() );
		$this->assertSame( '', $order->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertSame( '', $order->get_meta( '_pos_cash_change' ) );
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
	 * Pay an order through the order-pay form the way the browser posts it.
	 *
	 * @param WC_Order    $order    Order to pay.
	 * @param string|null $tendered Posted tendered amount, or null to leave the field out.
	 *
	 * @return array{result: array, errors: string[]}
	 */
	private function pay_by_form( WC_Order $order, ?string $tendered ): array {
		$_POST['pos_cash_payment_nonce_field'] = wp_create_nonce( 'pos_cash_payment_nonce' );
		if ( null !== $tendered ) {
			$_POST['pos-cash-tendered'] = $tendered;
		}

		try {
			$result = $this->gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['pos_cash_payment_nonce_field'], $_POST['pos-cash-tendered'] );
		}

		$errors = array_column( wc_get_notices( 'error' ), 'notice' );
		wc_clear_notices();

		return array(
			'result' => $result,
			'errors' => $errors,
		);
	}
}
