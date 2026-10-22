<?php
/**
 * Payments controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/** Payments controller tests. */
class Test_Payments_Controller extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	/**
	 * Captured messages keep audit assertions independent of logger output.
	 *
	 * @var array
	 */
	private array $logged_messages = array();

	/**
	 * Retain the filter so teardown removes only this test's callback.
	 *
	 * @var \Closure
	 */
	private $log_filter;

	/** Capture log attempts without writing to disk. */
	public function setUp(): void {
		parent::setUp();
		Route_Handler::$prompt       = null;
		Route_Handler::$answer       = null;
		Route_Handler::$refund_error = null;
		Logger::reset_dedup_state();
		$this->logged_messages = array();
		$this->log_filter = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'wcpos_logging', $this->log_filter, 10, 2 );
		// WP restores hooks between tests while the writer singleton survives: arm its sale trigger.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
	}

	/** Remove the capture filter after each test. */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', $this->log_filter, 10 );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/** Exclude dedup flush messages left by other tests. */
	private function payment_logs(): array {
		return array_values( array_filter( $this->logged_messages, static fn( $line ) => false !== strpos( $line, 'WCPOS payment' ) ) );
	}

	/** Recording cash returns the captured row and paid order summary. */
	public function test_record_cash_payment_returns_row_and_summary(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) );

		// Act.
		$response = $this->record( $order, $payment );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'captured', $data['payment']['status'] );
		$this->assertSame( '7.05', $data['payment']['change'] );
		$this->assertSame( '0.00', $data['order']['balance'] );
		$this->assertSame( 'completed', $data['order']['status'] );
		$this->assertSame( 'pos_cash', $data['order']['payment_method'] );
	}

	/** A swallowed Woo save failure must not be acknowledged as a recorded payment. */
	public function test_record_persistence_failure_returns_retryable_conflict(): void {
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '5.00' );
		$refuse = static function ( $saving ) use ( $order ): void {
			if ( $saving->get_id() === $order->get_id() ) {
				throw new \RuntimeException( 'Simulated refused data-store write' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $refuse, 1 );
		try {
			$response = $this->record( $order, $payment );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
			$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $refuse, 1 );
		}
		$this->assertSame( 200, $this->record( $order, $payment )->get_status() );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** D4: a failed ledger storage read is the retryable 409, never a 500, and stores nothing. */
	public function test_record_when_the_ledger_read_fails_answers_409(): void {
		// Arrange.
		global $wpdb;
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '5.00' );
		$broken  = 0;
		$break   = static function ( $sql ) use ( &$broken ) {
			if ( 0 === $broken && false !== strpos( $sql, 'AS meta_id, meta_value FROM' ) && false !== strpos( $sql, "'" . Ledger::META_KEY . "'" ) ) {
				++$broken;
				return 'SELECT no_such_column FROM no_such_table';
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $break );

		// Act.
		try {
			$response = $this->record( $order, $payment );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
		}

		// Assert.
		$this->assertSame( 1, $broken );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertNull( $this->stored_row( $order->get_id(), $payment['id'] ) );
	}

	/** Cashiers cannot forge handler events or an in-flight cancellation. */
	public function test_record_client_events_and_cancel_stamp_do_not_persist(): void {
		$order   = $this->create_pos_order();
		$payment = $this->payment(
			'pos_cash',
			'92.95',
			array(
				'events'            => array(
					array(
						't'       => '2026-10-14T10:00:00Z',
						'level'   => 'info',
						'message' => 'Forged approval',
					),
				),
				'void_requested_at' => '2026-10-14T10:00:00Z',
			)
		);
		$user_id = get_current_user_id();
		$cashier = self::factory()->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier );
		try {
			$response = $this->record( $order, $payment );
			$this->assertSame( 200, $response->get_status() );
			$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $payment['id'] );
			$this->assertSame( array(), $row['events'] );
			$this->assertSame( null, $row['void_requested_at'] );
			$request = $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' );
			$status  = $this->server->dispatch( $request );
			$this->assertSame( 200, $status->get_status() );
			$this->assertSame( array(), $status->get_data()['payment']['events'] );
		} finally {
			wp_set_current_user( $user_id );
		}
	}

	/** An unknown method alias cannot turn a card leg into drawer cash. */
	public function test_record_unknown_method_key_cannot_reclassify_a_card_leg(): void {
		$order    = $this->create_pos_order();
		$payment  = $this->payment( 'pos_card', '92.95', array( 'method' => 'cash' ) );
		$response = $this->record( $order, $payment );

		$this->assertSame( 200, $response->get_status() );
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $payment['id'] );
		$this->assertFalse( array_key_exists( 'method', $row ) );
		$totals = Session_Figures::tender_totals( array( $row ) );
		$this->assertSame( '92.9500', $totals['card'] );
		$this->assertSame( '0.0000', $totals['cash'] );
	}

	/** Only provider-confirmed tips affect the amount compared on replay. */
	public function test_record_client_tip_is_ignored_and_the_identical_replay_succeeds(): void {
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95', array( 'tip' => '7.05' ) );
		$first   = $this->record( $order, $payment );
		$second  = $this->record( $order, $payment );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$order = wc_get_order( $order->get_id() );
		$rows  = Ledger::instance()->read( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( null, $rows[0]['tip'] );
		$this->assertSame( '92.95', $order->get_total() );
	}

	/** The record boundary preserves the till's documented device facts. */
	public function test_record_keeps_the_tills_device_facts(): void {
		$order = $this->create_pos_order();
		$facts = array(
			'recorded_offline' => true,
			'transport'        => 'offline',
			'created_at_gmt'   => '2026-10-13T08:00:00Z',
			'captured_at_gmt'  => '2026-10-13T08:00:05Z',
			'provider_refs'    => array( 'transaction_id' => 'T-1' ),
			'receipt'          => array( 'card_brand' => 'visa' ),
		);
		$payment  = $this->payment( 'pos_cash', '92.95', $facts );
		$response = $this->record( $order, $payment );

		$this->assertSame( 200, $response->get_status() );
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $payment['id'] );
		foreach ( $facts as $key => $expected ) {
			$this->assertSame( $expected, $row[ $key ], $key );
		}
	}

	/** An offline authorization recorded through the route keeps the device's approval time. */
	public function test_record_route_keeps_authorized_at_gmt_on_an_authorized_row(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment(
			'pos_card',
			'92.95',
			array(
				'status'            => 'authorized',
				'authorized_at_gmt' => '2026-10-13T08:00:00Z',
			)
		);

		// Act.
		$response = $this->record( $order, $payment );
		$data     = $response->get_data();

		// Assert: the answer and storage carry the device's instant, not a server stamp.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'authorized', $data['payment']['status'] );
		$stored = $this->stored_row( $order->get_id(), $payment['id'] );
		$this->assertNotNull( $stored );
		foreach ( array( $data['payment']['authorized_at_gmt'], $stored['authorized_at_gmt'] ) as $authorized_at ) {
			$this->assertIsString( $authorized_at );
			$this->assertSame( strtotime( '2026-10-13T08:00:00Z' ), strtotime( $authorized_at ) );
			$this->assertFalse( time() - strtotime( $authorized_at ) <= 60 );
		}
	}

	/** An identical id replays without duplicating the ledger row. */
	public function test_record_replay_same_id_returns_same_row_without_duplicate(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );

		// Act.
		$calls = array();
		$listener = static function ( $hook_order, $row ) use ( &$calls ) {
			$calls[] = array( $hook_order, $row );
		};
		add_action( 'wcpos_payment_recorded', $listener, 10, 2 );
		try {
			$first = $this->record( $order, $payment );
			$this->assertSame( array( sprintf( 'WCPOS payment %s recorded on order #%d: 92.95 USD via pos_cash (captured) by cashier #%d', $payment['id'], $order->get_id(), get_current_user_id() ) ), $this->payment_logs() );
			// A new request resets dedup, so it cannot hide an erroneous replay log.
			Logger::reset_dedup_state();
			$second = $this->record( $order, $payment );
			$this->assertCount( 1, $this->payment_logs() );
			$this->assertCount( 1, $calls );
			$this->assertInstanceOf( \WC_Order::class, $calls[0][0] );
			$this->assertSame( $order->get_id(), $calls[0][0]->get_id() );
			$this->assertSame( $payment['id'], $calls[0][1]['id'] );
			$this->assertSame( 'captured', $calls[0][1]['status'] );
		} finally {
			remove_action( 'wcpos_payment_recorded', $listener, 10 );
		}

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['payment']['id'], $second->get_data()['payment']['id'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** A second payment on a paid order returns its stable failed row. */
	public function test_record_overpay_returns_409_with_failed_row(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Act.
		$this->logged_messages = array();
		$payment = $this->payment( 'pos_cash', '10.00' );
		$calls = array();
		$listener = static function ( $hook_order, $row ) use ( &$calls ) {
			$calls[] = array( $hook_order, $row );
		};
		add_action( 'wcpos_payment_refused', $listener, 10, 2 );
		try {
			$response = $this->record( $order, $payment );
		} finally {
			remove_action( 'wcpos_payment_refused', $listener, 10 );
		}
		$data = $response->get_data();
		$this->assertSame( array( sprintf( 'WCPOS payment %s refused on order #%d: order_already_paid (10.00 USD against 0.00 owed)', $payment['id'], $order->get_id() ) ), $this->payment_logs() );
		$this->assertCount( 1, $calls );
		$this->assertInstanceOf( \WC_Order::class, $calls[0][0] );
		$this->assertSame( $order->get_id(), $calls[0][0]->get_id() );
		$this->assertSame( $payment['id'], $calls[0][1]['id'] );
		$this->assertSame( 'failed', $calls[0][1]['status'] );
		$this->assertSame( 'order_already_paid', $calls[0][1]['failure_reason'] );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $data['code'] );
		$this->assertSame( 'failed', $data['data']['payment']['status'] );
		$this->assertSame( 'order_already_paid', $data['data']['payment']['failure_reason'] );
	}

	/** A reused id with different money is the family's body conflict (spec §7 decision 12). */
	public function test_record_conflicting_replay_is_422_command_conflict(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->record( $order, array_merge( $payment, array( 'amount' => '21.00' ) ) );

		// Assert.
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( $payment['id'], $response->get_data()['data']['payment']['id'] );
		$this->assertSame( '20.00', $response->get_data()['data']['payment']['amount'] );
	}

	/** Unknown payment methods return the ledger error. */
	public function test_record_unknown_method_returns_404(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$response = $this->record( $order, $this->payment( 'missing_gateway', '20.00' ) );

		// Assert.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_payment_method_not_found', $response->get_data()['code'] );
	}

	/** Status returns the matching row and order summary. */
	public function test_status_returns_row_and_summary(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $payment['id'], $data['payment']['id'] );
		$this->assertSame( '20.00', $data['order']['paid'] );
	}

	/** Status returns 404 for an unknown UUID. */
	public function test_status_unknown_uuid_returns_404(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, wp_generate_uuid4() ) . '/status' ) );

		// Assert.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_payment_not_found', $response->get_data()['code'] );
	}

	/** Voiding the only pending row returns the order to POS open. */
	public function test_void_pending_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'pos_cash',
			'10.00',
			array(
				'status'       => 'pending',
				'kind'         => 'cash',
				'capture_mode' => 'manual',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );

		// Act.
		$response = $this->void( $order, $row['id'] );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'voided', $data['payment']['status'] );
		$this->assertSame( 'pos-open', $data['order']['status'] );
	}

	/** Captured rows cannot be voided. */
	public function test_void_captured_cash_leg_mid_split_returns_200_voided(): void {
		// Arrange: a partial cash leg on an order still in progress (cancel mid-split).
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$this->logged_messages = array();
		$calls = array();
		$listener = static function ( $hook_order, $row, $applied, $reason ) use ( &$calls ) {
			$calls[] = array( $hook_order->get_id(), $row['id'], $applied['status'], $reason );
		};
		add_action( 'wcpos_payment_voided', $listener, 10, 4 );
		try {
			$response = $this->void( $order, $payment['id'] );
		} finally {
			remove_action( 'wcpos_payment_voided', $listener, 10 );
		}

		// Assert.
		$this->assertSame( array( sprintf( 'WCPOS payment %s voided on order #%d: 20.00 USD: Customer cancelled', $payment['id'], $order->get_id() ) ), $this->payment_logs() );
		$this->assertSame( array( array( $order->get_id(), $payment['id'], 'voided', 'Customer cancelled' ) ), $calls );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'voided', $response->get_data()['payment']['status'] );
	}

	/** Completed cash sales must be refunded rather than voided. */
	public function test_void_captured_cash_leg_on_completed_order_returns_409(): void {
		// Arrange: cash covering the whole order completes it; a void is no longer the path.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', (string) $order->get_total() );
		$this->record( $order, $payment );

		// Act.
		$response = $this->void( $order, $payment['id'] );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
	}

	/** Payment routes additionally require order publishing capability. */
	public function test_routes_require_publish_shop_orders(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );

		// Act.
		$response = $this->record( $order, $this->payment( 'pos_cash', '20.00' ) );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** Close needs the payment routes' capability. */
	public function test_close_requires_publish_shop_orders(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** A covered open order completes on close. */
	public function test_close_on_a_covered_open_order_completes_it(): void {
		// Arrange: money recorded without deriving.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$stored = wc_get_order( $order->get_id() );
		$this->assertTrue( $stored->is_paid() );
		$this->assertNotNull( $stored->get_date_paid( 'edit' ) );
		$this->assertSame( 1, $response->get_data()['completion']['n'] );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
	}

	/** A reopened sale closed with its lines unchanged is completion 2, at the same total. */
	public function test_close_on_a_reopened_sale_with_unchanged_lines_writes_completion_2(): void {
		// Arrange.
		list( $order, $product ) = $this->paid_sale_of( array( '50.00' ), 2 );
		$date_paid = $order->get_date_paid( 'edit' )->getTimestamp();
		$this->assertSame( 8, wc_get_product( $product[0] )->get_stock_quantity() );
		$order->set_status( 'pos-open' );
		$order->save();
		$announced = array();
		$listener  = static function ( $completed, $n ) use ( &$announced ): void {
			$announced[] = $n;
		};
		add_action( 'wcpos_sale_completed', $listener, 5, 2 );

		// Act.
		$response = $this->close( $order );
		remove_action( 'wcpos_sale_completed', $listener, 5 );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$stored  = wc_get_order( $order->get_id() );
		$history = Completion_History::of( $stored )->entries();
		$this->assertTrue( $stored->is_paid() );
		$this->assertSame( $date_paid, $stored->get_date_paid( 'edit' )->getTimestamp() );
		$this->assertSame( 8, wc_get_product( $product[0] )->get_stock_quantity() );
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertSame( array( 2 ), $announced );
		$this->assertSame(
			array(
				'n' => 2,
				'id' => $history[1]['id'],
			),
			$response->get_data()['completion']
		);
		$records = new Fiscal_Record_Store();
		$one     = $records->find_sale( $order->get_id(), $history[0]['id'] );
		$two     = $records->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertSame( $one['id'], $two['corrects_record_id'] );
		$this->assertSame( '0.00', $two['payload']['fiscal']['adjustment'] );
	}

	/** Retained money above a reduced total still completes, and the excess reads as refund_due. */
	public function test_close_on_a_reopened_sale_with_a_reduced_total_completes_with_refund_due(): void {
		// Arrange: 100.00 captured on 80.00 + 20.00; reopened and the 20.00 line removed.
		list( $order ) = $this->paid_sale_of( array( '80.00', '20.00' ), 1 );
		$order->set_status( 'pos-open' );
		$lines = array_keys( $order->get_items() );
		$order->remove_item( $lines[1] );
		$order->calculate_totals( false );
		$order->save();

		// Act.
		$response = $this->close( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['completion']['n'] );
		$this->assertSame( '20.00', $response->get_data()['order']['refund_due'] );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$two     = ( new Fiscal_Record_Store() )->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertSame( '-20.00', $two['payload']['fiscal']['adjustment'] );
	}

	/** An uncovered order is refused and nothing is written. */
	public function test_close_with_balance_outstanding_is_refused_and_writes_nothing(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '50.00' ) ), false );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_balance_outstanding', $response->get_data()['code'] );
		$this->assertSame( '42.95', $response->get_data()['data']['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertSame( array(), Completion_History::of( $stored )->entries() );
	}

	/** Money in motion refuses a close, naming the row. */
	public function test_close_with_a_pending_row_is_refused_in_flight(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$pending = array_merge( $this->captured_row( '92.95' ), array( 'status' => 'pending' ) );
		Ledger::instance()->save( $order, array( $pending ), false );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_payment_in_flight', $response->get_data()['code'] );
		$this->assertSame( $pending['id'], $response->get_data()['data']['payment_id'] );
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
	}

	/** Closing a paid sale again replays its completion and writes nothing. */
	public function test_close_on_a_paid_order_replays_the_last_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'n' => 1,
				'id' => $history[0]['id'],
			),
			$response->get_data()['completion']
		);
		$this->assertSame( $history, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
	}

	/** A cancelled sale is not closed: its completion no longer stands, so nothing replays. */
	public function test_close_on_a_cancelled_sale_is_an_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	/** A completion standing on a configured on-hold status is replayed by close, refund or not. */
	public function test_close_on_a_standing_on_hold_completion_replays_it(): void {
		// Arrange: Cash's POS Order Status stored as On hold; completed; 20.00 refunded.
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'enabled'      => true,
						'order_status' => 'wc-on-hold',
					),
				),
			)
		);
		$order = $this->create_pos_order();
		$row   = $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_data()['payment'];
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => '20.00',
				'refund_payment' => false,
			)
		);
		$this->assertIsArray( Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '20.00' ) );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame(
			array(
				'n' => 1,
				'id' => $history[0]['id'],
			),
			$response->get_data()['completion']
		);
		$this->assertSame( $history, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
	}

	/** A sale paid before the completion history existed stands: close replays it with no completion and writes nothing. */
	public function test_close_on_a_legacy_paid_sale_replays_with_no_completion(): void {
		// Arrange: paid, no ledger rows, no history.
		$order = $this->create_pos_order();
		$paid  = wc_get_order( $order->get_id() );
		$paid->set_date_paid( time() );
		$paid->set_status( 'processing' );
		$paid->save();
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertNull( $response->get_data()['completion'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
	}

	/** A status poll is no money command: its body's provenance is not the completion's. */
	public function test_a_status_poll_that_completes_the_order_names_the_rows_register_not_its_body(): void {
		// Arrange: a pending card leg taken at register R.
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static fn() => 'route_test' );
		$register = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
		$order    = $this->create_pos_order();
		$leg      = array(
			'id'           => wp_generate_uuid4(),
			'method_id'    => 'pos_cash',
			'capture_mode' => 'route_test',
			'amount'       => '92.95',
			'status'       => 'pending',
			'register_id'  => $register,
		);
		Ledger::instance()->save( $order, array( $leg ), false );
		Route_Handler::$answer = array( 'status' => 'captured' );

		// Act: the poll carries another register's provenance.
		$request = $this->wp_rest_get_request( $this->payment_path( $order, $leg['id'] ) . '/status' );
		$request->set_body_params( array( 'provenance' => array( 'register_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' ) ) );
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( $register, $history[0]['register'] );
	}

	/** When the new completion cannot be recorded, close reports none rather than the reopened one. */
	public function test_close_whose_completion_cannot_be_recorded_reports_no_completion(): void {
		// Arrange: a paid sale reopened (entry 1 marked); writes to the history will fail.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$refuse = static function ( $check, $meta_id, $value, $key ) {
			return Completion_Stamp::META_KEY === $key ? true : $check;
		};
		add_filter( 'update_post_metadata_by_mid', $refuse, 10, 4 );

		// Act.
		try {
			$response = $this->close( $order );
		} finally {
			remove_filter( 'update_post_metadata_by_mid', $refuse, 10 );
		}

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['completion'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'could not be recorded', $notes );
	}

	/** Close alone may complete an on-hold order its payments cover. */
	public function test_close_on_an_on_hold_covered_order_completes_it(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_status( 'on-hold' );
		$order->save();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( 1, $response->get_data()['completion']['n'] );
	}

	/** A zero-total open order completes on close with no payment, as WooCommerce completes a free checkout. */
	public function test_close_on_a_zero_total_open_order_completes_it_without_payment(): void {
		// Arrange: one line at 0.00, no rows.
		$order     = $this->zero_total_open_order();
		$register  = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
		$path      = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$announced = array();
		$listener  = static function ( $completed, $n ) use ( &$announced ): void {
			$announced[] = $n;
		};
		add_action( 'wcpos_sale_completed', $listener, 5, 2 );

		// Act.
		$response = $this->command( $path, $this->envelope( $order, array( 'provenance' => array( 'register_id' => $register ) ) ) );
		remove_action( 'wcpos_sale_completed', $listener, 5 );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$stored = wc_get_order( $order->get_id() );
		$this->assertContains( $stored->get_status(), wc_get_is_paid_statuses() );
		$this->assertNotNull( $stored->get_date_paid( 'edit' ) );
		$this->assertSame( '', $stored->get_payment_method() );
		$history = $this->stored_meta( $order->get_id(), Completion_Stamp::META_KEY );
		$this->assertCount( 1, $history );
		$this->assertSame( 1, $history[0]['n'] );
		$this->assertSame( array(), $history[0]['payment_ids'] );
		$this->assertSame( $register, $history[0]['register'] );
		$this->assertSame( array( 1 ), $announced );
		$this->assertNotNull( ( new Fiscal_Record_Store() )->find_sale( $order->get_id(), $history[0]['id'] ) );
		$this->assertSame( 1, $response->get_data()['completion']['n'] );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
		$this->assertSame( $this->current_revision( $order ), $response->get_data()['revision'] );

		// A second close replays the completion and writes nothing.
		$again = $this->command( $path, $this->envelope( $order ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $response->get_data()['completion'], $again->get_data()['completion'] );
		$this->assertCount( 1, $this->stored_meta( $order->get_id(), Completion_Stamp::META_KEY ) );
	}

	/** A reopened sale whose lines are all removed completes at 0.00, its retained money read as refund_due. */
	public function test_close_on_a_reopened_sale_reduced_to_zero_total_completes_with_refund_due(): void {
		// Arrange: 50.00 cash captured and completed; reopened and the line removed.
		list( $order ) = $this->paid_sale_of( array( '50.00' ), 1 );
		$date_paid     = $order->get_date_paid( 'edit' )->getTimestamp();
		$row_id        = Ledger::instance()->read( $order )[0]['id'];
		$order->set_status( 'pos-open' );
		$lines = array_keys( $order->get_items() );
		$order->remove_item( $lines[0] );
		$order->calculate_totals( false );
		$order->save();
		$this->assertSame( '0.00', wc_format_decimal( wc_get_order( $order->get_id() )->get_total(), 2 ) );

		// Act.
		$response = $this->close( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 2, $response->get_data()['completion']['n'] );
		$this->assertSame( '50.00', $response->get_data()['order']['refund_due'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( $date_paid, $stored->get_date_paid( 'edit' )->getTimestamp() );
		$history = $this->stored_meta( $order->get_id(), Completion_Stamp::META_KEY );
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertSame( array( $row_id ), $history[1]['payment_ids'] );
		$two = ( new Fiscal_Record_Store() )->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertSame( '-50.00', $two['payload']['fiscal']['adjustment'] );
	}

	/** A cancelled zero-total order is never revived by a close (WooCommerce's guard). */
	public function test_close_on_a_cancelled_zero_total_order_is_refused(): void {
		// Arrange.
		$order = $this->zero_total_open_order();
		$order->set_status( 'cancelled' );
		$order->save();

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertNull( $this->stored_meta( $order->get_id(), Completion_Stamp::META_KEY ) );
	}

	/** With prevent-overselling on, a zero-total close holds stock like record and intent (§4.8). */
	public function test_close_on_a_zero_total_order_is_refused_when_stock_is_held_elsewhere(): void {
		// Arrange: one unit of a free product, two open orders for it.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$settings                = get_option( 'woocommerce_pos_settings_checkout' );
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => true ) );
		$announced = 0;
		$listener  = static function () use ( &$announced ): void {
			++$announced;
		};
		try {
			$product = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
			$product->set_regular_price( '0.00' );
			$product->set_price( '0.00' );
			$product->set_tax_status( 'none' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 1 );
			$product->save();
			$first  = $this->zero_total_open_order( $product );
			$second = $this->zero_total_open_order( $product );
			add_action( 'wcpos_sale_completed', $listener );

			// Act.
			$sold    = $this->close( $first );
			$refused = $this->close( $second );
		} finally {
			remove_action( 'wcpos_sale_completed', $listener );
			unset( $_SERVER['HTTP_X_WCPOS'] );
			update_option( 'woocommerce_pos_settings_checkout', $settings );
		}

		// Assert.
		$this->assertSame( 200, $sold->get_status(), 'Response: ' . wp_json_encode( $sold->get_data() ) );
		$this->assertSame( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
		$this->assertSame( 400, $refused->get_status(), 'Response: ' . wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'wcpos_insufficient_stock', $refused->get_data()['code'] );
		$stored = wc_get_order( $second->get_id() );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertNull( $this->stored_meta( $second->get_id(), Completion_Stamp::META_KEY ) );
		$this->assertSame( 1, $announced );
	}

	/**
	 * An open POS order of one line at 0.00, no payment rows.
	 *
	 * @param \WC_Product|null $product The line's product; a new untaxed 0.00 product when null.
	 */
	private function zero_total_open_order( ?\WC_Product $product = null ): \WC_Order {
		if ( null === $product ) {
			$product = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
			$product->set_regular_price( '0.00' );
			$product->set_price( '0.00' );
			$product->set_tax_status( 'none' );
			$product->save();
		}
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();
		$this->assertSame( '0.00', wc_format_decimal( $order->get_total(), 2 ) );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Reopen an order in place, as the push lane's status change does.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $total Optional new total (a line edit's effect).
	 */
	private function reopen( \WC_Order $order, string $total = '' ): \WC_Order {
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'pos-open' );
		if ( '' !== $total ) {
			$order->set_total( $total );
		}
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/** Cash that counted in a completion leaves only through a refund once the sale is reopened. */
	public function test_void_of_a_cash_row_that_counted_in_a_completion_is_refused_after_reopen(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_data()['payment'];
		$this->reopen( $order );

		// Act.
		$response = $this->void( $order, $row['id'] );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
		$this->assertSame( 'captured', Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] )['status'] );
	}

	/** That counted cash row is refunded instead. */
	public function test_refund_of_that_row_is_allowed(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$row    = $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_data()['payment'];
		$order  = $this->reopen( $order );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => '10.00',
				'refund_payment' => false,
			)
		);

		// Act.
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $row['id'] ) . '/refund' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'refund_id' => $refund->get_id(),
					'amount' => '10.00',
				)
			)
		);
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'succeeded', $response->get_data()['payment']['refunds'][0]['status'] );
	}

	/** A split tender taken after the reopen can be voided until a completion counts it. */
	public function test_a_cash_row_recorded_after_reopen_is_voidable_until_the_next_completion(): void {
		// Arrange: completed, reopened with a higher total.
		$order = $this->create_pos_order();
		$this->record( $order, $this->payment( 'pos_cash', '92.95' ) );
		$order = $this->reopen( $order, '120.00' );

		// Act + Assert: a part tender is voidable.
		$part = $this->record( $order, $this->payment( 'pos_cash', '10.00' ) )->get_data()['payment'];
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 200, $this->void( $order, $part['id'] )->get_status() );

		// Once a completion counts the covering tender, a later reopen cannot void it.
		$cover = $this->record( $order, $this->payment( 'pos_cash', '27.05' ) )->get_data()['payment'];
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->reopen( $order );
		$this->assertSame( 409, $this->void( $order, $cover['id'] )->get_status() );
	}

	/** A cash sale completed before the history existed is as non-voidable after a reopen. */
	public function test_a_pre_deploy_cash_row_is_not_voidable_after_reopen(): void {
		// Arrange: paid with a captured cash row and no completion history.
		$order = $this->create_pos_order();
		$cash  = $this->captured_row( '92.95' );
		Ledger::instance()->save( $order, array( $cash ), false );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5 );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_completed' ), 15 );
		try {
			$paid = wc_get_order( $order->get_id() );
			$paid->set_date_paid( time() );
			$paid->set_status( 'completed' );
			$paid->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5, 4 );
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_completed' ), 15, 4 );
		}
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->reopen( $order );

		// Act.
		$response = $this->void( $order, $cash['id'] );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
	}

	/**
	 * Every payment mutation respects the order lock.
	 *
	 * @param string $action Payment action.
	 * @dataProvider order_actions
	 */
	public function test_every_order_route_obeys_lock( string $action ): void {
		$order = $this->create_pos_order();
		$other = new \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock();
		$this->assertTrue( $other->acquire( $order->get_id() ) );
		try {
			$path = '/wcpos/v2/orders/' . $order->get_id() . ( 'close' === $action ? '/close' : '/payments' );
			if ( ! in_array( $action, array( 'record', 'close' ), true ) ) {
				$path .= '/' . wp_generate_uuid4() . '/' . $action;
			}
			$request = 'status' === $action ? $this->wp_rest_get_request( $path ) : $this->wp_rest_post_request( $path );
			if ( 'status' !== $action ) {
				// The envelope is present: the held lock answers before any command is looked up.
				$request->set_body_params( $this->envelope( $order ) );
			}
			$response = $this->server->dispatch( $request );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'wcpos_payment_locked', $response->get_data()['code'] );
		} finally {
			$other->release( $order->get_id() );
		}
	}

	/** Provide the locked payment actions. */
	public function order_actions(): array {
		return array_map(
			static function ( $action ) {
				return array( $action );
			},
			array( 'record', 'intent', 'capture', 'status', 'void', 'refund', 'close' )
		);
	}

	public function test_status_prompt_is_response_only(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		$prompt = array( 'id' => 'p1', 'lines' => array( 'Sign?' ), 'buttons' => array( array( 'id' => 'B1', 'label' => 'YES' ) ) );
		Route_Handler::$prompt = $prompt;
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00', array( 'capture_mode' => 'route_test', 'status' => 'pending' ) );
		Ledger::instance()->save( $order, array( $payment ) );
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'prompt', $data );
		$this->assertSame( $prompt, $data['prompt'] );
		$this->assertArrayNotHasKey( 'prompt', $data['payment'] );
		$this->assertArrayNotHasKey( 'prompt', Ledger::instance()->find( wc_get_order( $order->get_id() ), $payment['id'] ) );
	}

	public function test_status_without_prompt_returns_null(): void {
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'prompt', $data );
		$this->assertNull( $data['prompt'] );
	}

	/** Intent and capture return the current payment summary. */
	public function test_intent_and_capture_return_handoff_and_locked_fresh_order_summary(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		add_filter(
			'wcpos_payment_method_capture_mode',
			static function () {
				return 'route_test';
			}
		);
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/intent' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'payment' => $payment,
					'context' => array(
						'reader' => 'reader-1',
						'cashier_id' => 0,
					),
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['payment']['status'] );
		$this->assertSame( get_current_user_id(), $response->get_data()['payment']['cashier_id'] );
		$this->assertSame( array( 'reader' => 'reader-1' ), $response->get_data()['handoff'] );
		$this->assertSame( 'pending', $response->get_data()['order']['status'] );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/capture' );
		$request->set_body_params( $this->envelope( $order, array( 'context' => array( 'amount' => '92.95' ) ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'captured', $response->get_data()['payment']['status'] );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
		$this->assertArrayNotHasKey( 'handoff', $response->get_data() );
	}

	/** A second full-balance intent while a leg is live is refused with the live leg's id. */
	public function test_intent_in_flight_returns_409_with_the_live_payment_id(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		add_filter(
			'wcpos_payment_method_capture_mode',
			static function () {
				return 'route_test';
			}
		);
		$order = $this->create_pos_order();
		$first = $this->payment( 'pos_cash', '92.95' );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $first['id'] ) . '/intent' );
		$request->set_body_params( $this->envelope( $order, array( 'payment' => $first, 'context' => array() ) ) );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$second = $this->payment( 'pos_cash', '92.95' );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $second['id'] ) . '/intent' );
		$request->set_body_params( $this->envelope( $order, array( 'payment' => $second, 'context' => array() ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wcpos_payment_in_flight', $data['code'] );
		$this->assertSame( $first['id'], $data['data']['payment_id'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** Refund responses contain only the payment row. */
	public function test_refund_response_contains_only_payment(): void {
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '20.00' );
		$refund->save();
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/refund' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'refund_id' => $refund->get_id(),
					'amount' => '5.00',
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'payment', 'revision' ), array_keys( $response->get_data() ) );
		$this->assertSame( '5.00', $response->get_data()['payment']['refunded_amount'] );
	}

	/** Provider errors retain their redacted events without advancing the payment. */
	public function test_capture_provider_error_persists_failure_events(): void {
		$registry = \WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance();
		$registry->register( 'route_test', Failure_Route_Handler::class );
		$mode = static fn() => 'route_test';
		add_filter( 'wcpos_payment_method_capture_mode', $mode );
		try {
			$order = $this->create_pos_order();
			$payment = $this->payment( 'pos_cash', '92.95' );
			$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/intent' );
			$request->set_body_params(
				$this->envelope(
					$order,
					array(
						'payment' => $payment,
						'context' => array( 'reader' => 'reader-1' ),
					)
				)
			);
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
			$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/capture' );
			$body    = $this->envelope( $order );
			$request->set_body_params( $body );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 502, $response->get_status() );
			// The failure stored no outcome but its events moved the revision: the retry re-reads it.
			$request->set_body_params( array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );
			$this->assertSame( 502, $this->server->dispatch( $request )->get_status() );
			$order = wc_get_order( $order->get_id() );
			$row = Ledger::instance()->find( $order, $payment['id'] );
			$this->assertCount( 2, $row['events'] );
			$this->assertSame( 'Capture failed: card_declined', $row['events'][0]['message'] );
			$this->assertSame( 'Capture failed: card_declined', end( $row['events'] )['message'] );
			$this->assertSame( 'pending', $row['status'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $mode );
			$registry->register( 'route_test', Route_Handler::class );
		}
	}

	/** Route the till's card-like legs through the counting test handler. */
	private function use_route_handler(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static fn() => 'route_test' );
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
	 * An order meta value as storage holds it, JSON decoded; null when absent.
	 *
	 * @param int    $order_id Order id.
	 * @param string $key      Meta key.
	 * @return mixed
	 */
	private function stored_meta( int $order_id, string $key ) {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( $key === $meta->meta_key ) {
				$value = maybe_unserialize( $meta->meta_value );
				return is_string( $value ) ? json_decode( $value, true ) : $value;
			}
		}
		return null;
	}

	/**
	 * A stored ledger row, read from storage.
	 *
	 * @param int    $order_id Order id.
	 * @param string $id       Payment id.
	 */
	private function stored_row( int $order_id, string $id ): ?array {
		foreach ( (array) ( $this->stored_meta( $order_id, Ledger::META_KEY )['payments'] ?? array() ) as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * A pending leg handled by the test handler, intended through the route.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $amount  Amount.
	 */
	private function intended( \WC_Order $order, string $amount ): array {
		$payment = $this->payment( 'pos_cash', $amount );
		$this->assertSame( 200, $this->command( $this->payment_path( $order, $payment['id'] ) . '/intent', $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) ) )->get_status() );
		return $payment;
	}

	/** Pin 1: a capture retried with its command and body replays, even on the revision from before it. */
	public function test_capture_replay_with_the_same_command_and_body_answers_the_captured_row_with_a_stale_revision(): void {
		// Arrange.
		$this->use_route_handler();
		Route_Handler::$captures = 0;
		$order   = $this->create_pos_order();
		$payment = $this->intended( $order, '92.95' );
		$body    = $this->envelope( $order, array( 'context' => array( 'amount' => '92.95' ) ) );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/capture';

		// Act.
		$first = $this->command( $path, $body );
		$again = $this->command( $path, $body );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( 'captured', $again->get_data()['payment']['status'] );
		$this->assertSame( $first->get_data()['payment']['id'], $again->get_data()['payment']['id'] );
		$this->assertSame( $first->get_data()['revision'], $again->get_data()['revision'] );
		$this->assertSame( 1, Route_Handler::$captures );
		$commands = $this->stored_row( $order->get_id(), $payment['id'] )['commands'];
		$this->assertSame( 'capture', $commands[ $body['command_id'] ]['kind'] );
		$this->assertCount( 1, array_filter( $commands, static fn( $entry ) => 'capture' === $entry['kind'] ) );
	}

	/** Pin 2: the same command id with another body is a 422 and changes nothing. */
	public function test_capture_replay_with_the_same_command_and_a_different_body_is_422(): void {
		// Arrange.
		$this->use_route_handler();
		Route_Handler::$captures = 0;
		$order   = $this->create_pos_order();
		$payment = $this->intended( $order, '92.95' );
		$body    = $this->envelope( $order, array( 'context' => array( 'amount' => '92.95' ) ) );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/capture';
		$this->assertSame( 200, $this->command( $path, $body )->get_status() );
		$before = $this->stored_row( $order->get_id(), $payment['id'] );

		// Act.
		$response = $this->command( $path, array_merge( $body, array( 'context' => array( 'amount' => '92.95', 'reader' => 'other' ) ) ) );

		// Assert.
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( $body['command_id'], $response->get_data()['data']['command_id'] );
		$this->assertSame( 'capture', $response->get_data()['data']['kind'] );
		$this->assertSame( $before, $this->stored_row( $order->get_id(), $payment['id'] ) );
		$this->assertSame( 1, Route_Handler::$captures );
	}

	/** Pin 3: a fresh command built on a stale revision is refused with the current one. */
	public function test_a_fresh_command_with_a_stale_revision_is_409_with_the_current_revision_and_document(): void {
		// Arrange: a pending leg, then cash recorded against r0 (which moves the revision).
		$order   = $this->create_pos_order();
		$pending = $this->payment( 'pos_cash', '40.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $pending ), false );
		$r0 = $this->current_revision( $order );
		$this->assertSame( 200, $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', array( 'payment' => $this->payment( 'pos_cash', '20.00' ), 'revision' => $r0 ) )->get_status() );
		$path = $this->payment_path( $order, $pending['id'] ) . '/void';

		// Act.
		$stale = $this->command( $path, array( 'command_id' => wp_generate_uuid4(), 'revision' => $r0 ) );

		// Assert.
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'wcpos_revision_stale', $stale->get_data()['code'] );
		$current = \WCPOS\WooCommercePOS\Sync\Order_Serializer::current_state( $order->get_id() );
		$this->assertSame( $current['revision'], $stale->get_data()['data']['currentRevision'] );
		$this->assertSame( $order->get_id(), $stale->get_data()['data']['current']['id'] );
		$this->assertSame( 'pending', $this->stored_row( $order->get_id(), $pending['id'] )['status'] );
		$fresh = $this->command( $path, array( 'command_id' => wp_generate_uuid4(), 'revision' => $stale->get_data()['data']['currentRevision'] ) );
		$this->assertSame( 200, $fresh->get_status() );
		$this->assertSame( 'voided', $fresh->get_data()['payment']['status'] );
	}

	/**
	 * Pin 4: every money command needs a command id (but record) and a revision; nothing is written without.
	 *
	 * @param string $kind Command kind.
	 * @dataProvider money_commands
	 */
	public function test_money_commands_require_a_command_id_and_a_revision( string $kind ): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$pending = $this->payment( 'pos_cash', '10.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $pending ), false );
		$base  = '/wcpos/v2/orders/' . $order->get_id();
		$paths = array(
			'record'  => $base . '/payments',
			'intent'  => $this->payment_path( $order, wp_generate_uuid4() ) . '/intent',
			'capture' => $this->payment_path( $order, $pending['id'] ) . '/capture',
			'void'    => $this->payment_path( $order, $pending['id'] ) . '/void',
			'refund'  => $this->payment_path( $order, $pending['id'] ) . '/refund',
			'submit'  => $base . '/payment-methods/pos_cash/submit',
			'cancel'  => $base . '/payment-methods/pos_cash/cancel',
			'close'   => $base . '/close',
		);
		$ledger   = $this->stored_meta( $order->get_id(), Ledger::META_KEY );
		$revision = $this->current_revision( $order );
		$answers  = array();

		// Act.
		if ( 'record' !== $kind ) {
			$answers['missing id'] = $this->command( $paths[ $kind ], array( 'revision' => $revision ) );
			$answers['not a uuid'] = $this->command( $paths[ $kind ], array( 'command_id' => 'abc', 'revision' => $revision ) );
		}
		$no_revision = $this->command( $paths[ $kind ], array( 'command_id' => wp_generate_uuid4(), 'payment' => $this->payment( 'pos_cash', '5.00' ) ) );

		// Assert.
		foreach ( $answers as $case => $answer ) {
			$this->assertSame( 400, $answer->get_status(), $case );
			$this->assertSame( 'rest_invalid_param', $answer->get_data()['code'], $case );
		}
		$this->assertSame( 428, $no_revision->get_status() );
		$this->assertSame( 'wcpos_revision_required', $no_revision->get_data()['code'] );
		$this->assertSame( $ledger, $this->stored_meta( $order->get_id(), Ledger::META_KEY ) );
		$this->assertNull( $this->stored_meta( $order->get_id(), '_wcpos_commands' ) );
	}

	/** The money commands. */
	public function money_commands(): array {
		return array_map( static fn( $kind ) => array( $kind ), array_combine( array( 'record', 'intent', 'capture', 'void', 'refund', 'submit', 'cancel', 'close' ), array( 'record', 'intent', 'capture', 'void', 'refund', 'submit', 'cancel', 'close' ) ) );
	}

	/** Pin 6: a void of a pending row moves the revision, and its answer carries the new one. */
	public function test_a_void_of_a_pending_row_moves_the_revision(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$pending = $this->payment( 'pos_cash', '10.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $pending ), false );
		$before = $this->current_revision( $order );

		// Act.
		$response = $this->void( $order, $pending['id'] );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );
		$after = $this->current_revision( $order );
		$this->assertNotSame( $before, $after );
		$this->assertSame( $after, $response->get_data()['revision'] );
	}

	/** Pin 7: a save that only moves date_modified leaves the revision alone. */
	public function test_the_revision_excludes_date_modified(): void {
		// Arrange.
		$order    = $this->create_pos_order();
		$revision = $this->current_revision( $order );

		// Act.
		$touched = wc_get_order( $order->get_id() );
		$touched->set_date_modified( time() + 60 );
		$touched->save();

		// Assert.
		$this->assertSame( $revision, $this->current_revision( $order ) );
	}

	/** Pin 12: a close retried with its command replays the completion it made, even on a stale revision. */
	public function test_close_replays_its_completion_with_a_stale_revision(): void {
		// Arrange: covered without deriving.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$body      = $this->envelope( $order );
		$announced = 0;
		$listener  = static function () use ( &$announced ): void {
			++$announced;
		};
		add_action( 'wcpos_sale_completed', $listener );

		// Act.
		$first = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', $body );
		$again = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', $body );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $again->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( array( 'n' => 1, 'id' => $history[0]['id'] ), $first->get_data()['completion'] );
		$this->assertSame( $first->get_data()['completion'], $again->get_data()['completion'] );
		$commands = $this->stored_meta( $order->get_id(), '_wcpos_commands' );
		$this->assertSame( array( $body['command_id'] ), array_keys( $commands ) );
		$this->assertSame( $history[0]['id'], $commands[ $body['command_id'] ]['ref'] );
		$this->assertSame( 1, $announced );
	}

	/** Pin 13: a fresh close on a stale revision is refused; on the current one it replays the standing completion. */
	public function test_close_with_a_fresh_command_and_a_stale_revision_is_409(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$r1   = $this->current_revision( $order );
		$path = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$this->assertSame( 200, $this->command( $path, array( 'command_id' => wp_generate_uuid4(), 'revision' => $r1 ) )->get_status() );

		// Act.
		$stale = $this->command( $path, array( 'command_id' => wp_generate_uuid4(), 'revision' => $r1 ) );
		$fresh = $this->command( $path, $this->envelope( $order ) );

		// Assert.
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'wcpos_revision_stale', $stale->get_data()['code'] );
		$this->assertSame( 200, $fresh->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( array( 'n' => 1, 'id' => $history[0]['id'] ), $fresh->get_data()['completion'] );
	}

	/**
	 * A captured row the test handler refunds, and a refund object of 20.00 on the order.
	 *
	 * @param string $amount Row amount.
	 * @return array{0: \WC_Order, 1: array, 2: \WC_Order_Refund}
	 */
	private function refundable( string $amount = '92.95' ): array {
		$this->use_route_handler();
		Route_Handler::$refunds = 0;
		$order = $this->create_pos_order();
		$row   = array_merge( $this->captured_row( $amount ), array( 'capture_mode' => 'route_test' ) );
		Ledger::instance()->save( $order, array( $row ), false );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => '20.00',
				'refund_payment' => false,
			)
		);
		return array( wc_get_order( $order->get_id() ), $row, $refund );
	}

	/** Pin 14: a refund retried with its command allocates once; another amount under it is a 422. */
	public function test_refund_replay_allocates_once_and_a_different_amount_is_422(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->refundable();
		$path = $this->payment_path( $order, $row['id'] ) . '/refund';
		$body = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount' => '10.00',
			)
		);

		// Act.
		$first     = $this->command( $path, $body );
		$again     = $this->command( $path, $body );
		$other     = $this->command( $path, array_merge( $body, array( 'amount' => '20.00' ) ) );
		$new_id    = $this->command( $path, $this->envelope( $order, array( 'refund_id' => $refund->get_id(), 'amount' => '10.00' ) ) );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $first->get_data()['payment']['refunds'], $again->get_data()['payment']['refunds'] );
		$this->assertSame( 422, $other->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $other->get_data()['code'] );
		$this->assertSame( 200, $new_id->get_status() );
		$this->assertCount( 1, $this->stored_row( $order->get_id(), $row['id'] )['refunds'] );
		$this->assertSame( 1, Route_Handler::$refunds );
	}

	/**
	 * Rewrite the refund's allocation in every staged ledger save of the order while $active() holds:
	 * null strips it, an array stands in its place (the forced storage miss of spec §6.5).
	 *
	 * @param int        $order_id   Order id.
	 * @param int        $refund_id  Refund id.
	 * @param array|null $allocation The allocation storage is forced to hold.
	 * @param callable   $active     Whether this save is rewritten.
	 */
	private function force_allocation( int $order_id, int $refund_id, ?array $allocation, callable $active ): \Closure {
		$hook = static function ( $saving ) use ( $order_id, $refund_id, $allocation, $active ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || ! $active() ) {
				return;
			}
			$value = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			foreach ( $value['payments'] as &$row ) {
				$row['refunds'] = array_values( array_filter( $row['refunds'] ?? array(), static fn( $existing ) => (int) $existing['id'] !== $refund_id ) );
				if ( null !== $allocation ) {
					$row['refunds'][] = $allocation;
				}
			}
			unset( $row );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );
		return $hook;
	}

	/**
	 * A refund's allocations on a stored row.
	 *
	 * @param array|null $row       Stored row.
	 * @param int        $refund_id Refund id.
	 */
	private function allocations_of( ?array $row, int $refund_id ): array {
		return array_values( array_filter( $row['refunds'] ?? array(), static fn( $allocation ) => (int) $allocation['id'] === $refund_id ) );
	}

	/**
	 * A refund stranded through the route: its settle is forced back to the reservation.
	 *
	 * @return array{0: \WC_Order, 1: array, 2: \WC_Order_Refund, 3: string, 4: array, 5: \WP_REST_Response}
	 */
	private function stranded_refund(): array {
		list( $order, $row, $refund ) = $this->refundable();
		$reservation = array(
			'id'           => $refund->get_id(),
			'amount'       => '10.00',
			'status'       => 'pending',
			'provider_ref' => null,
		);
		$force = $this->force_allocation( $order->get_id(), $refund->get_id(), $reservation, static fn() => Route_Handler::$refunds > 0 );
		$path  = $this->payment_path( $order, $row['id'] ) . '/refund';
		$body  = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount'    => '10.00',
			)
		);
		$first = $this->command( $path, $body );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );
		return array( $order, $row, $refund, $path, $body, $first );
	}

	/** #2362 review: a settle that lands without its command entry is a miss, so the command still owns its id. */
	public function test_a_settle_that_loses_the_command_entry_is_not_acknowledged(): void {
		// Arrange: the settle's two saves land with every row's command map emptied; later saves land as written.
		list( $order, $row, $refund ) = $this->refundable();
		$order_id = $order->get_id();
		$stripped = 0;
		$strip    = static function ( $saving ) use ( $order_id, &$stripped ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || 0 === Route_Handler::$refunds || $stripped >= 2 ) {
				return;
			}
			++$stripped;
			$value = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			foreach ( $value['payments'] as &$payment ) {
				$payment['commands'] = array();
			}
			unset( $payment );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$path = $this->payment_path( $order, $row['id'] ) . '/refund';
		$body = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount'    => '10.00',
			)
		);

		// Act.
		$first = $this->command( $path, $body );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$stored = $this->stored_row( $order_id, $row['id'] );
		$other  = $this->command( $path, array_merge( $body, array( 'amount' => '20.00' ) ) );

		// Assert.
		$this->assertSame( 2, $stripped );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'pending', $this->allocations_of( $first->get_data()['payment'], $refund->get_id() )[0]['status'] );
		$this->assertSame( 'pending', $this->allocations_of( $stored, $refund->get_id() )[0]['status'] );
		$this->assertArrayHasKey( $body['command_id'], $stored['commands'] );
		$this->assertSame( 422, $other->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $other->get_data()['code'] );
		$this->assertSame( 1, Route_Handler::$refunds );
	}

	/** §6.6 pin 4: a stranded refund retried with its command answers the pending allocation; the provider is not asked again. */
	public function test_a_stranded_refund_retried_with_the_same_command_does_not_call_the_provider(): void {
		// Arrange.
		list( $order, $row, $refund, $path, $body, $first ) = $this->stranded_refund();

		// Act: the same command id and body, on the revision from before the refund.
		$again = $this->command( $path, $body );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'pending', $this->allocations_of( $first->get_data()['payment'], $refund->get_id() )[0]['status'] );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( array( 'payment', 'revision' ), array_keys( $again->get_data() ) );
		$this->assertSame( 'pending', $this->allocations_of( $again->get_data()['payment'], $refund->get_id() )[0]['status'] );
		$this->assertCount( 1, $this->allocations_of( $this->stored_row( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertSame( 1, Route_Handler::$refunds );
	}

	/** §6.6 pin 5: a new command for a stranded refund answers it too, and keeps its entry. */
	public function test_a_stranded_refund_under_a_new_command_does_not_call_the_provider(): void {
		// Arrange.
		list( $order, $row, $refund, $path, $body ) = $this->stranded_refund();

		// Act: a new command id, the same refund and amount, on the current revision.
		$fresh = $this->command(
			$path,
			$this->envelope(
				$order,
				array(
					'refund_id' => $refund->get_id(),
					'amount'    => '10.00',
				)
			)
		);

		// Assert.
		$this->assertSame( 200, $fresh->get_status() );
		$stored = $this->stored_row( $order->get_id(), $row['id'] );
		$this->assertSame( 'pending', $this->allocations_of( $stored, $refund->get_id() )[0]['status'] );
		$this->assertCount( 1, $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertCount( 2, $stored['commands'] );
		$this->assertArrayHasKey( $body['command_id'], $stored['commands'] );
		$this->assertSame( 1, Route_Handler::$refunds );
	}

	/** §6.6 pin 2, route twin: a reservation that does not land is the retryable 409, and nothing is kept. */
	public function test_a_refund_whose_reservation_does_not_land_is_the_retryable_409(): void {
		// Arrange: every save strips the allocation and keeps the entry.
		list( $order, $row, $refund ) = $this->refundable();
		$strip = $this->force_allocation( $order->get_id(), $refund->get_id(), null, static fn() => true );
		$path  = $this->payment_path( $order, $row['id'] ) . '/refund';
		$body  = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount'    => '10.00',
			)
		);

		// Act.
		$refused = $this->command( $path, $body );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$stored = $this->stored_row( $order->get_id(), $row['id'] );
		$retry  = $this->command( $path, array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $refused->get_data()['code'] );
		$this->assertSame( array(), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayNotHasKey( $body['command_id'], $stored['commands'] );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertSame( 'succeeded', $this->allocations_of( $retry->get_data()['payment'], $refund->get_id() )[0]['status'] );
		$this->assertSame( 1, Route_Handler::$refunds );
	}

	/** §6.6 pin 6, route twin: a provider refusal is answered and stores nothing; the same command then runs. */
	public function test_a_refund_the_provider_refuses_stores_nothing_and_the_same_command_runs_again(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->refundable();
		Route_Handler::$refund_error  = new \WP_Error( 'wcpos_provider_error', 'declined', array( 'status' => 502 ) );
		$path                         = $this->payment_path( $order, $row['id'] ) . '/refund';
		$body                         = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount'    => '10.00',
			)
		);

		// Act.
		$refused = $this->command( $path, $body );
		$stored  = $this->stored_row( $order->get_id(), $row['id'] );
		Route_Handler::$refund_error = null;
		$retry = $this->command( $path, array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 502, $refused->get_status() );
		$this->assertSame( 'wcpos_provider_error', $refused->get_data()['code'] );
		$this->assertSame( array(), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayNotHasKey( $body['command_id'], $stored['commands'] );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertSame( 'succeeded', $this->allocations_of( $retry->get_data()['payment'], $refund->get_id() )[0]['status'] );
		$this->assertSame( 2, Route_Handler::$refunds );
	}

	/** Pin 15: an intent retried with its command resumes the leg; another body under it is a 422. */
	public function test_intent_replay_with_the_same_command_resumes_the_leg_and_a_different_body_is_422(): void {
		// Arrange.
		$this->use_route_handler();
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/intent';
		$body    = $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) );

		// Act.
		$first = $this->command( $path, $body );
		$again = $this->command( $path, $body );
		$other = $this->command( $path, array_merge( $body, array( 'payment' => array_merge( $payment, array( 'amount' => '50.00' ) ) ) ) );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( 'pending', $again->get_data()['payment']['status'] );
		$this->assertSame( array( 'reader' => 'reader-1' ), $again->get_data()['handoff'] );
		$this->assertSame( 422, $other->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $other->get_data()['code'] );
		$this->assertCount( 1, $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'] );
	}

	/** Pin 16: offline cash replayed after the revision moved is the same row, never a second. */
	public function test_an_offline_cash_record_replayed_after_the_revision_moved_creates_one_row(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$cash    = $this->payment( 'pos_cash', '20.00', array( 'recorded_offline' => true ) );
		$r0      = $this->current_revision( $order );
		$path    = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$this->assertSame( 200, $this->command( $path, array( 'payment' => $cash, 'revision' => $r0 ) )->get_status() );
		$edited = wc_get_order( $order->get_id() );
		$edited->set_customer_note( 'late' );
		$edited->save();

		// Act.
		$replayed = $this->command( $path, array( 'payment' => $cash, 'revision' => $r0 ) );

		// Assert.
		$this->assertSame( 200, $replayed->get_status() );
		$this->assertSame( $cash['id'], $replayed->get_data()['payment']['id'] );
		$this->assertCount( 1, $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'] );
	}

	/** Pin 17: a new offline row on a stale revision is refused; re-sent with the current one it lands once. */
	public function test_a_fresh_offline_cash_record_with_a_stale_revision_is_refused_so_the_till_re_reads(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$r0     = $this->current_revision( $order );
		$edited = wc_get_order( $order->get_id() );
		$edited->set_customer_note( 'late' );
		$edited->save();
		$cash = $this->payment( 'pos_cash', '20.00', array( 'recorded_offline' => true ) );
		$path = '/wcpos/v2/orders/' . $order->get_id() . '/payments';

		// Act.
		$stale = $this->command( $path, array( 'payment' => $cash, 'revision' => $r0 ) );
		$fresh = $this->command( $path, array( 'payment' => $cash, 'revision' => $stale->get_data()['data']['currentRevision'] ) );

		// Assert.
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'wcpos_revision_stale', $stale->get_data()['code'] );
		$this->assertSame( 200, $fresh->get_status() );
		$rows = $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'];
		$this->assertSame( array( $cash['id'] ), array_column( $rows, 'id' ) );
	}

	/** Pin 19: the status poll is not a command: no envelope asked, but its answer carries the revision. */
	public function test_the_status_poll_takes_no_envelope_and_answers_the_revision(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->current_revision( $order ), $response->get_data()['revision'] );
	}

	/** A poll that settles the row answers the revision after it, so the next void or capture is not stale. */
	public function test_a_status_poll_that_completes_the_order_answers_the_revision_after_it(): void {
		// Arrange: a pending card leg the provider has captured.
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static fn() => 'route_test' );
		$order = $this->create_pos_order();
		$leg   = array(
			'id'           => wp_generate_uuid4(),
			'method_id'    => 'pos_cash',
			'capture_mode' => 'route_test',
			'amount'       => '92.95',
			'status'       => 'pending',
		);
		Ledger::instance()->save( $order, array( $leg ), false );
		Route_Handler::$answer = array( 'status' => 'captured' );
		$before                = $this->current_revision( $order );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $leg['id'] ) . '/status' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->current_revision( $order ), $response->get_data()['revision'] );
		$this->assertNotSame( $before, $response->get_data()['revision'] );
	}

	/** Pin 20: the command maps are kept in storage and never answered on the wire. */
	public function test_command_entries_stay_out_of_the_wire_and_in_storage(): void {
		// Arrange.
		$this->use_route_handler();
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$intent  = $this->command( $this->payment_path( $order, $payment['id'] ) . '/intent', $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) ) );
		$capture = $this->envelope( $order, array( 'context' => array( 'amount' => '92.95' ) ) );
		$close   = $this->envelope( $order );

		// Act.
		$captured = $this->command( $this->payment_path( $order, $payment['id'] ) . '/capture', $capture );
		$replayed = $this->command( $this->payment_path( $order, $payment['id'] ) . '/capture', $capture );
		$closed   = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', array_merge( $close, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		foreach ( array( $intent, $captured, $replayed ) as $answer ) {
			$this->assertSame( 200, $answer->get_status() );
			$this->assertArrayNotHasKey( 'commands', $answer->get_data()['payment'] );
		}
		$this->assertSame( 200, $closed->get_status() );
		$this->assertArrayHasKey( $capture['command_id'], $this->stored_row( $order->get_id(), $payment['id'] )['commands'] );
		$this->assertArrayHasKey( $close['command_id'], $this->stored_meta( $order->get_id(), '_wcpos_commands' ) );
	}

	/** Pin 23: a command id is the order's: reused on another row or route it is another body. */
	public function test_a_command_id_reused_on_another_row_or_route_is_422(): void {
		// Arrange: rows A and B, refund c6 allocated on A.
		list( $order, $a, $refund ) = $this->refundable( '50.00' );
		$b = array_merge( $this->captured_row( '42.95' ), array( 'capture_mode' => 'route_test' ) );
		Ledger::instance()->save( $order, array( $a, $b ), false );
		$body = $this->envelope(
			$order,
			array(
				'refund_id' => $refund->get_id(),
				'amount' => '10.00',
			)
		);
		$this->assertSame( 200, $this->command( $this->payment_path( $order, $a['id'] ) . '/refund', $body )->get_status() );

		// Act.
		$other_row   = $this->command( $this->payment_path( $order, $b['id'] ) . '/refund', $body );
		$other_route = $this->command( $this->payment_path( $order, $a['id'] ) . '/void', array( 'command_id' => $body['command_id'], 'revision' => $this->current_revision( $order ) ) );

		// Assert.
		$this->assertSame( 422, $other_row->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $other_row->get_data()['code'] );
		$this->assertSame( 422, $other_route->get_status() );
		$this->assertSame( 1, Route_Handler::$refunds );
		$this->assertSame( array(), $this->stored_row( $order->get_id(), $b['id'] )['refunds'] );
	}

	/** Pin 24: a 2xx command that changed nothing else still keeps its entry. */
	public function test_a_command_that_changed_nothing_still_stores_its_entry(): void {
		// Arrange: a refund allocated.
		list( $order, $row, $refund ) = $this->refundable();
		$path = $this->payment_path( $order, $row['id'] ) . '/refund';
		$this->assertSame( 200, $this->command( $path, $this->envelope( $order, array( 'refund_id' => $refund->get_id(), 'amount' => '10.00' ) ) )->get_status() );
		$c8 = $this->envelope( $order, array( 'refund_id' => $refund->get_id(), 'amount' => '10.00' ) );

		// Act + Assert: the refund under a new id.
		$this->assertSame( 200, $this->command( $path, $c8 )->get_status() );
		$this->assertArrayHasKey( $c8['command_id'], $this->stored_row( $order->get_id(), $row['id'] )['commands'] );
		$this->assertSame( 422, $this->command( $path, array_merge( $c8, array( 'amount' => '5.00' ) ) )->get_status() );

		// Act + Assert: a close replaying the standing completion of a covered sale.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$close = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$this->assertSame( 200, $this->command( $close, $this->envelope( $order ) )->get_status() );
		$c9       = $this->envelope( $order );
		$replayed = $this->command( $close, $c9 );
		$this->assertSame( 200, $replayed->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( $history[0]['id'], $this->stored_meta( $order->get_id(), '_wcpos_commands' )[ $c9['command_id'] ]['ref'] );
		$this->assertSame( 422, $this->command( $close, array_merge( $c9, array( 'provenance' => array( 'register_id' => wp_generate_uuid4() ) ) ) )->get_status() );
	}

	/** Pin 24 (legacy): a close on a sale paid before the history existed names no completion. */
	public function test_a_close_on_a_legacy_paid_sale_stores_its_entry_with_no_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$paid  = wc_get_order( $order->get_id() );
		$paid->set_date_paid( time() );
		$paid->set_status( 'processing' );
		$paid->save();
		$body = $this->envelope( $order );

		// Act.
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', $body );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( $body['command_id'], $this->stored_meta( $order->get_id(), '_wcpos_commands' ) );
		$this->assertNull( $this->stored_meta( $order->get_id(), '_wcpos_commands' )[ $body['command_id'] ]['ref'] );
	}

	/** Pin 25: a failed inner read refuses the command: nothing is hashed, run or stored. */
	public function test_a_failed_inner_read_refuses_the_command_and_hashes_nothing(): void {
		// Arrange.
		$this->use_route_handler();
		Route_Handler::$captures = 0;
		$order   = $this->create_pos_order();
		$payment = $this->intended( $order, '92.95' );
		$body    = $this->envelope( $order, array( 'context' => array( 'amount' => '92.95' ) ) );
		$route   = '/wc/v3/orders/' . $order->get_id();
		$deny    = static function ( $result, $server, $request ) use ( $route ) {
			return $route === $request->get_route() ? new \WP_Error( 'woocommerce_rest_cannot_view', 'Sorry, you cannot view this resource.', array( 'status' => 403 ) ) : $result;
		};
		add_filter( 'rest_pre_dispatch', $deny, 10, 3 );

		// Act.
		try {
			$response = $this->command( $this->payment_path( $order, $payment['id'] ) . '/capture', $body );
		} finally {
			remove_filter( 'rest_pre_dispatch', $deny, 10 );
		}

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cannot_view', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'revision', $response->get_data() );
		$stored = $this->stored_row( $order->get_id(), $payment['id'] );
		$this->assertSame( 'pending', $stored['status'] );
		$this->assertArrayNotHasKey( $body['command_id'], $stored['commands'] );
		$this->assertSame( 0, Route_Handler::$captures );
	}

	/** Pin 26: the answer's revision is read after the parked sale record was recovered in the same request. */
	public function test_the_answers_revision_is_read_after_fiscal_recovery(): void {
		// Arrange: completed while the sale record could not be inserted, so its document is parked.
		$order = $this->create_pos_order();
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
		$this->assertNotEmpty( $this->stored_meta( $order->get_id(), '_wcpos_fiscal_pending' ) );

		// Act: any command; close replays the standing completion.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $this->stored_meta( $order->get_id(), '_wcpos_fiscal_pending' ) );
		$this->assertSame( $this->current_revision( $order ), $response->get_data()['revision'] );
	}

	/** Pin 29: a new command id on an existing row is a fresh command: a stale revision refuses it before the provider. */
	public function test_a_fresh_intent_on_an_existing_row_with_a_stale_revision_is_409(): void {
		// Arrange: intent c1 creates pending row P; the order then changes.
		$this->use_route_handler();
		Route_Handler::$intents = 0;
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/intent';
		$body    = $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) );
		$this->assertSame( 200, $this->command( $path, $body )->get_status() );
		$old    = $this->current_revision( $order );
		$edited = wc_get_order( $order->get_id() );
		$edited->set_customer_note( 'changed' );
		$edited->save();
		$c2 = array_merge( $body, array( 'command_id' => wp_generate_uuid4(), 'revision' => $old ) );

		// Act.
		$stale = $this->command( $path, $c2 );
		$fresh = $this->command( $path, array_merge( $c2, array( 'revision' => $stale->get_data()['data']['currentRevision'] ?? '' ) ) );

		// Assert.
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'wcpos_revision_stale', $stale->get_data()['code'] );
		$this->assertSame( 200, $fresh->get_status(), wp_json_encode( $fresh->get_data() ) );
		$this->assertSame( array( 'reader' => 'reader-1' ), $fresh->get_data()['handoff'] );
		$this->assertSame( 2, Route_Handler::$intents );
		$this->assertSame( 'pending', $this->stored_row( $order->get_id(), $payment['id'] )['status'] );
	}

	/** Pin 30: a close whose ref was never filled (a crash between its saves) replays the completion that stands. */
	public function test_a_close_entry_without_its_ref_replays_the_standing_completion(): void {
		// Arrange: close c4 completes the sale; storage then holds its entry with a null ref.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$body      = $this->envelope( $order );
		$path      = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$announced = 0;
		add_action(
			'wcpos_sale_completed',
			static function () use ( &$announced ): void {
				++$announced;
			}
		);
		$first   = $this->command( $path, $body );
		$crashed = wc_get_order( $order->get_id() );
		$map     = json_decode( (string) $crashed->get_meta( '_wcpos_commands' ), true );
		$map[ $body['command_id'] ]['ref'] = null;
		$crashed->update_meta_data( '_wcpos_commands', (string) wp_json_encode( $map ) );
		$crashed->save();
		$this->assertNull( $this->stored_meta( $order->get_id(), '_wcpos_commands' )[ $body['command_id'] ]['ref'] );

		// Act: the same close, on the revision from before it.
		$again = $this->command( $path, $body );

		// Assert.
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( array( 'n' => 1, 'id' => $history[0]['id'] ), $again->get_data()['completion'] );
		$this->assertSame( $first->get_data()['completion'], $again->get_data()['completion'] );
		$this->assertSame( 1, $announced );
	}

	/** Pin 30: the close entry rides the completion's own save, so it survives a failed second save. */
	public function test_a_close_entry_is_stored_with_the_completion_before_its_ref_is_known(): void {
		// Arrange: every write of the command map that names a completion fails (the second save is lost).
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$body   = $this->envelope( $order );
		$path   = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$names  = static function ( $value ): bool {
			$map = json_decode( is_string( $value ) ? $value : '', true );
			return is_array( $map ) && array_filter( $map, static fn( $entry ) => null !== ( $entry['ref'] ?? null ) );
		};
		$refuse_add = static fn( $check, $object_id, $key, $value ) => '_wcpos_commands' === $key && $names( $value ) ? false : $check;
		$refuse_mid = static fn( $check, $meta_id, $value, $key ) => '_wcpos_commands' === $key && $names( $value ) ? false : $check;
		add_filter( 'add_post_metadata', $refuse_add, 10, 4 );
		add_filter( 'update_post_metadata_by_mid', $refuse_mid, 10, 4 );

		// Act.
		try {
			$first = $this->command( $path, $body );
		} finally {
			remove_filter( 'add_post_metadata', $refuse_add, 10 );
			remove_filter( 'update_post_metadata_by_mid', $refuse_mid, 10 );
		}
		$again = $this->command( $path, $body );

		// Assert: the unverified close is a retryable conflict; its staged entry replays the completion.
		$this->assertSame( 409, $first->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $first->get_data()['code'] );
		$stored = $this->stored_meta( $order->get_id(), '_wcpos_commands' );
		$this->assertArrayHasKey( $body['command_id'], (array) $stored );
		$this->assertNull( $stored[ $body['command_id'] ]['ref'] );
		$this->assertSame( 200, $again->get_status() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( array( 'n' => 1, 'id' => $history[0]['id'] ), $again->get_data()['completion'] );
	}

	/** Pin 34: a close whose entry did not reach storage is not acknowledged; the completion stands for the retry. */
	public function test_a_close_whose_entry_is_not_stored_is_a_retryable_conflict(): void {
		// Arrange: a covered sale; every write of the command map fails.
		$order = $this->create_pos_order();
		Ledger::instance()->save( $order, array( $this->captured_row( '92.95' ) ), false );
		$path       = '/wcpos/v2/orders/' . $order->get_id() . '/close';
		$refuse_add = static fn( $check, $object_id, $key ) => '_wcpos_commands' === $key ? false : $check;
		$refuse_mid = static fn( $check, $meta_id, $value, $key ) => '_wcpos_commands' === $key ? false : $check;
		add_filter( 'add_post_metadata', $refuse_add, 10, 3 );
		add_filter( 'update_post_metadata_by_mid', $refuse_mid, 10, 4 );

		// Act.
		try {
			$refused = $this->command( $path, $this->envelope( $order ) );
		} finally {
			remove_filter( 'add_post_metadata', $refuse_add, 10 );
			remove_filter( 'update_post_metadata_by_mid', $refuse_mid, 10 );
		}
		$retry = $this->envelope( $order );
		$again = $this->command( $path, $retry );

		// Assert.
		$this->assertSame( 409, $refused->get_status(), wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $refused->get_data()['code'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( array( 'n' => 1, 'id' => $history[0]['id'] ), $again->get_data()['completion'] );
		$this->assertSame( $history[0]['id'], $this->stored_meta( $order->get_id(), '_wcpos_commands' )[ $retry['command_id'] ]['ref'] );
	}

	/** Pin 28: a record whose row id another command already consumed is a body conflict and creates no row. */
	public function test_a_record_reusing_a_command_id_as_its_row_id_is_422(): void {
		// Arrange: intent c on row P, through the test handler.
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		$mode = static fn() => 'route_test';
		add_filter( 'wcpos_payment_method_capture_mode', $mode );
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '40.00' );
		$body    = $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) );
		$this->assertSame( 200, $this->command( $this->payment_path( $order, $payment['id'] ) . '/intent', $body )->get_status() );
		remove_filter( 'wcpos_payment_method_capture_mode', $mode );

		// Act: cash recorded under the intent's command id as its row id.
		$response = $this->record( $order, $this->payment( 'pos_cash', '10.00', array( 'id' => $body['command_id'] ) ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( array( $payment['id'] ), array_column( $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'], 'id' ) );
	}

	/** Pin 28: a recorded row's id is its command id: another command reusing it is a body conflict. */
	public function test_a_command_reusing_a_recorded_rows_id_is_422(): void {
		// Arrange: cash row R and a refund object.
		Route_Handler::$refunds = 0;
		$order = $this->create_pos_order();
		$row   = $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_data()['payment'];
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => '10.00',
				'refund_payment' => false,
			)
		);

		// Act.
		$response = $this->command(
			$this->payment_path( $order, $row['id'] ) . '/refund',
			array_merge(
				$this->envelope( $order, array( 'refund_id' => $refund->get_id(), 'amount' => '10.00' ) ),
				array( 'command_id' => $row['id'] )
			)
		);

		// Assert.
		$this->assertSame( 422, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( array(), $this->stored_row( $order->get_id(), $row['id'] )['refunds'] );
		$this->assertSame( 0, Route_Handler::$refunds );
	}

	/** Pin 31: an intent whose resume fails determinately keeps no entry, so its retry is a fresh command. */
	public function test_an_intent_that_fails_determinately_on_resume_keeps_no_entry(): void {
		// Arrange: the first answer is lost (indeterminate), the resume is declined.
		$this->use_route_handler();
		Route_Handler::$intent_errors = array(
			new \WP_Error( 'wcpos_provider_timeout', 'No answer', array( 'status' => 504, 'indeterminate' => true ) ),
			new \WP_Error( 'wcpos_provider_error', 'Declined', array( 'status' => 502 ) ),
		);
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/intent';
		$body    = $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) );

		// Act.
		$lost     = $this->command( $path, $body );
		$entry    = $this->stored_row( $order->get_id(), $payment['id'] )['commands'];
		$declined = $this->command( $path, $body );
		$failed   = $this->stored_row( $order->get_id(), $payment['id'] );
		$stale    = $this->command( $path, $body );
		$current  = $this->command( $path, array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 504, $lost->get_status() );
		$this->assertArrayHasKey( $body['command_id'], $entry );
		$this->assertSame( 502, $declined->get_status() );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertArrayNotHasKey( $body['command_id'], $failed['commands'] );
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'wcpos_revision_stale', $stale->get_data()['code'] );
		$this->assertSame( 200, $current->get_status(), wp_json_encode( $current->get_data() ) );
		$this->assertSame( 'failed', $current->get_data()['payment']['status'] );
	}

	/**
	 * Pin 32: a record's whole body is its fingerprint: the same row id with any other body is a conflict.
	 *
	 * @param array $other The body field that differs.
	 * @dataProvider other_record_bodies
	 */
	public function test_a_record_replayed_with_another_body_is_422( array $other ): void {
		// Arrange: a partial cash row.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00', array( 'tendered' => '20.00' ) );
		$this->assertSame( 200, $this->record( $order, $payment )->get_status() );
		$before = $this->stored_meta( $order->get_id(), Ledger::META_KEY );

		// Act.
		$body = array_merge( array( 'payment' => $payment ), $other );
		if ( isset( $other['payment'] ) ) {
			$body['payment'] = array_merge( $payment, $other['payment'] );
		}
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( $payment['id'], $response->get_data()['data']['payment']['id'] );
		$this->assertSame( $before, $this->stored_meta( $order->get_id(), Ledger::META_KEY ) );
	}

	/** Record bodies that differ from the first only outside method, amount and currency. */
	public function other_record_bodies(): array {
		return array(
			'tendered'   => array( array( 'payment' => array( 'tendered' => '50.00' ) ) ),
			'provenance' => array( array( 'provenance' => array( 'register_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ) ) ),
		);
	}

	/** Pin 32: a record naming a row another command created is a conflict, even with matching money fields. */
	public function test_a_record_naming_an_intent_rows_id_is_422(): void {
		// Arrange: intent row P through the test handler.
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'route_test', Route_Handler::class );
		$mode = static fn() => 'route_test';
		add_filter( 'wcpos_payment_method_capture_mode', $mode );
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '40.00' );
		$this->assertSame( 200, $this->command( $this->payment_path( $order, $payment['id'] ) . '/intent', $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) ) )->get_status() );
		remove_filter( 'wcpos_payment_method_capture_mode', $mode );
		Route_Handler::$intents = 0;
		$before = $this->stored_row( $order->get_id(), $payment['id'] );

		// Act: record the same id, method, amount and currency.
		$response = $this->record( $order, $payment );

		// Assert.
		$this->assertSame( 422, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( 0, Route_Handler::$intents );
		$this->assertSame( $before, $this->stored_row( $order->get_id(), $payment['id'] ) );
	}

	/** Pin 32: a refused record replays from its own entry: the same refusal, one failed row. */
	public function test_a_refused_record_replays_its_refusal_with_a_stale_revision(): void {
		// Arrange: a paid sale, then cash the order no longer owes.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		$late = $this->payment( 'pos_cash', '10.00' );
		$body = $this->envelope( $order, array( 'payment' => $late ) );
		$path = '/wcpos/v2/orders/' . $order->get_id() . '/payments';

		// Act.
		$refused = $this->command( $path, $body );
		$again   = $this->command( $path, $body );

		// Assert.
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $refused->get_data()['code'] );
		$this->assertSame( 409, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( 'wcpos_order_already_paid', $again->get_data()['code'] );
		$row = $this->stored_row( $order->get_id(), $late['id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'record', $row['commands'][ $late['id'] ]['kind'] );
		$this->assertCount( 2, $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'] );
	}

	/** Pin 33: a 2xx command whose revision cannot be read answers the read's error; its retry replays. */
	public function test_a_command_whose_answer_revision_cannot_be_read_is_an_error_and_its_retry_replays(): void {
		// Arrange: a pending row; the order read fails the second time this request asks.
		$order   = $this->create_pos_order();
		$pending = $this->payment( 'pos_cash', '10.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $pending ), false );
		$body  = $this->envelope( $order, array( 'reason' => 'Customer cancelled' ) );
		$route = '/wc/v3/orders/' . $order->get_id();
		$reads = 0;
		$deny  = static function ( $result, $server, $request ) use ( $route, &$reads ) {
			if ( $route === $request->get_route() && 2 === ++$reads ) {
				return new \WP_Error( 'woocommerce_rest_cannot_view', 'Sorry, you cannot view this resource.', array( 'status' => 403 ) );
			}
			return $result;
		};
		add_filter( 'rest_pre_dispatch', $deny, 10, 3 );

		// Act.
		try {
			$voided = $this->command( $this->payment_path( $order, $pending['id'] ) . '/void', $body );
		} finally {
			remove_filter( 'rest_pre_dispatch', $deny, 10 );
		}
		$replayed = $this->command( $this->payment_path( $order, $pending['id'] ) . '/void', $body );

		// Assert.
		$this->assertSame( 403, $voided->get_status(), wp_json_encode( $voided->get_data() ) );
		$this->assertSame( 'woocommerce_rest_cannot_view', $voided->get_data()['code'] );
		$this->assertSame( 'voided', $this->stored_row( $order->get_id(), $pending['id'] )['status'] );
		$this->assertSame( 200, $replayed->get_status() );
		$this->assertSame( $this->current_revision( $order ), $replayed->get_data()['revision'] );
	}

	/** Pin 36: an intent replay resumes its row after the merchant disabled the method; a new intent is still refused. */
	public function test_an_intent_replay_resumes_after_the_method_is_disabled(): void {
		// Arrange: intent c1 on the test handler, then the method is switched off for the POS.
		$this->use_route_handler();
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '40.00' );
		$path    = $this->payment_path( $order, $payment['id'] ) . '/intent';
		$body    = $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) );
		$this->assertSame( 200, $this->command( $path, $body )->get_status() );
		add_filter(
			'wcpos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['pos_cash']['enabled'] = false;
				return $settings;
			},
			20
		);

		// Act.
		$resumed = $this->command( $path, $body );
		$other   = $this->payment( 'pos_cash', '10.00' );
		$fresh   = $this->command( $this->payment_path( $order, $other['id'] ) . '/intent', $this->envelope( $order, array( 'payment' => $other, 'context' => array( 'reader' => 'reader-1' ) ) ) );

		// Assert.
		$this->assertSame( 200, $resumed->get_status(), wp_json_encode( $resumed->get_data() ) );
		$this->assertSame( array( 'reader' => 'reader-1' ), $resumed->get_data()['handoff'] );
		$this->assertSame( 403, $fresh->get_status() );
		$this->assertSame( 'wcpos_payment_method_disabled', $fresh->get_data()['code'] );
		$this->assertCount( 1, $this->stored_meta( $order->get_id(), Ledger::META_KEY )['payments'] );
	}

	/** Create an open POS order at the contract total. */
	/**
	 * A captured cash row, for seeding without deriving.
	 *
	 * @param string $amount Amount.
	 */
	private function captured_row( string $amount ): array {
		return array(
			'id' => wp_generate_uuid4(),
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'capture_mode' => 'manual',
			'amount' => $amount,
			'status' => 'captured',
		);
	}

	/**
	 * A POS sale paid in cash: one stock-managed product line (stock 10) per price, at a quantity.
	 *
	 * @param string[] $prices   Line prices.
	 * @param int      $quantity Units per line.
	 * @return array{0: \WC_Order, 1: int[]} The paid order and its product ids.
	 */
	private function paid_sale_of( array $prices, int $quantity ): array {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$products = array();
		foreach ( $prices as $price ) {
			$product = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
			$product->set_regular_price( $price );
			$product->set_price( $price );
			$product->set_tax_status( 'none' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 10 );
			$product->save();
			$order->add_product( $product, $quantity );
			$products[] = $product->get_id();
		}
		$order->calculate_totals( false );
		$order->save();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', $order->get_total(), array( 'currency' => $order->get_currency() ) ) )->get_status() );
		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid() );
		return array( $paid, $products );
	}

	/**
	 * Close through the route.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function close( \WC_Order $order ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' );
		$request->set_body_params( $this->envelope( $order ) );
		return $this->server->dispatch( $request );
	}

	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}

	/**
	 * Build a valid payment request row.
	 *
	 * @param string $method_id Payment method ID.
	 * @param string $amount    Payment amount.
	 * @param array  $extra     Extra row fields.
	 */
	private function payment( string $method_id, string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => $method_id,
				'amount'    => $amount,
				'currency'  => 'USD',
			),
			$extra
		);
	}

	/**
	 * Dispatch the payment record request.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param array     $payment Payment row.
	 */
	private function record( \WC_Order $order, array $payment ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_body_params( $this->envelope( $order, array( 'payment' => $payment ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a payment void request.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $id    Payment UUID.
	 */
	private function void( \WC_Order $order, string $id ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $id ) . '/void' );
		$request->set_body_params( $this->envelope( $order, array( 'reason' => 'Customer cancelled' ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Build a payment-row route prefix.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $id    Payment UUID.
	 */
	private function payment_path( \WC_Order $order, string $id ): string {
		return '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $id;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Route fixtures stay with their tests.
/** A local handler keeps route tests independent of a provider. */
class Route_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler {
	/**
	 * Response-only provider prompt.
	 *
	 * @var array|null
	 */
	public static $prompt = null;

	/**
	 * Fields the provider's status answer carries, when set.
	 *
	 * @var array|null
	 */
	public static $answer = null;

	/**
	 * Provider capture calls, for replay pins.
	 *
	 * @var int
	 */
	public static $captures = 0;

	/**
	 * Provider refund calls, for replay pins.
	 *
	 * @var int
	 */
	public static $refunds = 0;

	/**
	 * Provider intent calls, for replay pins.
	 *
	 * @var int
	 */
	public static $intents = 0;

	/**
	 * Errors the next intent calls answer, in order.
	 *
	 * @var \WP_Error[]
	 */
	public static $intent_errors = array();

	/**
	 * Return a transient prompt when the provider asks the cashier a question.
	 *
	 * @param array $row Payment row.
	 */
	public function status( array $row ) {
		if ( null !== self::$answer ) {
			$row = array_merge( $row, self::$answer );
		}
		if ( null !== self::$prompt ) {
			$row['prompt'] = self::$prompt;
		}
		return $row;
	}

	/**
	 * Describe the test capture mode.
	 *
	 * @param \WC_Payment_Gateway $gateway Payment gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'route_test';
		return $descriptor;
	}
	/**
	 * Return a pending intent with its handoff.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function intent( array $row, array $context ) {
		++self::$intents;
		if ( self::$intent_errors ) {
			return array_shift( self::$intent_errors );
		}
		$row['handoff'] = array( 'reader' => $context['reader'] );
		return $row;
	}
	/**
	 * Return the provider-confirmed capture.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function capture( array $row, array $context ) {
		++self::$captures;
		return array_merge( array( 'status' => 'captured' ), $context );
	}

	/**
	 * The refusal the next refunds answer, when set.
	 *
	 * @var \WP_Error|null
	 */
	public static $refund_error = null;

	/**
	 * Count and settle a refund allocation, or refuse it as set.
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund id.
	 * @param string $amount    Amount.
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		++self::$refunds;
		return self::$refund_error ?? parent::refund( $row, $refund_id, $amount );
	}
}

/** A declined provider returns redacted diagnostics on its error. */
class Failure_Route_Handler extends Route_Handler {
	/**
	 * Return a provider failure without a successful capture result.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function capture( array $row, array $context ) {
		return new \WP_Error(
			'wcpos_provider_error',
			'declined',
			array(
				'status' => 502,
				'events' => array(
					array(
						't'       => gmdate( 'c' ),
						'level'   => 'error',
						'message' => 'Capture failed: card_declined',
					),
				),
			)
		);
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
