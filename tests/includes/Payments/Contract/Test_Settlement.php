<?php
/**
 * Provider settlement persistence tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\API\V2\Payments_Controller;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Payments\Contract\Settlement;

class Test_Settlement extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wcpos_pending_settlements' );
		Capture_Mode_Registry::instance()->register( 'settlement_test', Settlement_Test_Handler::class );
		Settlement_Test_Handler::$calls = 0;
		Settlement_Test_Handler::$descriptions = 0;
		Settlement_Test_Handler::$patch = array( 'status' => 'captured' );
		add_filter( 'wcpos_payment_method_capture_mode', static function ( $mode, $gateway ) {
			return 'pos_card' === $gateway->id ? 'settlement_test' : $mode;
		}, 10, 2 );
	}

	public function test_settlement_pending_row_captures_and_derives_order(): void {
		list( $order, $row ) = $this->pending();
		$this->assertTrue( wcpos_settle_payment( strtoupper( $row['id'] ), array(
			'status' => 'captured', 'amount' => '20.00', 'currency' => $row['currency'],
			'provider_refs' => array( 'transaction_id' => 'provider-1' ), 'receipt' => array( 'brand' => 'visa' ),
		) ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'captured', Ledger::instance()->find( $order, $row['id'] )['status'] );
		$this->assertSame( '20.00', Ledger::instance()->summary( $order )['paid'] );
		$this->assertSame( 'pos-partial', $order->get_status() );
		$this->assertSame( 'provider-1', $order->get_transaction_id() );
		$this->assertSame( array( 'brand' => 'visa' ), Ledger::instance()->find( $order, $row['id'] )['receipt'] );
	}

	public function test_settlement_duplicate_event_does_not_verify_or_write_again(): void {
		list( $order, $row ) = $this->pending();
		$patch = array( 'event_id' => 'evt-1', 'status' => 'captured' );
		$this->assertTrue( wcpos_settle_payment( $row['id'], $patch ) );
		$before = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$descriptions = Settlement_Test_Handler::$descriptions;
		$writes = 0;
		add_action( 'woocommerce_after_order_object_save', static function ( $saved ) use ( $order, &$writes ) {
			if ( $saved->get_id() === $order->get_id() ) { ++$writes; }
		} );
		// Even a conflicting payload for an already-seen event must not re-verify.
		$patch['amount'] = '999.00';
		$this->assertTrue( wcpos_settle_payment( $row['id'], $patch ) );
		$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( $descriptions, Settlement_Test_Handler::$descriptions );
		$this->assertSame( 0, Settlement_Test_Handler::$calls );
		$this->assertSame( 0, $writes );
	}

	public function test_settlement_unknown_row_is_consumed_on_record_and_replay_is_noop(): void {
		$order = $this->order();
		$id = wp_generate_uuid4();
		$this->assertTrue( wcpos_settle_payment( $id, array( 'status' => 'captured', 'event_id' => 'offline', 'receipt' => array( 'code' => 'ok' ) ) ) );
		$this->assertArrayHasKey( $id, get_option( 'wcpos_pending_settlements' ) );
		$input = array( 'id' => $id, 'method_id' => 'pos_card', 'amount' => '20.00', 'status' => 'authorized' );
		$row = Ledger::instance()->record( $order, $input );
		$this->assertIsArray( $row );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( array( 'offline' ), $row['seen_events'] );
		$this->assertSame( array( 'code' => 'ok' ), $row['receipt'] );
		$this->assertArrayNotHasKey( $id, get_option( 'wcpos_pending_settlements', array() ) );
		$this->assertSame( $row, Ledger::instance()->record( $order, $input ) );
	}

	public function test_settlement_parked_capture_applies_to_already_captured_arrival(): void {
		$order = $this->order();
		$id = wp_generate_uuid4();
		wcpos_settle_payment( $id, array( 'status' => 'captured', 'event_id' => 'settled' ) );
		$row = Ledger::instance()->record( $order, array( 'id' => $id, 'method_id' => 'pos_card', 'amount' => '20.00' ) );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( array( 'settled' ), $row['seen_events'] );
	}

	public function test_settlement_parked_mismatch_does_not_mark_order_paid(): void {
		$order = $this->order();
		$id = wp_generate_uuid4();
		wcpos_settle_payment( $id, array( 'status' => 'captured', 'amount' => '100.00' ) );
		$error = Ledger::instance()->record( $order, array( 'id' => $id, 'method_id' => 'pos_card', 'amount' => '92.95' ) );
		$this->assertSame( 'wcpos_amount_mismatch', $error->get_error_code() );
		$order = wc_get_order( $order->get_id() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertSame( 'failed', Ledger::instance()->find( $order, $id )['status'] );
	}

	public function test_settlement_parking_prunes_expired_and_drops_oldest_at_cap(): void {
		$expired = wp_generate_uuid4();
		$oldest = wp_generate_uuid4();
		$entries = array( $expired => array( 'patch' => array(), 'parked_at' => time() - Settlement::PARK_TTL - 1 ) );
		$entries[ $oldest ] = array( 'patch' => array(), 'parked_at' => time() - 1000 );
		for ( $i = 1; $i < Settlement::PARK_MAX; ++$i ) {
			$entries[ wp_generate_uuid4() ] = array( 'patch' => array(), 'parked_at' => time() - 10 );
		}
		update_option( 'wcpos_pending_settlements', $entries, false );
		$id = wp_generate_uuid4();
		$this->assertTrue( wcpos_settle_payment( $id, array( 'status' => 'captured' ) ) );
		$stored = get_option( 'wcpos_pending_settlements' );
		$this->assertCount( 200, $stored );
		$this->assertArrayNotHasKey( $expired, $stored );
		$this->assertArrayNotHasKey( $oldest, $stored );
		$this->assertArrayHasKey( $id, $stored );
		$this->assertArrayNotHasKey( 'wcpos_pending_settlements', wp_load_alloptions() );
	}

	public function test_settlement_expired_patch_is_not_applied_on_arrival(): void {
		$order = $this->order();
		$id = wp_generate_uuid4();
		update_option( 'wcpos_pending_settlements', array( $id => array(
			'patch' => array( 'status' => 'captured', 'receipt' => array( 'expired' => true ) ),
			'parked_at' => time() - Settlement::PARK_TTL - 1,
		) ), false );
		$row = Ledger::instance()->record( $order, array( 'id' => $id, 'method_id' => 'pos_card', 'amount' => '20.00' ) );
		$this->assertSame( array(), $row['receipt'] );
		$this->assertSame( array(), get_option( 'wcpos_pending_settlements' ) );
	}

	public function test_settlement_regression_is_refused_without_writing(): void {
		list( $order, $row ) = $this->pending();
		wcpos_settle_payment( $row['id'], array( 'status' => 'captured' ) );
		$before = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$error = wcpos_settle_payment( $row['id'], array( 'status' => 'pending', 'receipt' => array( 'bad' => true ) ) );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	public function test_settlement_amount_and_currency_mismatches_persist_failure(): void {
		foreach ( array( array( 'amount' => '21.00' ), array( 'currency' => 'BAD' ) ) as $patch ) {
			list( $order, $row ) = $this->pending();
			$error = wcpos_settle_payment( $row['id'], array_merge( $patch, array( 'status' => 'captured' ) ) );
			$this->assertSame( 'wcpos_amount_mismatch', $error->get_error_code() );
			$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] );
			$this->assertSame( 'failed', $row['status'] );
			$this->assertSame( 'amount_mismatch', $row['failure_reason'] );
			$this->assertSame( '20.00', $row['amount'] );
		}
	}

	public function test_settlement_invalid_uuid_is_rejected(): void {
		$error = wcpos_settle_payment( 'not-a-uuid', array() );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertFalse( get_option( 'wcpos_pending_settlements' ) );
	}

	public function test_settlement_event_history_is_capped_fifo(): void {
		list( $order, $row ) = $this->pending();
		for ( $i = 0; $i < 21; ++$i ) {
			$this->assertTrue( wcpos_settle_payment( $row['id'], array( 'event_id' => 'evt-' . $i ) ) );
		}
		$events = Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] )['seen_events'];
		$this->assertCount( 20, $events );
		$this->assertSame( 'evt-1', $events[0] );
		$this->assertSame( 'evt-20', $events[19] );
	}

	public function test_settlement_and_status_route_share_lock_and_write_once_in_sequence(): void {
		global $wp_rest_server;
		$previous = $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		( new Payments_Controller() )->register_routes();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		list( $order, $row ) = $this->pending();
		$request = new \WP_REST_Request( 'GET', '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $row['id'] . '/status' );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_header( 'X-WCPOS-Protocol', '2' );
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		$writes = 0;
		add_action( 'woocommerce_after_order_object_save', static function ( $saved ) use ( $order, &$writes ) {
			if ( $saved->get_id() === $order->get_id() ) { ++$writes; }
		} );
		try {
			$error = wcpos_settle_payment( $row['id'], array( 'status' => 'captured' ) );
			$this->assertSame( 'wcpos_payment_locked', $error->get_error_code() );
			$this->assertSame( 'wcpos_payment_locked', $wp_rest_server->dispatch( $request )->get_data()['code'] );
			$this->assertSame( 0, Settlement_Test_Handler::$calls );
			$this->assertSame( 0, $writes );
			$lock->release( $order->get_id() );
			$this->assertSame( 200, $wp_rest_server->dispatch( $request )->get_status() );
			$this->assertTrue( wcpos_settle_payment( $row['id'], array( 'status' => 'captured' ) ) );
			$this->assertSame( 1, $writes );
			$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			$lock->release( $order->get_id() );
			$wp_rest_server = $previous;
		}
	}

	public function test_payment_id_index_includes_terminal_rows_and_is_rebuilt(): void {
		$order = $this->order();
		$rows = array();
		foreach ( array( 'pending', 'authorized', 'captured', 'failed', 'voided' ) as $status ) {
			$rows[] = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_card', 'amount' => '1.00', 'status' => $status );
		}
		Ledger::instance()->save( $order, $rows );
		$this->assertSame( array_column( $rows, 'id' ), array_values( wp_list_pluck( $order->get_meta( Ledger::PAYMENT_ID_META_KEY, false ), 'value' ) ) );
		Ledger::instance()->save( $order, array( $rows[4] ) );
		$this->assertSame( array( $rows[4]['id'] ), array_values( wp_list_pluck( $order->get_meta( Ledger::PAYMENT_ID_META_KEY, false ), 'value' ) ) );
	}

	public function test_settlement_record_replay_finishes_projection_after_parking_lock_contention(): void {
		$order = $this->order();
		$input = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_card', 'amount' => '20.00' );
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( 0 ) );
		try {
			$error = Ledger::instance()->record( $order, $input );
			$this->assertSame( 'wcpos_payment_locked', $error->get_error_code() );
		} finally {
			$lock->release( 0 );
		}
		$this->assertIsArray( Ledger::instance()->record( $order, $input ) );
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_settlement_parked_updates_preserve_confirmation_and_refuse_regression(): void {
		$id = wp_generate_uuid4();
		$this->assertTrue( wcpos_settle_payment( $id, array( 'status' => 'captured', 'amount' => '99.00' ) ) );
		$this->assertTrue( wcpos_settle_payment( $id, array( 'receipt' => array( 'code' => 'ok' ) ) ) );
		$error = wcpos_settle_payment( $id, array( 'status' => 'pending' ) );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$order = $this->order();
		$error = Ledger::instance()->record( $order, array( 'id' => $id, 'method_id' => 'pos_card', 'amount' => '20.00' ) );
		$this->assertSame( 'wcpos_amount_mismatch', $error->get_error_code() );
	}

	private function order(): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}

	private function pending(): array {
		$order = $this->order();
		$row = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_card', 'capture_mode' => 'settlement_test', 'amount' => '20.00', 'status' => 'pending' );
		Ledger::instance()->save( $order, array( $row ) );
		return array( $order, Ledger::instance()->find( $order, $row['id'] ) );
	}
}

class Settlement_Test_Handler extends Manual_Handler {
	public static $calls = 0;
	public static $descriptions = 0;
	public static $patch = array();
	public function describe( \WC_Payment_Gateway $gateway ): array {
		++self::$descriptions;
		$descriptor = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'settlement_test';
		return $descriptor;
	}
	public function status( array $row ) {
		++self::$calls;
		return self::$patch;
	}
}
