<?php
/**
 * Tender correction route tests (#3647).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Projection;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;

/** POST /orders/{id}/payments/{uuid}/correct. */
class Test_Payment_Correction extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;
	use Money_Route_Envelope;

	/** Arm the fiscal sale and correction triggers, as the payments controller tests do. */
	public function setUp(): void {
		parent::setUp();
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		add_action( 'wcpos_payment_corrected', array( Fiscal_Record_Writers::instance(), 'handle_correction' ), 10, 4 );
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

	/** The target id is looked up exactly as sent, as record() looks it up: WooCommerce gateway ids may carry uppercase. */
	public function test_correct_method_id_is_matched_exactly_as_record_matches_it(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );

		// Act.
		$response = $this->correct( $order, $cash['id'], 'POS_CARD' );

		// Assert.
		$this->assert_refused( 'method_invalid', $response );
	}

	/** Stored value is never a correction's target. */
	public function test_correct_to_stored_value_target_returns_method_invalid(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$stored_value         = static fn( $kind, $gateway ) => 'pos_card' === $gateway->id ? 'stored_value' : $kind;
		add_filter( 'wcpos_payment_method_kind', $stored_value, 10, 2 );

		try {
			// Act / Assert.
			$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'pos_card' ) );
		} finally {
			remove_filter( 'wcpos_payment_method_kind', $stored_value, 10 );
		}
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
	 * A row without its command map, which a correction's entry rides, or the
	 * settle stamp that entry moves when the clock ticks between sale and correction.
	 *
	 * @param array $row Row.
	 */
	private function without_commands( array $row ): array {
		unset( $row['commands'], $row['updated_at_gmt'] );
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

	/** The customer's case end to end: a reopened card sale corrected to cash closes again as a cash sale. */
	public function test_correct_reopened_card_sale_then_close_records_a_cash_sale_and_cash_figures(): void {
		// Arrange.
		$session              = $this->closure_session( null, 'open' );
		list( $order, $card ) = $this->completed_sale_in( $session );
		$order                = $this->reopen( $order );
		$before               = Session_Figures::for_session( $session );
		$refund_count         = $before->refund_count();
		$cash_refunds         = $before->terms()['cash_refunds'];

		// Act.
		$response = $this->correct( $order, $card['id'], 'pos_cash' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$close = $this->close_in( $order, $session );

		// Assert.
		$this->assertSame( 200, $close->get_status(), wp_json_encode( $close->get_data() ) );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_History::of( $order );
		$this->assertCount( 2, $history->entries() );
		$sales = $this->fiscal_records( $order->get_id(), 'sale' );
		$this->assertCount( 2, $sales );
		$this->assertSame( $sales[1]['id'], $sales[0]['corrects_record_id'] );
		$payments = $sales[0]['payload']['payments'];
		$this->assertCount( 1, $payments );
		$this->assertSame( 'pos_cash', $payments[0]['method_id'] );
		$this->assertSame( 10.0, (float) $payments[0]['amount'] ); // The stored snapshot is JSON: 10.0 reads back as 10.
		$this->assertSame( 'pos_cash', $order->get_payment_method() );
		$figures = Session_Figures::for_session( $session );
		$this->assertSame( '0.0000', $figures->expected()['card'] );
		$this->assertSame( Closure_Store::sum( array( $figures->terms()['float'], '10' ) ), $figures->expected()['cash'] );
		$this->assertSame( $refund_count, $figures->refund_count() );
		$this->assertSame( $cash_refunds, $figures->terms()['cash_refunds'] );
	}

	/** In a closed session the correction is the late record, and the recompletion carries its legs no second time. */
	public function test_correct_reopened_card_sale_in_closed_session_writes_one_tender_correction_late_sale(): void {
		// Arrange.
		$session              = $this->closure_session();
		list( $order, $card ) = $this->completed_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$order = $this->reopen( $order );

		// Act.
		$plus  = $this->correct( $order, $card['id'], 'pos_cash' )->get_data()['payment'];
		$minus = $this->reversal_of( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), $card['id'] );
		$late  = $this->fiscal_records( $order->get_id(), 'late_sale' );
		$close = $this->close_in( $order, $session );

		// Assert.
		$this->assertCount( 1, $late );
		$this->assertSame( 'tender_correction', $late[0]['payload']['kind'] ?? null );
		$this->assertSame( 200, $close->get_status(), wp_json_encode( $close->get_data() ) );
		$this->assertCount( 2, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$corrections = 0;
		foreach ( $this->fiscal_records( $order->get_id(), 'late_sale' ) as $record ) {
			if ( 'tender_correction' === ( $record['payload']['kind'] ?? null ) ) {
				++$corrections;
				continue;
			}
			$this->assertSame( array(), array_intersect( array( $minus['id'], $plus['id'] ), array_column( $record['payload']['tender_rows'], 'id' ) ) );
		}
		$this->assertSame( 1, $corrections );
	}

	/** A reprint shows where the money is: the corrected tender only. */
	public function test_correct_card_to_cash_reprint_shows_one_cash_payment(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$this->assertSame( 200, $this->correct( $order, $card['id'], 'pos_cash' )->get_status() );

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( array( 'pos_cash' ), array_column( $data['payments'], 'method_id' ) );
		$this->assertSame( 10.0, $data['payments'][0]['amount'] );
		$this->assertSame( 10.0, $data['totals']['paid_total'] );
	}

	/** A1: the order's payment method and title follow a single leg's correction. */
	public function test_correct_single_cash_leg_to_card_projects_card_payment_method_and_title(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );

		// Act.
		$this->assertSame( 200, $this->correct( $order, $cash['id'], 'pos_card' )->get_status() );

		// Assert.
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos_card', $order->get_payment_method() );
		$this->assertSame( Descriptor_Builder::instance()->get( 'pos_card' )['title'], $order->get_payment_method_title() );
	}

	/** A2: a split sale's corrected leg projects as if it had been taken on the target method. */
	public function test_correct_split_card_leg_to_cash_projects_as_two_cash_legs(): void {
		// Arrange.
		$order = $this->create_pos_order( '10.00' );
		$card  = $this->record( $order, $this->payment( 'pos_card', '6.00' ) )->get_data()['payment'];
		$this->assertSame( 200, $this->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '4.00' ) )->get_status() );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$title    = static fn( string $method_id ): string => Descriptor_Builder::instance()->get( $method_id )['title'] ?? $method_id;
		$cash_leg = static fn( string $amount, string $at ): array => array(
			'id'              => wp_generate_uuid4(),
			'status'          => 'captured',
			'method_id'       => 'pos_cash',
			'kind'            => 'cash',
			'amount'          => $amount,
			'captured_at_gmt' => $at,
		);
		$expected = Projection::of( array( $cash_leg( '6.00', '2026-11-18 10:00:00' ), $cash_leg( '4.00', '2026-11-18 10:00:01' ) ), '10.00', 'completed', false, Ledger::MODE_AUTO, false, $title );

		// Act.
		$this->assertSame( 200, $this->correct( wc_get_order( $order->get_id() ), $card['id'], 'pos_cash' )->get_status() );

		// Assert.
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $expected['method'], $order->get_payment_method() );
		$this->assertSame( $expected['title'], $order->get_payment_method_title() );
	}

	/** A3: replaying the correction command changes nothing on the order. */
	public function test_correct_replay_leaves_method_title_modified_date_and_ledger_unchanged(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$body                 = $this->envelope( $order, array( 'method_id' => 'pos_cash' ) );
		$this->assertSame( 200, $this->command( $this->payment_path( $order, $card['id'] ) . '/correct', $body )->get_status() );
		$before = wc_get_order( $order->get_id() );
		$state  = static fn( \WC_Order $o ): array => array( $o->get_payment_method(), $o->get_payment_method_title(), (string) $o->get_date_modified(), Ledger::instance()->read( $o ) );

		// Act.
		$replay = $this->command( $this->payment_path( $order, $card['id'] ) . '/correct', $body );

		// Assert.
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( $state( $before ), $state( wc_get_order( $order->get_id() ) ) );
	}

	/** B1: a sale refunded in full without a ledger allocation is not corrected. */
	public function test_correct_on_fully_refunded_order_without_allocation_returns_refunded(): void {
		$this->assert_refund_refuses_correction( '10.00' );
	}

	/** B2: a sale refunded in part without a ledger allocation is not corrected. */
	public function test_correct_on_partly_refunded_order_without_allocation_returns_refunded(): void {
		$this->assert_refund_refuses_correction( '3.00' );
	}

	/**
	 * Refund a completed card 10.00 sale by the amount, unallocated, and assert the correction is refused.
	 *
	 * @param string $amount Refund amount.
	 */
	private function assert_refund_refuses_correction( string $amount ): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$refund               = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertSame( array(), $this->row( $order, $card['id'] )['refunds'] ?? array() );

		// Act.
		$response = $this->correct( wc_get_order( $order->get_id() ), $card['id'], 'pos_cash' );

		// Assert.
		$this->assert_refused( 'refunded', $response );
		$this->assertSame( $rows, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A 10.00 card sale completed through the route in a session.
	 *
	 * @param array $session Session row.
	 * @return array{0: \WC_Order, 1: array} The completed order and the payment answer.
	 */
	private function completed_sale_in( array $session ): array {
		$order   = $this->create_pos_order( '10.00' );
		$payment = $this->payment( 'pos_card', '10.00' ) + array(
			'register_id' => $session['register_id'],
			'session_id'  => $session['id'],
		);
		$body    = $this->envelope(
			$order,
			array(
				'payment'    => $payment,
				'provenance' => $this->provenance_of( $session ),
			)
		);
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', $body );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		return array( wc_get_order( $order->get_id() ), $response->get_data()['payment'] );
	}

	/**
	 * Close the sale through the route at the session's register.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session row.
	 */
	private function close_in( \WC_Order $order, array $session ): \WP_REST_Response {
		return $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', $this->envelope( $order, array( 'provenance' => $this->provenance_of( $session ) ) ) );
	}

	/**
	 * A body's provenance at the session's register.
	 *
	 * @param array $session Session row.
	 */
	private function provenance_of( array $session ): array {
		return array(
			'register_id' => $session['register_id'],
			'session_id'  => $session['id'],
		);
	}

	/**
	 * The order's fiscal records of a type, newest first.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $type     Record type.
	 */
	private function fiscal_records( int $order_id, string $type ): array {
		return ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order_id,
				'type'     => $type,
			)
		);
	}
}
