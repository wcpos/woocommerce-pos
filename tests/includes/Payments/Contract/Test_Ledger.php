<?php
/**
 * Payment ledger tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Payment ledger tests. */
class Test_Ledger extends WCPOS_REST_Unit_Test_Case {
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

	/** Methods without offline recording capability cannot use the record route. */
	public function test_record_rejects_method_without_offline_recording_capability(): void {
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
			$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
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

	/** Captured money cannot be voided. */
	public function test_void_captured_row_is_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );

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
