<?php
/**
 * Payments controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Payments controller tests. */
class Test_Payments_Controller extends WCPOS_REST_Unit_Test_Case {
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
		Route_Handler::$prompt = null;
		Route_Handler::$answer = null;
		Logger::reset_dedup_state();
		$this->logged_messages = array();
		$this->log_filter = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'woocommerce_pos_logging', $this->log_filter, 10, 2 );
		// WP restores hooks between tests while the writer singleton survives: arm its sale trigger.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
	}

	/** Remove the capture filter after each test. */
	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_logging', $this->log_filter, 10 );
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
		add_action( 'woocommerce_pos_payment_recorded', $listener, 10, 2 );
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
			remove_action( 'woocommerce_pos_payment_recorded', $listener, 10 );
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
		add_action( 'woocommerce_pos_payment_refused', $listener, 10, 2 );
		try {
			$response = $this->record( $order, $payment );
		} finally {
			remove_action( 'woocommerce_pos_payment_refused', $listener, 10 );
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

	/** A reused id with different money conflicts. */
	public function test_record_conflicting_replay_returns_409_conflict(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->record( $order, array_merge( $payment, array( 'amount' => '21.00' ) ) );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $response->get_data()['code'] );
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
		add_action( 'woocommerce_pos_payment_voided', $listener, 10, 4 );
		try {
			$response = $this->void( $order, $payment['id'] );
		} finally {
			remove_action( 'woocommerce_pos_payment_voided', $listener, 10 );
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
		$this->assertCount( 1, Completion_Stamp::read( $stored ) );
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
		$history = Completion_Stamp::read( $stored );
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
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
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
		$this->assertSame( array(), Completion_Stamp::read( $stored ) );
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
		$this->assertSame( array(), Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
	}

	/** Closing a paid sale again replays its completion and writes nothing. */
	public function test_close_on_a_paid_order_replays_the_last_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->record( $order, $this->payment( 'pos_cash', '92.95' ) )->get_status() );
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );

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
		$this->assertSame( $history, Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
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
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );

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
		$this->assertSame( $history, Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
	}

	/** A sale paid before the completion history existed stands: close replays it with no completion and writes nothing. */
	public function test_close_on_a_legacy_paid_sale_replays_with_no_completion(): void {
		// Arrange: paid, no ledger rows, no history.
		$order = $this->create_pos_order();
		$paid  = wc_get_order( $order->get_id() );
		$paid->set_date_paid( time() );
		$paid->set_status( 'processing' );
		$paid->save();
		$this->assertSame( array(), Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );

		// Act.
		$response = $this->close( $order );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertNull( $response->get_data()['completion'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
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
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $history );
		$this->assertSame( $register, $history[0]['register'] );
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
			array(
				'refund_id' => $refund->get_id(),
				'amount' => '10.00',
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
		$this->assertSame( array(), Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
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
			array(
				'payment' => $payment,
				'context' => array(
					'reader' => 'reader-1',
					'cashier_id' => 0,
				),
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['payment']['status'] );
		$this->assertSame( get_current_user_id(), $response->get_data()['payment']['cashier_id'] );
		$this->assertSame( array( 'reader' => 'reader-1' ), $response->get_data()['handoff'] );
		$this->assertSame( 'pending', $response->get_data()['order']['status'] );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/capture' );
		$request->set_body_params( array( 'context' => array( 'amount' => '92.95' ) ) );
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
		$request->set_body_params( array( 'payment' => $first, 'context' => array() ) );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$second = $this->payment( 'pos_cash', '92.95' );
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $second['id'] ) . '/intent' );
		$request->set_body_params( array( 'payment' => $second, 'context' => array() ) );
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
			array(
				'refund_id' => $refund->get_id(),
				'amount' => '5.00',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'payment' ), array_keys( $response->get_data() ) );
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
				array(
					'payment' => $payment,
					'context' => array( 'reader' => 'reader-1' ),
				)
			);
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
			$request = $this->wp_rest_post_request( $this->payment_path( $order, $payment['id'] ) . '/capture' );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 502, $response->get_status() );
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
		return $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' ) );
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
		$request->set_body_params( array( 'payment' => $payment ) );

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
		$request->set_body_params( array( 'reason' => 'Customer cancelled' ) );

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
		return array_merge( array( 'status' => 'captured' ), $context );
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
