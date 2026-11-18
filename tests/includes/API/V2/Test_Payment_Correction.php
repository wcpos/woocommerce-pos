<?php
/**
 * Tender correction route tests (#3647).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/** POST /orders/{id}/payments/{uuid}/correct. */
class Test_Payment_Correction extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	/** Arm the fiscal sale trigger, as the payments controller tests do. */
	public function setUp(): void {
		parent::setUp();
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
	}

	/** Card 10.00 corrected to cash appends R- and R+ and moves nothing else. */
	public function test_correct_card_to_cash_on_completed_order_appends_reversal_and_replacement(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$before   = $this->without_commands( $this->row( $order, $card['id'] ) );
		$rows     = Ledger::instance()->read( $order );
		$paid     = Ledger::instance()->paid( $rows );
		$balance  = Ledger::instance()->signed_balance( $order, $rows );
		$history  = count( Completion_History::of( $order )->entries() );

		// Act.
		$response = $this->correct( $order, $card['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$payment = $response->get_data()['payment'];
		$this->assertSame( 'pos_cash', $payment['method_id'] );
		$this->assertSame( 'cash', $payment['kind'] );
		$this->assertSame( '10.00', $payment['amount'] );
		$this->assertSame( 'tender_correction', $payment['entry'] );
		$this->assertSame( $card['id'], $payment['corrects'] );
		$this->assertSame( 'captured', $payment['status'] );
		$order = wc_get_order( $order->get_id() );
		$rows  = Ledger::instance()->read( $order );
		$this->assertCount( 3, $rows );
		$reversal = $this->reversal_of( $rows, $card['id'] );
		$this->assertSame( 'pos_card', $reversal['method_id'] );
		$this->assertSame( 'card', $reversal['kind'] );
		$this->assertSame( '-10.00', $reversal['amount'] );
		$this->assertSame( '-10.00', $reversal['tendered'] );
		$this->assertSame( '0.00', $reversal['change'] );
		$this->assertSame( 'tender_correction', $reversal['entry'] );
		$this->assertSame( 'captured', $reversal['status'] );
		$this->assertSame( '10.00', $this->row( $order, $payment['id'] )['tendered'] );
		$this->assertSame( '0.00', $this->row( $order, $payment['id'] )['change'] );
		$this->assertSame( $before, $this->without_commands( $this->row( $order, $card['id'] ) ) );
		$this->assertSame( $paid, Ledger::instance()->paid( $rows ) );
		$this->assertSame( $balance, Ledger::instance()->signed_balance( $order, $rows ) );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( $history, count( Completion_History::of( $order )->entries() ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertContains( sprintf( 'WCPOS payment %s corrected: Card to Cash (10.00)', $card['id'] ), $notes );
	}

	/** A replay answers the same R+ and writes nothing; the same id with another body conflicts. */
	public function test_correct_replay_returns_same_answer_and_conflict_on_other_body(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$body  = $this->envelope( $order, array( 'method_id' => 'pos_cash' ) );
		$first = $this->command( $this->payment_path( $order, $card['id'] ) . '/correct', $body );
		$this->assertSame( 200, $first->get_status() );

		// Act.
		$replay   = $this->command( $this->payment_path( $order, $card['id'] ) . '/correct', $body );
		$conflict = $this->command( $this->payment_path( $order, $card['id'] ) . '/correct', array_merge( $body, array( 'method_id' => 'cod' ) ) );

		// Assert.
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( $first->get_data()['payment']['id'], $replay->get_data()['payment']['id'] );
		$this->assertCount( 3, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 422, $conflict->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $conflict->get_data()['code'] );
	}

	/** R+ is corrected again; the original and R- are not. */
	public function test_correct_replacement_again_appends_pair_and_refuses_original_and_reversal(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$plus  = $this->correct( $order, $card['id'], 'pos_cash' )->get_data()['payment'];
		$minus = $this->reversal_of( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), $card['id'] );

		// Act.
		$again    = $this->correct( $order, $plus['id'], 'pos_card' );
		$original = $this->correct( $order, $card['id'], 'pos_card' );
		$reversal = $this->correct( $order, $minus['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $plus['id'], $again->get_data()['payment']['corrects'] );
		$this->assertSame( 'pos_card', $again->get_data()['payment']['method_id'] );
		$this->assertCount( 5, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assert_refused( 'already_corrected', $original );
		$this->assert_refused( 'is_reversal', $reversal );
	}

	/** A manual row the void path still reaches is voided and re-recorded instead. */
	public function test_correct_voidable_row_returns_voidable(): void {
		$order = $this->create_pos_order( '92.95' );
		$cash  = $this->record( $order, $this->payment( 'pos_cash', '20.00' ) )->get_data()['payment'];

		$this->assert_refused( 'voidable', $this->correct( $order, $cash['id'], 'pos_card' ) );
	}

	/** Only captured money is corrected. */
	public function test_correct_uncaptured_row_returns_not_captured(): void {
		$order = $this->seeded( $this->row_of( 'pos_card', 'card', '10.00', array( 'status' => 'voided' ) ) );

		$this->assert_refused( 'not_captured', $this->correct( $order, $this->only_id( $order ), 'pos_cash' ) );
	}

	/** A row carrying a refund allocation is not corrected. */
	public function test_correct_refunded_row_returns_refunded(): void {
		$refunds = array(
			array(
				'id'     => 123,
				'amount' => '1.00',
				'status' => 'succeeded',
			),
		);
		$order   = $this->seeded( $this->row_of( 'pos_card', 'card', '10.00', array( 'refunds' => $refunds ) ) );

		$this->assert_refused( 'refunded', $this->correct( $order, $this->only_id( $order ), 'pos_cash' ) );
	}

	/** Provider, device and webview money is not corrected. */
	public function test_correct_provider_row_returns_provider_row(): void {
		foreach ( array( array( 'capture_mode' => 'terminal' ), array( 'source' => 'webview' ), array( 'provider' => 'stripe' ) ) as $extra ) {
			$order = $this->seeded( $this->row_of( 'pos_card', 'card', '10.00', $extra ) );

			$this->assert_refused( 'provider_row', $this->correct( $order, $this->only_id( $order ), 'pos_cash' ) );
		}
	}

	/** The target must be a known, enabled, manual method with no provider. */
	public function test_correct_invalid_target_method_returns_method_invalid(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$webview              = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'webview' : $mode;

		// Act / Assert: unknown.
		$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'no_such_gateway' ) );

		// A provider method: pos_card answered as a webview gateway.
		add_filter( 'wcpos_payment_method_capture_mode', $webview, 10, 2 );
		try {
			$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'pos_card' ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $webview, 10 );
		}

		// Disabled.
		$this->gateway_settings(
			array(
				'pos_cash' => array( 'enabled' => true ),
				'pos_card' => array( 'enabled' => false ),
			)
		);
		$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'pos_card' ) );
	}

	/** A correction to the row's own method is refused. */
	public function test_correct_to_same_method_returns_same_method(): void {
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );

		$this->assert_refused( 'same_method', $this->correct( $order, $card['id'], 'pos_card' ) );
	}

	/** An amount off the target's cash rounding increment is refused, never re-rounded. */
	public function test_correct_amount_off_rounding_increment_returns_off_rounding_increment(): void {
		// Arrange.
		$order = $this->seeded( $this->row_of( 'pos_card', 'card', '10.03' ) );
		$this->gateway_settings(
			array(
				'pos_cash' => array(
					'enabled'            => true,
					'rounding_increment' => '0.05',
				),
				'pos_card' => array( 'enabled' => true ),
			)
		);

		// Act.
		$response = $this->correct( $order, $this->only_id( $order ), 'pos_cash' );

		// Assert.
		$this->assert_refused( 'off_rounding_increment', $response );
		$this->assertSame( '0.05', $response->get_data()['data']['increment'] );
		$this->assertSame( '10.03', $response->get_data()['data']['amount'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** Under a fiscal module the route is refused before anything is read. */
	public function test_correct_with_fiscal_module_active_returns_403(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		add_filter( 'wcpos_fiscal_module_active', '__return_true' );

		// Act.
		$response = $this->correct( $order, $card['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpos_tender_correction_unavailable', $response->get_data()['code'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** The route needs the money routes' capability. */
	public function test_correct_without_publish_shop_orders_returns_403(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$user                 = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );

		// Act.
		$response = $this->correct( $order, $card['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** The customer's case: a reopened sale's counted card row cannot be voided, but it can be corrected. */
	public function test_correct_counted_row_on_reopened_sale_returns_200_without_completion(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$order                = $this->reopen( $order );
		$history              = count( Completion_History::of( $order )->entries() );
		$void                 = $this->void( $order, $card['id'] );

		// Act.
		$response = $this->correct( $order, $card['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 409, $void->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $void->get_data()['code'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos_cash', $response->get_data()['payment']['method_id'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( $history, count( Completion_History::of( $order )->entries() ) );
	}

	/** Neither leg of a correction is voided, even where a plain manual row would be. */
	public function test_correct_legs_on_reopened_sale_void_returns_409(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$order                = $this->reopen( $order );
		$plus                 = $this->correct( $order, $card['id'], 'pos_cash' )->get_data()['payment'];
		$minus                = $this->reversal_of( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), $card['id'] );

		// Act / Assert.
		foreach ( array( $plus['id'], $minus['id'] ) as $id ) {
			$response = $this->void( $order, $id );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
			$this->assertSame( 'captured', $this->row( wc_get_order( $order->get_id() ), $id )['status'] );
		}
	}

	/** Money moved by a correction is refunded only through R+. */
	public function test_correct_refund_of_original_or_reversal_is_refused_and_of_replacement_succeeds(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$plus                 = $this->correct( $order, $card['id'], 'pos_cash' )->get_data()['payment'];
		$order                = wc_get_order( $order->get_id() );
		$minus                = $this->reversal_of( Ledger::instance()->read( $order ), $card['id'] );
		$refund               = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '5.00',
				'refund_payment' => false,
			)
		);

		// Act.
		$original = Ledger::instance()->refund( $order, $card['id'], $refund->get_id(), '5.00' );
		$reversal = Ledger::instance()->refund( $order, $minus['id'], $refund->get_id(), '5.00' );
		$moved    = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $plus['id'], $refund->get_id(), '5.00' );

		// Assert.
		$this->assertWPError( $original );
		$this->assertSame( 'wcpos_invalid_transition', $original->get_error_code() );
		$this->assertWPError( $reversal );
		$this->assertSame( 'wcpos_invalid_transition', $reversal->get_error_code() );
		$this->assertIsArray( $moved );
		$this->assertSame( 'succeeded', $moved['refunds'][0]['status'] );
	}

	/**
	 * Assert a 422 refusal with its reason.
	 *
	 * @param string            $reason   Expected reason.
	 * @param \WP_REST_Response $response Response.
	 */
	private function assert_refused( string $reason, \WP_REST_Response $response ): void {
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_payment_not_correctable', $response->get_data()['code'] );
		$this->assertSame( $reason, $response->get_data()['data']['reason'] );
	}

	/**
	 * A sale completed by one recorded payment of its whole total.
	 *
	 * @param string $method_id Method.
	 * @param string $amount    Total and payment amount.
	 * @return array{0: \WC_Order, 1: array} The completed order and the payment answer.
	 */
	private function completed_sale( string $method_id, string $amount ): array {
		$order    = $this->create_pos_order( $amount );
		$response = $this->record( $order, $this->payment( $method_id, $amount ) );
		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		return array( $order, $response->get_data()['payment'] );
	}

	/**
	 * A completed order holding one seeded row, without deriving.
	 *
	 * @param array $row Row.
	 */
	private function seeded( array $row ): \WC_Order {
		$order = $this->create_pos_order( $row['amount'] );
		$order->set_status( 'completed' );
		$order->save();
		return Ledger_Seed::rows( $order, array( $row ), false );
	}

	/**
	 * A seeded row.
	 *
	 * @param string $method_id Method.
	 * @param string $kind      Kind.
	 * @param string $amount    Amount.
	 * @param array  $extra     Extra fields.
	 */
	private function row_of( string $method_id, string $kind, string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'           => wp_generate_uuid4(),
				'method_id'    => $method_id,
				'kind'         => $kind,
				'capture_mode' => 'manual',
				'source'       => 'app',
				'amount'       => $amount,
				'currency'     => 'USD',
				'status'       => 'captured',
			),
			$extra
		);
	}

	/**
	 * The id of the order's only row.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function only_id( \WC_Order $order ): string {
		return Ledger::instance()->read( wc_get_order( $order->get_id() ) )[0]['id'];
	}

	/**
	 * Store the payment gateway settings.
	 *
	 * @param array $gateways Gateway settings by id.
	 */
	private function gateway_settings( array $gateways ): void {
		update_option( 'woocommerce_pos_settings_payment_gateways', array( 'gateways' => $gateways ) );
	}

	/**
	 * A stored row by id.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Row id.
	 */
	private function row( \WC_Order $order, string $id ): array {
		return (array) Ledger::instance()->find( wc_get_order( $order->get_id() ), $id );
	}

	/**
	 * The R- that reverses a row.
	 *
	 * @param array  $rows Rows.
	 * @param string $id   Reversed row id.
	 */
	private function reversal_of( array $rows, string $id ): array {
		foreach ( $rows as $row ) {
			if ( $id === ( $row['reverses'] ?? null ) ) {
				return $row;
			}
		}
		$this->fail( 'No reversal of ' . $id );
	}

	/**
	 * A row without its command map, which a correction's entry rides.
	 *
	 * @param array $row Row.
	 */
	private function without_commands( array $row ): array {
		unset( $row['commands'] );
		return $row;
	}

	/**
	 * Reopen an order in place, as the push lane's status change does.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function reopen( \WC_Order $order ): \WC_Order {
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'pos-open' );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Correct a row through the route.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $id        Row id.
	 * @param string    $method_id Target method.
	 */
	private function correct( \WC_Order $order, string $id, string $method_id ): \WP_REST_Response {
		return $this->command( $this->payment_path( $order, $id ) . '/correct', $this->envelope( $order, array( 'method_id' => $method_id ) ) );
	}

	/**
	 * POST a money command body.
	 *
	 * @param string $path Route path.
	 * @param array  $body Body.
	 */
	private function command( string $path, array $body ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( $path );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * A POS order in progress.
	 *
	 * @param string $total Order total.
	 */
	private function create_pos_order( string $total ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( $total );
		$order->save();
		return $order;
	}

	/**
	 * A payment request row.
	 *
	 * @param string $method_id Method.
	 * @param string $amount    Amount.
	 */
	private function payment( string $method_id, string $amount ): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => $method_id,
			'amount'    => $amount,
			'currency'  => 'USD',
		);
	}

	/**
	 * Record a payment through the route.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $payment Payment row.
	 */
	private function record( \WC_Order $order, array $payment ): \WP_REST_Response {
		return $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', $this->envelope( $order, array( 'payment' => $payment ) ) );
	}

	/**
	 * Void a row through the route.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Row id.
	 */
	private function void( \WC_Order $order, string $id ): \WP_REST_Response {
		return $this->command( $this->payment_path( $order, $id ) . '/void', $this->envelope( $order, array( 'reason' => 'Customer cancelled' ) ) );
	}

	/**
	 * A payment row's route prefix.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Row id.
	 */
	private function payment_path( \WC_Order $order, string $id ): string {
		return '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $id;
	}
}
