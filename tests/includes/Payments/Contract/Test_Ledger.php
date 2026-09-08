<?php
/**
 * Payment ledger tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WC_Tax;

/** Payment ledger tests. */
class Test_Ledger extends WCPOS_REST_Unit_Test_Case {

	public function setUp(): void {
		parent::setUp();
		Capture_Mode_Registry::instance()->register( 'integrity', Integrity_Handler::class );
		Integrity_Handler::$calls = 0;
		Integrity_Handler::$tips = 'none';
		add_filter( 'wcpos_payment_method_capture_mode', static function ( $mode, $gateway ) {
			return 'pos_card' === $gateway->id ? 'integrity' : $mode;
		}, 10, 2 );
		add_filter( 'woocommerce_pos_payment_gateways_settings', static function ( $settings ) {
			$settings['gateways']['pos_card']['enabled'] = true;
			return $settings;
		} );
	}

	public function test_intent_disabled_method_returns_403(): void {
		$order = $this->create_pos_order();
		add_filter( 'woocommerce_pos_payment_gateways_settings', static function ( $settings ) {
			$settings['gateways']['pos_card']['enabled'] = false;
			return $settings;
		}, 20 );
		$input = $this->payment( 'pos_card', '20.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_payment_method_disabled', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	public function test_intent_paid_order_returns_409_without_row(): void {
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );
		$input = $this->payment( 'pos_card', '20.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertCount( 1, Ledger::instance()->read( $order ) );
	}

	public function test_intent_excess_balance_does_not_persist_or_call_handler(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '93.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_amount_exceeds_balance', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
		$this->assertSame( 0, Integrity_Handler::$calls );
	}

	public function test_intent_handler_error_does_not_persist(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$this->assertSame( $error, Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $error ) ) );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	public function test_capture_handler_error_preserves_pending_row(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$this->assertSame( $error, Ledger::instance()->capture( $order, $input['id'], array( 'error' => $error ) ) );
		$this->assertSame( 'pending', Ledger::instance()->find( $order, $input['id'] )['status'] );
	}

	public function test_intent_replay_resumes_the_handler_and_returns_the_handoff(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger = Ledger::instance();
		$first = $ledger->intent( $order, $input['id'], $input, array() );
		$again = $ledger->intent( $order, $input['id'], $input, array() );
		// routes.md §4.3: a leg still in flight RESUMES at the provider rather than
		// repeating — the handler keys its provider call on the row id — so a replay
		// hands back the same handoff the app lost when the first response never
		// arrived. Returning an empty handoff would strand the till mid-payment.
		$this->assertSame( 2, Integrity_Handler::$calls );
		$this->assertSame( array( 'token' => 'secret' ), $first['handoff'] );
		$this->assertSame( $first['handoff'], $again['handoff'] );
		$this->assertSame( $first['payment']['id'], $again['payment']['id'] );
		$this->assertSame( 'pending', $again['payment']['status'] );
		$this->assertCount( 1, $ledger->read( $order ) );
		$this->assertArrayNotHasKey( 'handoff', $ledger->find( $order, $input['id'] ) );
		$this->assertSame( 'pending', $order->get_status() );
		$error = $ledger->intent( $order, $input['id'], array_merge( $input, array( 'amount' => '21.00' ) ), array() );
		$this->assertSame( 'wcpos_payment_conflict', $error->get_error_code() );
	}

	/** @dataProvider capture_confirmations */
	public function test_capture_verifies_provider_money( array $confirmed, string $tips, bool $mismatch ): void {
		$order = $this->create_pos_order();
		$product = ProductHelper::create_simple_product();
		$product->set_price( '92.95' );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->calculate_totals( false );
		Integrity_Handler::$tips = $tips;
		$input = $this->payment( 'pos_card', '92.95' );
		$ledger = Ledger::instance();
		$ledger->intent( $order, $input['id'], $input, array() );
		$result = $ledger->capture( $order, $input['id'], $confirmed );
		$stored = $ledger->find( wc_get_order( $order->get_id() ), $input['id'] );
		if ( $mismatch ) {
			$this->assertSame( 'wcpos_amount_mismatch', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( 'failed', $stored['status'] );
			$this->assertSame( 'amount_mismatch', $stored['failure_reason'] );
			$this->assertSame( '92.95', $stored['amount'] );
			$replay = $ledger->capture( $order, $input['id'], $confirmed );
			// assertEquals, not assertSame: to_wire() mints a fresh stdClass for the two
			// empty maps on every call, so identity comparison could never hold.
			$this->assertEquals( $result->get_error_data(), $replay->get_error_data() );
			$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
			$this->assertFalse( $order->is_paid() );
		} else {
			$this->assertSame( $confirmed['status'] ?? 'captured', $stored['status'] );
			$this->assertSame( $confirmed['amount'] ?? '92.95', $stored['amount'] );
			$this->assertSame( '0.00', $ledger->summary( $order )['balance'] );
			if ( 'on_reader' === $tips ) {
				$fees = array_values( $order->get_fees() );
				$this->assertCount( 1, $fees );
				$this->assertSame( 'Tip', $fees[0]->get_name() );
				$this->assertSame( 'none', $fees[0]->get_tax_status() );
				$this->assertSame( '7.05', $stored['tip'] );
				$this->assertSame( '100.00', $order->get_total() );
				$this->assertSame( 'wcpos_invalid_transition', $ledger->capture( $order, $input['id'], $confirmed )->get_error_code() );
				$this->assertCount( 1, $order->get_fees() );
			}
		}
	}

	public function capture_confirmations(): array {
		return array(
			'exact' => array( array( 'amount' => '92.95' ), 'none', false ),
			'authorized exact' => array( array( 'status' => 'authorized', 'amount' => '92.95' ), 'none', false ),
			'authorized mismatch' => array( array( 'status' => 'authorized', 'amount' => '90.00' ), 'none', true ),
			'implicit' => array( array(), 'none', false ),
			'tip' => array( array( 'amount' => '100.00' ), 'on_reader', false ),
			'over' => array( array( 'amount' => '100.00' ), 'none', true ),
			'under' => array( array( 'amount' => '90.00' ), 'none', true ),
			'currency' => array( array( 'currency' => 'EUR' ), 'on_reader', true ),
		);
	}

	public function test_capture_carves_tax_out_of_a_taxable_tip_and_the_order_stays_paid(): void {
		$old_calc_taxes    = get_option( 'woocommerce_calc_taxes' );
		$old_tax_based_on  = get_option( 'woocommerce_tax_based_on' );
		$old_base_location = get_option( 'woocommerce_default_country' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$base_tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'Base tax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		$tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'AU',
				'tax_rate_state'    => 'VIC',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'Tip tax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);

		try {
			$order = $this->create_pos_order();
			$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'billing' );
			$order->set_billing_country( 'AU' );
			$order->set_billing_state( 'VIC' );
			$product = ProductHelper::create_simple_product();
			$product->set_price( '92.95' );
			$product->set_tax_status( 'none' );
			$product->save();
			$order->add_product( $product );
			$order->calculate_totals( false );
			Integrity_Handler::$tips = 'on_reader';
			add_filter( 'wcpos_payment_tip_fee_taxable', '__return_true' );
			$input = $this->payment( 'pos_card', '92.95' );
			$ledger = Ledger::instance();
			$ledger->intent( $order, $input['id'], $input, array() );

			$row = $ledger->capture( $order, $input['id'], array( 'amount' => '100.00' ) );

			// The customer paid 100.00 and no more. A 7.05 tip at 10% is 6.41 + 0.64 tax,
			// carved out of the tip — never 7.05 + 0.71 on top, which would leave a
			// phantom 0.71 balance on an order the customer has fully paid.
			$fee = array_values( $order->get_fees() )[0];
			$this->assertSame( 'taxable', $fee->get_tax_status() );
			$this->assertSame( '6.41', wc_format_decimal( $fee->get_total(), 2 ) );
			$this->assertSame( '0.64', wc_format_decimal( $fee->get_total_tax(), 2 ) );
			$this->assertSame( '100.00', $order->get_total() );
			$this->assertSame( '100.00', $row['amount'] );
			$this->assertSame( '7.05', $row['tip'] );
			$this->assertSame( '0.00', $ledger->summary( $order )['balance'] );
			$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		} finally {
			WC_Tax::_delete_tax_rate( $tax_rate_id );
			WC_Tax::_delete_tax_rate( $base_tax_rate_id );
			update_option( 'woocommerce_calc_taxes', $old_calc_taxes );
			update_option( 'woocommerce_tax_based_on', $old_tax_based_on );
			update_option( 'woocommerce_default_country', $old_base_location );
		}
	}

	public function test_refund_replay_and_rollup_preserve_one_allocation(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->save();
		$first = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$again = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( $first, $again );
		$this->assertCount( 1, $again['refunds'] );
		$this->assertSame( '5.00', $again['refunded_amount'] );
		$this->assertSame( '5.00', $ledger->find( wc_get_order( $order->get_id() ), $row['id'] )['refunded_amount'] );
	}

	public function test_refund_pending_allocations_reserve_balance(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$row['refunds'] = array( array( 'id' => 123, 'amount' => '15.00', 'status' => 'pending', 'provider_ref' => null ) );
		$ledger->save( $order, array( $row ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->save();
		$error = $ledger->refund( $order, $row['id'], $refund->get_id(), '6.00' );
		$this->assertSame( 'wcpos_refund_not_allocatable', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$exact = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( '5.00', $exact['refunded_amount'] );
	}

	public function test_refund_rejects_foreign_parent(): void {
		$order = $this->create_pos_order();
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $this->create_pos_order()->get_id() );
		$refund->save();
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
	}

	public function test_row_schema_normalizes_expiry_and_seen_events(): void {
		$order = $this->create_pos_order();
		$row = $this->payment( 'pos_cash', '20.00', array( 'status' => 'pending', 'expires_at' => 'invalid', 'seen_events' => array( 'event', 123 ) ) );
		Ledger::instance()->save( $order, array( $row ) );
		$stored = Ledger::instance()->find( $order, $row['id'] );
		$this->assertNull( $stored['expires_at'] );
		$this->assertSame( array( 'event' ), $stored['seen_events'] );
		$new = Ledger::instance()->apply_transition( $stored, array( 'expires_at' => '2026-09-09T00:00:00Z', 'seen_events' => array( 'forged' ) ) );
		$this->assertSame( '2026-09-09T00:00:00Z', $new['expires_at'] );
		$this->assertSame( array( 'event' ), $new['seen_events'] );
	}

	/** A full cash tender completes and indexes the order. */
	public function test_record_single_cash_payment_completes_order_and_sets_payment_method(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) ) );

		// Assert.
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( '7.05', $row['change'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pos_cash', $order->get_payment_method() );
		$this->assertSame( 'Cash', $order->get_payment_method_title() );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
		$stored = json_decode( $order->get_meta( Ledger::META_KEY, true ), true );
		$this->assertSame( 1, $stored['schema'] );
		$this->assertCount( 1, $stored['payments'] );
	}

	/** Split tenders derive the largest method and composed title. */
	public function test_record_cash_then_card_split_derives_largest_tender_and_composed_title(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$ledger = Ledger::instance();

		// Act.
		$ledger->record( $order, $this->payment( 'pos_cash', '30.00' ) );
		$first = $ledger->summary( $order );
		$ledger->record( $order, $this->payment( 'pos_card', '62.95' ) );

		// Assert.
		$this->assertSame( 'pos-partial', $first['status'] );
		$this->assertSame( '30.00', $first['paid'] );
		$this->assertSame( '62.95', $first['balance'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pos_card', $order->get_payment_method() );
		$this->assertSame( 'Cash + Card', $order->get_payment_method_title() );
		$this->assertSame( array( 'pos_cash', 'pos_card' ), $this->index_values( $order ) );
	}

	/** Stored value never displaces a conventional payment method. */
	public function test_derive_payment_method_excludes_stored_value_tender(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$filter = static function ( string $kind, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'stored_value' : $kind;
		};
		add_filter( 'wcpos_payment_method_kind', $filter, 10, 2 );

		try {
			// Act.
			Ledger::instance()->record( $order, $this->payment( 'pos_card', '80.00' ) );
			Ledger::instance()->record( $order, $this->payment( 'pos_cash', '12.95' ) );

			// Assert.
			$this->assertSame( 'pos_cash', $order->get_payment_method() );
		} finally {
			remove_filter( 'wcpos_payment_method_kind', $filter, 10 );
		}
	}

	/** Authorized money counts toward the paid total. */
	public function test_derive_authorized_row_counts_toward_balance(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		Ledger::instance()->record( $order, $this->payment( 'pos_card', '92.95', array( 'status' => 'authorized' ) ) );
		$summary = Ledger::instance()->summary( $order );

		// Assert.
		$this->assertSame( '92.95', $summary['paid'] );
		$this->assertSame( '0.00', $summary['balance'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/** A paid order records and returns a stable refusal. */
	public function test_record_refuses_overpay_when_balance_is_zero(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '10.00' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'failed', $error->get_error_data()['payment']['status'] );
		$this->assertSame( 'order_already_paid', $error->get_error_data()['payment']['failure_reason'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
	}

	/** A paid legacy order without a ledger cannot accept another tender. */
	public function test_record_refuses_payment_for_paid_order_without_ledger(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_status( 'completed' );
		$order->save();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( '0.00', $error->get_error_data()['order']['balance'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/** Amounts above the current balance are refused and retained. */
	public function test_record_refuses_amount_above_balance(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '100.00' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_amount_exceeds_balance', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'failed', $error->get_error_data()['payment']['status'] );
	}

	/** Identical request IDs replay without another ledger row. */
	public function test_record_replay_with_same_id_returns_stored_row_without_duplicate(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );

		// Act.
		$first  = Ledger::instance()->record( $order, $input );
		$second = Ledger::instance()->record( $order, $input );

		// Assert.
		$this->assertSame( $first, $second );
		$this->assertCount( 1, Ledger::instance()->read( $order ) );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
	}

	/** Reusing a request ID for different money conflicts. */
	public function test_record_replay_with_different_amount_returns_conflict(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );
		Ledger::instance()->record( $order, $input );

		// Act.
		$error = Ledger::instance()->record( $order, array_merge( $input, array( 'amount' => '90.00' ) ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_payment_conflict', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** A stored UUID replay does not depend on the gateway's current capture mode. */
	public function test_record_replay_returns_stored_row_after_capture_mode_changes(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$first = Ledger::instance()->record( $order, $input );
		$filter = static function ( string $mode, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'webview' : $mode;
		};
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );

		try {
			// Act.
			$replay = Ledger::instance()->record( $order, $input );

			// Assert.
			$this->assertSame( $first, $replay );
			$this->assertCount( 1, Ledger::instance()->read( $order ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
		}
	}

	/** Only manual and offline-recordable methods may use the record route. */
	public function test_record_rejects_method_that_cannot_be_recorded(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$filter = static function ( string $mode, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'webview' : $mode;
		};
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );

		try {
			// Act.
			$error = Ledger::instance()->record( $order, $this->payment( 'pos_card', '20.00' ) );

			// Assert.
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_method_not_recordable', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
			$this->assertSame( array(), Ledger::instance()->read( $order ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
		}
	}

	/** Structured amounts return a validation error instead of reaching the formatter. */
	public function test_record_rejects_non_scalar_amount(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );
		$input['amount'] = array( '92.95' );

		// Act.
		$error = Ledger::instance()->record( $order, $input );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/** Structured tendered values return the same validation error as other invalid tender input. */
	public function test_record_rejects_non_scalar_tendered_amount(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95', array( 'tendered' => array( '100.00' ) ) ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/** The manual record route always stores its server-owned app source. */
	public function test_record_ignores_client_source(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00', array( 'source' => 'webview' ) ) );

		// Assert.
		$this->assertSame( 'app', $row['source'] );
	}

	/** Tendered is cash-only. */
	public function test_record_rejects_tendered_on_non_cash_kind(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_card', '20.00', array( 'tendered' => '20.00' ) ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
	}

	/** Voiding the only pending row reopens the order. */
	public function test_void_pending_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_cash', '10.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $row ) );
		$this->assertSame( 'pending', $order->get_status() );

		// Act.
		$voided = Ledger::instance()->void( $order, $row['id'], 'Customer cancelled' );

		// Assert.
		$this->assertSame( 'voided', $voided['status'] );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( array(), $this->index_values( $order ) );
	}

	/** Cancelling a split mid-way voids a captured cash row: the cashier hands the cash back. */
	public function test_void_captured_manual_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( 'pos-partial', $order->get_status() );

		// Act.
		$voided = Ledger::instance()->void( $order, $row['id'], 'Customer cancelled' );

		// Assert.
		$this->assertSame( 'voided', $voided['status'] );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( array(), $this->index_values( $order ) );
	}

	/** Once the order has completed, a captured cash leg is refunded, never voided. */
	public function test_void_captured_manual_row_on_completed_order_is_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', (string) $order->get_total() ) );
		$this->assertTrue( $order->is_paid() );

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Too late' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 'captured', Ledger::instance()->find( $order, $row['id'] )['status'] );
	}

	/** Money captured at a provider is refunded, never voided. */
	public function test_void_captured_provider_row_is_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'stripe_terminal', '20.00', array( 'status' => 'captured', 'kind' => 'card', 'capture_mode' => 'device' ) );
		Ledger::instance()->save( $order, array( $row ) );

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Too late' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** A pending row projects the WooCommerce pending status. */
	public function test_status_projection_pending_row_flips_order_to_pending(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'status' => 'pending', 'kind' => 'card', 'capture_mode' => 'manual' ) );

		// Act.
		Ledger::instance()->save( $order, array( $row ) );

		// Assert.
		$this->assertSame( 'pending', $order->get_status() );
	}

	/** Every live split method is written as an index value. */
	public function test_index_meta_matches_any_leg_of_a_split(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '30.00' ) );
		Ledger::instance()->record( $order, $this->payment( 'pos_card', '62.95' ) );

		// Assert.
		$this->assertContains( 'pos_cash', $this->index_values( $order ) );
		$this->assertContains( 'pos_card', $this->index_values( $order ) );
	}

	/** Corrupt ledger JSON is treated as an empty ledger. */
	public function test_read_ignores_corrupt_ledger_meta(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->update_meta_data( Ledger::META_KEY, 'not json' );
		$order->save();

		// Act / Assert.
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	/** The app writes the ledger as a typed-meta object; an already-decoded array reads the same as JSON. */
	public function test_read_accepts_array_form_ledger_meta(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = array(
			'id'           => 'a1b2c3d4-0000-4000-8000-000000000001',
			'method_id'    => 'pos_cash',
			'kind'         => 'cash',
			'capture_mode' => 'manual',
			'amount'       => '10.00',
			'status'       => 'captured',
		);
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => Ledger::SCHEMA, 'payments' => array( $row ) ) );
		$order->save();

		// Act.
		$rows = Ledger::instance()->read( $order );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pos_cash', $rows[0]['method_id'] );
	}

	/** A stored row without a capture mode returns the contract error from status. */
	public function test_status_rejects_row_without_capture_mode(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'status' => 'pending' ) );
		unset( $row['capture_mode'] );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => Ledger::SCHEMA, 'payments' => array( $row ) ) ) );
		$order->save();

		// Act.
		$error = Ledger::instance()->status( $order, $row['id'] );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( 501, $error->get_error_data()['status'] );
	}

	/** A stored row without a status cannot be voided. */
	public function test_void_rejects_row_without_status(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'capture_mode' => 'manual' ) );
		unset( $row['status'] );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => Ledger::SCHEMA, 'payments' => array( $row ) ) ) );
		$order->save();

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Malformed row' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** A voidable stored row without a capture mode returns the contract error. */
	public function test_void_rejects_row_without_capture_mode(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'status' => 'pending' ) );
		unset( $row['capture_mode'] );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => Ledger::SCHEMA, 'payments' => array( $row ) ) ) );
		$order->save();

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Malformed row' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( 501, $error->get_error_data()['status'] );
	}

	/** The multi-valued index meta as a plain list of method ids, in write order. */
	private function index_values( \WC_Order $order ): array {
		return array_values( wp_list_pluck( $order->get_meta( Ledger::INDEX_META_KEY, false ), 'value' ) );
	}

	/** Create an open WCPOS order with the contract test total. */
	private function create_pos_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}

	/** Build a valid record request. */
	private function payment( string $method_id, string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => $method_id,
				'amount'    => $amount,
			),
			$extra
		);
	}
}

/** Deterministic provider confirmations; no external payment operations. */
class Integrity_Handler extends Manual_Handler {
	public static $calls = 0;
	public static $tips = 'none';
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'integrity';
		$descriptor['capabilities']['tips'] = self::$tips;
		return $descriptor;
	}
	public function intent( array $row, array $context ) {
		++self::$calls;
		if ( isset( $context['error'] ) ) { return $context['error']; }
		$row['handoff'] = array( 'token' => 'secret' );
		return $row;
	}
	public function capture( array $row, array $context ) {
		return $context['error'] ?? array_merge( array( 'status' => 'captured' ), $context );
	}
}
