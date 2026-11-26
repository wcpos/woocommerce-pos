<?php
/**
 * Tender correction route tests (#3647).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Projection;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Services\Settings\Payment_Gateways_Section;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Adopt_Refund_Test_Gateway;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;

/** POST /orders/{id}/payments/{uuid}/correct. */
class Test_Payment_Correction extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as closure_tear_down;
	}
	use Money_Route_Envelope;

	/**
	 * The gateway filter online_gateway() installed, removed again in tearDown().
	 *
	 * @var \Closure|null
	 */
	private $online_gateways = null;

	/** Arm the fiscal sale and correction triggers, as the payments controller tests do. */
	public function setUp(): void {
		parent::setUp();
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		add_action( 'wcpos_payment_corrected', array( Fiscal_Record_Writers::instance(), 'handle_correction' ), 10, 4 );
	}

	/** Drop the online gateway fixture from WooCommerce's loaded list after the test that added it. */
	public function tearDown(): void {
		if ( null !== $this->online_gateways ) {
			remove_filter( 'woocommerce_payment_gateways', $this->online_gateways );
			\WC_Payment_Gateways::instance()->init();
			Payment_Gateways_Section::register_free_tier_rule();
			$this->online_gateways = null;
		}
		$this->closure_tear_down();
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
		$this->assertSame( $before + array( 'corrected_by' => $payment['id'] ), $this->without_commands( $this->row( $order, $card['id'] ) ) );
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

	/** The target must be a known method enabled for POS. */
	public function test_correct_invalid_target_method_returns_method_invalid(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );

		// Act / Assert: unknown.
		$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'no_such_gateway' ) );

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

	/** A corrected row and its R- report no refund capacity: the money moved to R+, and the uncorrected leg keeps its own. */
	public function test_correction_leaves_corrected_row_and_reversal_without_refund_capacity(): void {
		// Arrange.
		list( $order, $card, $cash ) = $this->split_sale();

		// Act.
		$response = $this->correct( $order, $cash['id'], 'pos_card' );
		$minus    = $this->reversal_of( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), $cash['id'] );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, Ledger::available_for_refund( $this->row( $order, $cash['id'] ) ) );
		$this->assertSame( 0, Ledger::available_for_refund( $minus ) );
		$this->assertSame( Money::minor( '60.00' ), Ledger::available_for_refund( $this->row( $order, $response->get_data()['payment']['id'] ) ) );
		$this->assertSame( Money::minor( '40.00' ), Ledger::available_for_refund( $this->row( $order, $card['id'] ) ) );
	}

	/** Pro's allocator walk: every captured row reporting capacity after a correction accepts a refund of that capacity. */
	public function test_every_row_with_refund_capacity_after_correction_accepts_a_refund_of_it(): void {
		// Arrange.
		list( $order, , $cash ) = $this->split_sale();
		$this->assertSame( 200, $this->correct( $order, $cash['id'], 'pos_card' )->get_status() );
		$order     = wc_get_order( $order->get_id() );
		$refund    = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '100.00',
				'refund_payment' => false,
			)
		);
		$remaining = Money::minor( '100.00' );
		$allocated = 0;
		$results   = array();

		// Act: newest row first, as Pro's allocator walks them.
		foreach ( array_reverse( Ledger::instance()->read( $order ) ) as $row ) {
			$capacity = Ledger::available_for_refund( $row );
			if ( 'captured' !== $row['status'] || $capacity <= 0 || $remaining <= 0 ) {
				continue;
			}
			$take                  = min( $capacity, $remaining );
			$results[ $row['id'] ] = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), Money::format( $take ) );
			$remaining            -= $take;
			$allocated            += is_wp_error( $results[ $row['id'] ] ) ? 0 : $take;
		}

		// Assert.
		foreach ( $results as $id => $result ) {
			$this->assertNotWPError( $result, $id );
		}
		$this->assertSame( Money::minor( '100.00' ), $allocated );
	}

	/** A correction of a correction leaves only its last R+ refundable. */
	public function test_correction_of_a_correction_leaves_only_the_last_leg_refundable(): void {
		// Arrange.
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$first                = $this->correct( $order, $cash['id'], 'pos_card' )->get_data()['payment'];

		// Act.
		$response = $this->correct( $order, $first['id'], 'pos_cash' );
		$rows     = Ledger::instance()->read( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, Ledger::available_for_refund( $this->row( $order, $cash['id'] ) ) );
		$this->assertSame( 0, Ledger::available_for_refund( $this->reversal_of( $rows, $cash['id'] ) ) );
		$this->assertSame( 0, Ledger::available_for_refund( $this->row( $order, $first['id'] ) ) );
		$this->assertSame( 0, Ledger::available_for_refund( $this->reversal_of( $rows, $first['id'] ) ) );
		$this->assertSame( Money::minor( '10.00' ), Ledger::available_for_refund( $this->row( $order, $response->get_data()['payment']['id'] ) ) );
	}

	/** On a 0-decimal store a 0.05 increment is below one minor unit, so every whole amount is on it. */
	public function test_correction_on_zero_decimal_store_with_sub_unit_increment_succeeds(): void {
		// Arrange.
		add_filter( 'wc_get_price_decimals', '__return_zero' );
		try {
			$order = $this->seeded( $this->row_of( 'pos_card', 'card', '60' ) );
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
		} finally {
			remove_filter( 'wc_get_price_decimals', '__return_zero' );
		}

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pos_cash', $response->get_data()['payment']['method_id'] );
		$this->assertSame( '60', $response->get_data()['payment']['amount'] );
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
	 * A completed 100.00 sale paid card 40.00 first, then cash 60.00.
	 *
	 * @return array{0: \WC_Order, 1: array, 2: array} The completed order, the card answer and the cash answer.
	 */
	private function split_sale(): array {
		$order = $this->create_pos_order( '100.00' );
		$card  = $this->record( $order, $this->payment( 'pos_card', '40.00' ) )->get_data()['payment'];
		$cash  = $this->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '60.00' ) )->get_data()['payment'];
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		return array( $order, $card, $cash );
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
			if ( ( $row['reverses'] ?? null ) === $id ) {
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
	 * @param array     $extra     Further body fields.
	 */
	private function correct( \WC_Order $order, string $id, string $method_id, array $extra = array() ): \WP_REST_Response {
		return $this->command( $this->payment_path( $order, $id ) . '/correct', $this->envelope( $order, array_merge( array( 'method_id' => $method_id ), $extra ) ) );
	}

	/**
	 * Create a refund through WooCommerce's own REST route.
	 *
	 * @param \WC_Order $order   Order to refund.
	 * @param array     $body    Request body.
	 * @param string    $version WooCommerce route version.
	 */
	private function wc_refund( \WC_Order $order, array $body, string $version = 'v3' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wc/' . $version . '/orders/' . $order->get_id() . '/refunds' );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * A captured row to stage without projecting the order's gateway.
	 *
	 * @param string $method_id Method.
	 * @param string $kind      Kind.
	 * @param string $amount    Captured amount.
	 */
	private function captured_row( string $method_id, string $kind, string $amount ): array {
		return array(
			'id'           => wp_generate_uuid4(),
			'method_id'    => $method_id,
			'kind'         => $kind,
			'capture_mode' => 'manual',
			'amount'       => $amount,
			'status'       => 'captured',
		);
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
	 * A 10.00 sale completed through the route in a session.
	 *
	 * @param array  $session   Session row.
	 * @param string $method_id Method.
	 * @return array{0: \WC_Order, 1: array} The completed order and the payment answer.
	 */
	private function completed_sale_in( array $session, string $method_id = 'pos_card' ): array {
		$order   = $this->create_pos_order( '10.00' );
		$payment = $this->payment( $method_id, '10.00' ) + array(
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

	/** T1: the customer paid online on the side; cash corrected to the gateway is a hand-recorded, unverified R+. */
	public function test_correct_cash_to_gateway_with_reference_records_unverified_manual_replacement(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );

		// Act.
		$response = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test', array( 'reference' => 'Stripe pi_123' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$payment = $response->get_data()['payment'];
		$this->assertSame( 'wcpos_adopt_refund_test', $payment['method_id'] );
		$this->assertSame( 'manual', $payment['capture_mode'] );
		$this->assertNull( $payment['provider'] );
		$this->assertTrue( $payment['unverified'] ?? null );
		$this->assertSame( 'Stripe pi_123', $payment['reference'] ?? null );
		$order  = wc_get_order( $order->get_id() );
		$stored = $this->row( $order, $payment['id'] );
		$this->assertSame( array(), $stored['provider_refs'] );
		$this->assertTrue( $stored['unverified'] ?? null );
		$this->assertSame( 'Stripe pi_123', $stored['reference'] ?? null );
		foreach ( array( $this->reversal_of( Ledger::instance()->read( $order ), $cash['id'] ), $this->row( $order, $cash['id'] ) ) as $row ) {
			$this->assertArrayNotHasKey( 'unverified', $row );
			$this->assertArrayNotHasKey( 'reference', $row );
		}
		$this->assertSame( 'wcpos_adopt_refund_test', $order->get_payment_method() );
		$this->assertSame( 'Online card', $order->get_payment_method_title() );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertContains( sprintf( 'WCPOS payment %s corrected: Cash to Online card (10.00), reference Stripe pi_123', $cash['id'] ), $notes );
	}

	/** T2: a provider-descriptor target and a webview-only target are accepted and marked; a target not enabled for POS is not. */
	public function test_correct_to_provider_or_webview_target_returns_unverified_replacement(): void {
		// Arrange.
		$this->online_gateway();
		Capture_Mode_Registry::instance()->register( 'correction_provider_test', Correction_Provider_Test_Handler::class );
		list( $first, $first_cash )   = $this->completed_sale( 'pos_cash', '10.00' );
		list( $second, $second_cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		list( $third, $third_cash )   = $this->completed_sale( 'pos_cash', '10.00' );
		$provider                     = static fn( $keys, $gateway ) => 'wcpos_adopt_refund_test' === $gateway->id ? array( 'correction_provider_test' ) : $keys;
		$webview                      = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'webview' : $mode;
		add_filter( 'wcpos_payment_method_capture_modes', $provider, 10, 2 );
		add_filter( 'wcpos_payment_method_capture_mode', $webview, 10, 2 );

		try {
			$this->assertSame( 'acme', Descriptor_Builder::instance()->get( 'wcpos_adopt_refund_test' )['capture']['provider'] );
			$this->assertSame( array( 'webview' ), array_column( Descriptor_Builder::instance()->get( 'pos_card' )['capture']['modes'], 'mode' ) );

			// Act.
			$to_provider = $this->correct( $first, $first_cash['id'], 'wcpos_adopt_refund_test' );
			$to_webview  = $this->correct( $second, $second_cash['id'], 'pos_card' );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $provider, 10 );
			remove_filter( 'wcpos_payment_method_capture_mode', $webview, 10 );
		}
		$this->gateway_settings(
			array(
				'pos_cash'                => array( 'enabled' => true ),
				'pos_card'                => array( 'enabled' => true ),
				'wcpos_adopt_refund_test' => array( 'enabled' => false ),
			)
		);
		$disabled = $this->correct( $third, $third_cash['id'], 'wcpos_adopt_refund_test' );

		// Assert.
		foreach ( array( $to_provider, $to_webview ) as $response ) {
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$payment = $response->get_data()['payment'];
			$this->assertSame( 'manual', $payment['capture_mode'] );
			$this->assertNull( $payment['provider'] );
			$this->assertTrue( $payment['unverified'] ?? null );
			$this->assertArrayNotHasKey( 'reference', $payment );
		}
		$this->assert_refused( 'method_invalid', $disabled );
	}

	/** T12: a provider target that also lists a manual mode is still marked. */
	public function test_correct_to_provider_target_that_also_lists_manual_returns_unverified_replacement(): void {
		// Arrange.
		$this->online_gateway();
		Capture_Mode_Registry::instance()->register( 'correction_provider_test', Correction_Provider_Test_Handler::class );
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$provider             = static fn( $keys, $gateway ) => 'wcpos_adopt_refund_test' === $gateway->id ? array( 'correction_provider_test', 'manual' ) : $keys;
		add_filter( 'wcpos_payment_method_capture_modes', $provider, 10, 2 );

		try {
			$descriptor = Descriptor_Builder::instance()->get( 'wcpos_adopt_refund_test' );
			$this->assertSame( 'acme', $descriptor['capture']['provider'] );
			$this->assertSame( array( 'device', 'manual' ), array_column( $descriptor['capture']['modes'], 'mode' ) );

			// Act.
			$response = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $provider, 10 );
		}

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$payment = $response->get_data()['payment'];
		$this->assertSame( 'manual', $payment['capture_mode'] );
		$this->assertNull( $payment['provider'] );
		$this->assertTrue( $payment['unverified'] ?? null );
	}

	/** T3: a plain manual target is never marked; a reference on it is stored and named in the note. */
	public function test_correct_to_plain_manual_target_with_reference_stores_it_without_unverified(): void {
		// Arrange.
		list( $plain_order, $plain_card ) = $this->completed_sale( 'pos_card', '10.00' );
		list( $order, $card )             = $this->completed_sale( 'pos_card', '10.00' );

		// Act.
		$plain = $this->correct( $plain_order, $plain_card['id'], 'pos_cash' );
		$with  = $this->correct( $order, $card['id'], 'pos_cash', array( 'reference' => 'Till slip 42' ) );

		// Assert.
		$this->assertSame( 200, $plain->get_status() );
		$this->assertSame( 200, $with->get_status() );
		$plain_row = $this->row( $plain_order, $plain->get_data()['payment']['id'] );
		$with_row  = $this->row( $order, $with->get_data()['payment']['id'] );
		$this->assertArrayNotHasKey( 'unverified', $plain_row );
		$this->assertArrayNotHasKey( 'reference', $plain_row );
		$this->assertArrayNotHasKey( 'unverified', $with_row );
		$this->assertSame( 'Till slip 42', $with_row['reference'] ?? null );
		$plain_notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $plain_order->get_id() ) ), 'content' );
		$with_notes  = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertContains( sprintf( 'WCPOS payment %s corrected: Card to Cash (10.00)', $plain_card['id'] ), $plain_notes );
		$this->assertContains( sprintf( 'WCPOS payment %s corrected: Card to Cash (10.00), reference Till slip 42', $card['id'] ), $with_notes );
	}

	/** T4: an unverified R+ refunds by hand, never through the gateway, and is never voided. */
	public function test_correct_unverified_replacement_refunds_manually_and_void_returns_409(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$order                = $this->reopen( $order );
		$response             = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$plus   = $response->get_data()['payment'];
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '4.00',
				'refund_payment' => false,
			)
		);

		// Act.
		$void  = $this->void( $order, $plus['id'] );
		$moved = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $plus['id'], $refund->get_id(), '4.00' );

		// Assert.
		$this->assertSame( 409, $void->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $void->get_data()['code'] );
		$this->assertIsArray( $moved );
		$this->assertSame( 'succeeded', $moved['refunds'][0]['status'] );
		$this->assertArrayHasKey( 'provider_ref', $moved['refunds'][0] );
		$this->assertNull( $moved['refunds'][0]['provider_ref'] );
		$this->assertSame( 0, Adopt_Refund_Test_Gateway::$calls );
		$this->assertSame( 'captured', $this->row( $order, $plus['id'] )['status'] );
	}

	/** T5: an unverified R+ corrected again to cash writes a pair that carries neither mark nor reference. */
	public function test_correct_unverified_replacement_again_to_cash_writes_unmarked_pair(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$first                = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test', array( 'reference' => 'pi_1' ) );
		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );
		$first = $first->get_data()['payment'];

		// Act.
		$again = $this->correct( $order, $first['id'], 'pos_cash' );

		// Assert.
		$this->assertSame( 200, $again->get_status(), (string) wp_json_encode( $again->get_data() ) );
		$this->assertSame( 'pos_cash', $again->get_data()['payment']['method_id'] );
		$this->assertSame( $first['id'], $again->get_data()['payment']['corrects'] );
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		foreach ( array( $this->row( $order, $again->get_data()['payment']['id'] ), $this->reversal_of( $rows, $first['id'] ) ) as $row ) {
			$this->assertArrayNotHasKey( 'unverified', $row );
			$this->assertArrayNotHasKey( 'reference', $row );
		}
		$this->assertTrue( $this->row( $order, $first['id'] )['unverified'] ?? null );
	}

	/** T6: in a split sale only the corrected leg moves; the other leg keeps its method, amount and refund capacity. */
	public function test_correct_split_cash_leg_to_gateway_leaves_card_leg_unchanged(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $card, $cash ) = $this->split_sale();
		$before                      = $this->without_commands( $this->row( $order, $card['id'] ) );

		// Act.
		$response = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$plus = $this->row( $order, $response->get_data()['payment']['id'] );
		$this->assertTrue( $plus['unverified'] ?? null );
		$this->assertSame( $before, $this->without_commands( $this->row( $order, $card['id'] ) ) );
		$this->assertSame( Money::minor( '40.00' ), Ledger::available_for_refund( $this->row( $order, $card['id'] ) ) );
		$this->assertSame( Money::minor( '60.00' ), Ledger::available_for_refund( $plus ) );
		$this->assertSame( 0, Ledger::available_for_refund( $this->row( $order, $cash['id'] ) ) );
		$this->assertSame( array( 'pos_card', 'wcpos_adopt_refund_test' ), array_column( Ledger::effective_rows( Ledger::instance()->read( wc_get_order( $order->get_id() ) ) ), 'method_id' ) );
	}

	/** T7: in the session the R+ counts under the gateway as a correction; the drawer goes down; no sale and no refund. */
	public function test_correct_cash_sale_to_gateway_in_session_counts_a_correction_and_lowers_cash(): void {
		// Arrange.
		$this->online_gateway();
		$session              = $this->closure_session( null, 'open' );
		list( $order, $cash ) = $this->completed_sale_in( $session, 'pos_cash' );
		$before               = Session_Figures::for_session( $session );
		$cash_sales           = $before->terms()['cash_sales'];
		$cash_refunds         = $before->terms()['cash_refunds'];
		$refund_count         = $before->refund_count();
		$expected_cash        = $before->expected()['cash'];

		// Act.
		$response = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$figures = Session_Figures::for_session( $session );
		$online  = $figures->payment_methods()['wcpos_adopt_refund_test'];
		$this->assertSame( '10.0000', $online['corrections'] );
		$this->assertSame( '0.0000', $online['sales'] );
		$this->assertSame( '0.0000', $online['refunds'] );
		$this->assertSame( '-10.0000', $figures->terms()['cash_corrections'] );
		$this->assertSame( Closure_Store::sum( array( $expected_cash, '-10' ) ), $figures->expected()['cash'] );
		$this->assertSame( $cash_sales, $figures->terms()['cash_sales'] );
		$this->assertSame( $cash_refunds, $figures->terms()['cash_refunds'] );
		$this->assertSame( $refund_count, $figures->refund_count() );
	}

	/** T8: a reprint shows one payment on the gateway, and the reference is never printed as a transaction id. */
	public function test_correct_cash_to_gateway_reprint_shows_one_gateway_payment_without_transaction_id(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$response             = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test', array( 'reference' => 'pi_123' ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( array( 'wcpos_adopt_refund_test' ), array_column( $data['payments'], 'method_id' ) );
		$this->assertSame( 'Online card', $data['payments'][0]['method_title'] );
		$this->assertSame( '', $data['payments'][0]['transaction_id'] );
		$this->assertSame( 10.0, $data['payments'][0]['amount'] );
		$this->assertSame( 10.0, $data['totals']['paid_total'] );
	}

	/** T9: the reference loses tags and line breaks, is capped at 100 characters, is not stored when empty, and must be a string. */
	public function test_correct_reference_is_sanitised_capped_and_must_be_a_string(): void {
		// Arrange.
		$cases = array(
			'<script>alert(1)</script>Slip <b>42</b>' => 'Slip 42',
			"Slip\n\t42\r"                            => 'Slip 42',
			str_repeat( 'é', 150 )                    => str_repeat( 'é', 100 ),
			'   '                                     => null,
			'<b></b>'                                 => null,
		);
		list( $typed_order, $typed_card ) = $this->completed_sale( 'pos_card', '10.00' );

		foreach ( $cases as $sent => $stored ) {
			list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );

			// Act.
			$response = $this->correct( $order, $card['id'], 'pos_cash', array( 'reference' => $sent ) );

			// Assert.
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$row = $this->row( $order, $response->get_data()['payment']['id'] );
			if ( null === $stored ) {
				$this->assertArrayNotHasKey( 'reference', $row, $sent );
			} else {
				$this->assertSame( $stored, $row['reference'] ?? null, $sent );
			}
		}

		// Act / Assert: not a string.
		$typed = $this->correct( $typed_order, $typed_card['id'], 'pos_cash', array( 'reference' => array( 'Slip 42' ) ) );
		$this->assertSame( 400, $typed->get_status() );
		$this->assertSame( 'rest_invalid_param', $typed->get_data()['code'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $typed_order->get_id() ) ) );
	}

	/** T10: the same command replays the same R+ with its reference; another reference under the same id conflicts and writes nothing. */
	public function test_correct_replay_with_reference_returns_same_replacement_and_other_reference_conflicts(): void {
		// Arrange.
		list( $order, $card ) = $this->completed_sale( 'pos_card', '10.00' );
		$path                 = $this->payment_path( $order, $card['id'] ) . '/correct';
		$body                 = $this->envelope(
			$order,
			array(
				'method_id' => 'pos_cash',
				'reference' => 'Slip 42',
			)
		);
		$first                = $this->command( $path, $body );
		$this->assertSame( 200, $first->get_status() );

		// Act.
		$replay   = $this->command( $path, $body );
		$conflict = $this->command( $path, array_merge( $body, array( 'reference' => 'Slip 43' ) ) );

		// Assert.
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( $first->get_data()['payment']['id'], $replay->get_data()['payment']['id'] );
		$this->assertSame( 'Slip 42', $replay->get_data()['payment']['reference'] ?? null );
		$this->assertSame( 422, $conflict->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $conflict->get_data()['code'] );
		$this->assertCount( 3, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 'Slip 42', $this->row( $order, $first->get_data()['payment']['id'] )['reference'] ?? null );
	}

	/** T11: a gateway-backed target answered as stored value is still refused. */
	public function test_correct_to_gateway_target_of_stored_value_kind_returns_method_invalid(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$stored_value         = static fn( $kind, $gateway ) => 'wcpos_adopt_refund_test' === $gateway->id ? 'stored_value' : $kind;
		add_filter( 'wcpos_payment_method_kind', $stored_value, 10, 2 );

		try {
			// Act / Assert.
			$this->assert_refused( 'method_invalid', $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' ) );
		} finally {
			remove_filter( 'wcpos_payment_method_kind', $stored_value, 10 );
		}
	}

	/** An explicit provider refund of a hand-recorded replacement writes nothing. */
	public function test_wc_refund_with_api_refund_of_unverified_replacement_returns_409_and_writes_nothing(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$correction           = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $correction->get_status() );
		$id     = $order->get_id();
		$before = Ledger::instance()->read( wc_get_order( $id ) );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ) );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wcpos_refund_unverified_payment', $data['code'] );
		$this->assertSame( 'unverified', $data['data']['reason'] );
		$this->assertSame( $correction->get_data()['payment']['id'], $data['data']['payment_id'] );
		$this->assertEmpty( wc_get_order( $id )->get_refunds() );
		$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $id ) ) );
		$this->assertSame( 0, Adopt_Refund_Test_Gateway::$calls );
	}

	/** Omitting api_refund still requests a provider refund on the v3 route. */
	public function test_wc_refund_without_api_refund_field_of_unverified_replacement_returns_409(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$correction           = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $correction->get_status() );
		$id     = $order->get_id();
		$before = Ledger::instance()->read( wc_get_order( $id ) );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00' ) );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wcpos_refund_unverified_payment', $data['code'] );
		$this->assertSame( 'unverified', $data['data']['reason'] );
		$this->assertSame( $correction->get_data()['payment']['id'], $data['data']['payment_id'] );
		$this->assertEmpty( wc_get_order( $id )->get_refunds() );
		$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $id ) ) );
		$this->assertSame( 0, Adopt_Refund_Test_Gateway::$calls );
	}

	/** The v2 refund route also refuses an unverified payment without writing. */
	public function test_wc_v2_refund_with_api_refund_of_unverified_replacement_returns_409(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$correction           = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $correction->get_status() );
		$id     = $order->get_id();
		$before = Ledger::instance()->read( wc_get_order( $id ) );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ), 'v2' );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wcpos_refund_unverified_payment', $data['code'] );
		$this->assertSame( 'unverified', $data['data']['reason'] );
		$this->assertSame( $correction->get_data()['payment']['id'], $data['data']['payment_id'] );
		$this->assertEmpty( wc_get_order( $id )->get_refunds() );
		$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $id ) ) );
		$this->assertSame( 0, Adopt_Refund_Test_Gateway::$calls );
	}

	/** WooCommerce can record a manual refund of the replacement without calling its gateway. */
	public function test_wc_refund_to_other_method_of_unverified_replacement_succeeds_without_gateway_call(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$correction           = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $correction->get_status() );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => false ) );

		// Assert.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
		$this->assertSame( 0, Adopt_Refund_Test_Gateway::$calls );
	}

	/** A verified row on the order's gateway can be refunded through the provider. */
	public function test_wc_refund_with_api_refund_of_verified_gateway_row_calls_gateway(): void {
		// Arrange.
		$this->online_gateway();
		$order = $this->create_pos_order( '10.00' );
		$order->set_payment_method( 'wcpos_adopt_refund_test' );
		$order->set_status( 'completed' );
		$order->save();
		Ledger_Seed::rows( $order, array( $this->captured_row( 'wcpos_adopt_refund_test', 'card', '10.00' ) ), false );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ) );

		// Assert.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, Adopt_Refund_Test_Gateway::$calls );
	}

	/** An unverified row on another method does not prevent a refund by the order's gateway. */
	public function test_wc_refund_with_unverified_row_of_another_method_calls_gateway(): void {
		// Arrange.
		$this->online_gateway();
		$order = $this->create_pos_order( '10.00' );
		$order->set_payment_method( 'wcpos_adopt_refund_test' );
		$order->set_status( 'completed' );
		$order->save();
		$other               = $this->captured_row( 'pos_card', 'card', '4.00' );
		$other['unverified'] = true;
		$verified            = $this->captured_row( 'wcpos_adopt_refund_test', 'card', '6.00' );
		Ledger_Seed::rows( $order, array( $other, $verified ), false );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ) );

		// Assert.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, Adopt_Refund_Test_Gateway::$calls );
	}

	/** An order with no ledger can still refund through its gateway. */
	public function test_wc_refund_with_api_refund_on_order_without_ledger_calls_gateway(): void {
		// Arrange.
		$this->online_gateway();
		$order = $this->create_pos_order( '10.00' );
		$order->set_payment_method( 'wcpos_adopt_refund_test' );
		$order->set_status( 'completed' );
		$order->save();
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ) );

		// Assert.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, Adopt_Refund_Test_Gateway::$calls );
	}

	/** A subscriber sees WooCommerce's permission error, not the unverified row. */
	public function test_wc_refund_by_user_without_permission_returns_core_permission_error_not_guard_code(): void {
		// Arrange.
		$this->online_gateway();
		list( $order, $cash ) = $this->completed_sale( 'pos_cash', '10.00' );
		$correction           = $this->correct( $order, $cash['id'], 'wcpos_adopt_refund_test' );
		$this->assertSame( 200, $correction->get_status() );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		// Act.
		$response = $this->wc_refund( $order, array( 'amount' => '4.00', 'api_refund' => true ) );

		// Assert.
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 'woocommerce_rest_cannot_create', $response->get_data()['code'] );
		$this->assertEmpty( wc_get_order( $order->get_id() )->get_refunds() );
	}

	/** Register the online card gateway fixture, enabled for POS beside cash and card, with its refund count reset. */
	private function online_gateway(): void {
		remove_all_filters( 'option_woocommerce_pos_settings_payment_gateways' ); // Model a Pro site: the free-tier rule is lifted the way Pro lifts it.
		Adopt_Refund_Test_Gateway::$calls = 0;
		$this->online_gateways            = static fn( array $gateways ): array => array_merge( $gateways, array( Adopt_Refund_Test_Gateway::class ) );
		add_filter( 'woocommerce_payment_gateways', $this->online_gateways );
		\WC_Payment_Gateways::instance()->init();
		$this->gateway_settings(
			array(
				'pos_cash'                => array( 'enabled' => true ),
				'pos_card'                => array( 'enabled' => true ),
				'wcpos_adopt_refund_test' => array( 'enabled' => true ),
			)
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A fixture stays with its tests, as Test_Ledger keeps its handlers.

/** A provider-backed capture mode: its descriptor names a provider, as a reader integration's does. */
class Correction_Provider_Test_Handler extends Abstract_Capture_Mode_Handler {
	/**
	 * Describe a device-mode method for the Acme provider.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array(
			'capture' => array(
				'mode'     => 'device',
				'provider' => 'acme',
			),
		);
	}
}
