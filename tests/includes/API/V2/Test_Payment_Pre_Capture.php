<?php
/**
 * Pre-capture veto tests on the locked money routes.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Command_Log;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WP_Error;

/** Exercises the veto through real payment commands. */
class Test_Payment_Pre_Capture extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	/** @var array Filter calls, in order. */
	private array $calls = array();

	/** @var array Captured log messages. */
	private array $logged_messages = array();

	/** @var \Closure Log capture removed in tearDown. */
	private $log_filter;

	/** Capture log attempts without writing to disk. */
	public function setUp(): void {
		parent::setUp();
		Veto_Route_Handler::$intents  = 0;
		Veto_Route_Handler::$captures = 0;
		Logger::reset_dedup_state();
		$this->logged_messages = array();
		$this->log_filter      = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'wcpos_logging', $this->log_filter, 10, 2 );
	}

	/** Release the log capture. */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', $this->log_filter, 10 );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/** A refused record returns the current order and the cashier-readable error. */
	public function test_pre_capture_record_refused_returns_current_state_without_writing(): void {
		$order  = $this->create_pos_order();
		$before = $this->snapshot( $order );
		$this->refuse( array( 'record' ) );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response );
		$answer = $response->get_data();
		$data   = $answer['data'];
		$this->assertSame( 'Refused for the test.', $answer['message'] );
		$this->assertSame( $this->current_revision( $order ), $data['currentRevision'] );
		$this->assertIsArray( $data['current'] );
		$this->assertSame( $order->get_id(), $data['current']['id'] );
		$this->assertSame( Ledger::instance()->summary( wc_get_order( $order->get_id() ) ), $data['order'] );
		$this->assertArrayNotHasKey( 'detail', $data );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assert_call( $order, 'record', 5000, 'pos_cash' );
	}

	/** A veto stops the intent before the provider sees it. */
	public function test_pre_capture_intent_refused_never_calls_provider(): void {
		$this->use_route_handler();
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );
		$before  = $this->snapshot( $order );
		$this->refuse( array( 'intent' ) );

		$response = $this->command( $this->payment_path( $order, $payment['id'] ) . '/intent', $this->envelope( $order, array( 'payment' => $payment, 'context' => array( 'reader' => 'reader-1' ) ) ) );

		$this->assert_veto( $response );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assertSame( 0, Veto_Route_Handler::$intents );
		$this->assert_call( $order, 'intent', 9295, 'pos_cash' );
	}

	/** A veto stops a gateway submit without recording its attempt. */
	public function test_pre_capture_submit_refused_leaves_storage_unchanged(): void {
		$order  = $this->create_pos_order();
		$before = $this->snapshot( $order );
		$this->refuse( array( 'submit' ) );

		$response = $this->submit( $order, 'a3e555f4-a844-4418-819a-a5dd60fe334c', array(), 'pos_cash' );

		$this->assert_veto( $response );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assert_call( $order, 'submit', null, 'pos_cash' );
	}

	/** R7: a refused capture leaves the intent available to void. */
	public function test_pre_capture_pending_capture_refused_leaves_row_voidable(): void {
		$this->use_route_handler();
		$order   = $this->create_pos_order();
		$payment = $this->intended( $order, '92.95' );
		$before  = $this->snapshot( $order );
		$this->refuse( array( 'capture' ) );

		$response = $this->command( $this->payment_path( $order, strtoupper( $payment['id'] ) ) . '/capture', $this->envelope( $order ) );

		$this->assert_veto( $response );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assertSame( 'pending', $this->stored_row( $order->get_id(), $payment['id'] )['status'] );
		$this->assertSame( 0, Veto_Route_Handler::$captures );
		$this->assert_call( $order, 'capture', 9295, 'pos_cash' );
		remove_all_filters( 'wcpos_payment_pre_capture' );
		$this->assertSame( 200, $this->void( $order, $payment['id'] )->get_status() );
		$this->assertSame( 'voided', $this->stored_row( $order->get_id(), $payment['id'] )['status'] );
	}

	/** R7: a refused capture preserves an authorization too. */
	public function test_pre_capture_authorized_capture_refused_leaves_row_voidable(): void {
		$this->use_route_handler();
		$order = $this->create_pos_order();
		$row   = array(
			'id'           => 'a3e555f4-a844-4418-819a-a5dd60fe334c',
			'method_id'    => 'pos_cash',
			'kind'         => 'cash',
			'capture_mode' => 'veto_route',
			'amount'       => '92.95',
			'status'       => 'authorized',
		);
		Ledger_Seed::rows( $order, array( $row ), false );
		$before = $this->snapshot( $order );
		$this->refuse( array( 'capture' ) );

		$response = $this->command( $this->payment_path( $order, $row['id'] ) . '/capture', $this->envelope( $order ) );

		$this->assert_veto( $response );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assertSame( 'authorized', $this->stored_row( $order->get_id(), $row['id'] )['status'] );
		$this->assertSame( 0, Veto_Route_Handler::$captures );
		remove_all_filters( 'wcpos_payment_pre_capture' );
		$this->assertSame( 200, $this->void( $order, $row['id'] )->get_status() );
		$this->assertSame( 'voided', $this->stored_row( $order->get_id(), $row['id'] )['status'] );
	}

	/** Even a fully covered sale stays open on refusal. */
	public function test_pre_capture_close_refused_leaves_covered_sale_open(): void {
		$order = $this->create_pos_order();
		Ledger_Seed::rows( $order, array( $this->captured_row( '92.95' ) ), false );
		$before = $this->snapshot( $order );
		$this->refuse( array( 'close' ) );

		$response = $this->close( $order );

		$this->assert_veto( $response );
		$this->assertSame( $before, $this->snapshot( $order ) );
		$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assert_call( $order, 'close', null, null );
	}

	/** M16: both documents and the retry revision reflect the saved credit change. */
	public function test_pre_capture_credit_reduced_returns_new_balance_and_revision(): void {
		$order = $this->create_pos_order();
		$order->set_total( '42.95' );
		$order->save();
		$route = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$body  = $this->envelope( $order, array( 'payment' => $this->payment( 'pos_cash', '42.95' ) ) );
		add_filter(
			'wcpos_payment_pre_capture',
			static function ( $veto, $order, $kind ) {
				if ( 'record' !== $kind ) {
					return $veto;
				}
				$order = wc_get_order( $order->get_id() );
				$order->set_total( '72.95' );
				$order->save();
				return new WP_Error( 'wcpos_test_credit_short', 'Store credit changed.' );
			},
			10,
			3
		);

		$response = $this->command( $route, $body );

		$this->assert_veto( $response, 'wcpos_test_credit_short' );
		$data = $response->get_data()['data'];
		$this->assertSame( '72.950000', $data['current']['total'] );
		$this->assertSame( '72.95', $data['order']['total'] );
		$this->assertSame( '72.95', $data['order']['balance'] );
		$this->assertNotSame( $body['revision'], $data['currentRevision'] );
		$this->assertSame( $this->current_revision( $order ), $data['currentRevision'] );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		remove_all_filters( 'wcpos_payment_pre_capture' );
		$retry = $this->envelope( $order, array( 'payment' => $this->payment( 'pos_cash', '72.95' ), 'revision' => $data['currentRevision'] ) );
		$this->assertSame( 200, $this->command( $route, $retry )->get_status() );
	}

	/** If this ever answers `wcpos_order_already_paid`, that is woocommerce-pos#2858, not this veto. */
	public function test_pre_capture_zero_total_close_refused_leaves_new_balance_open(): void {
		$order = $this->zero_total_open_order();
		add_filter(
			'wcpos_payment_pre_capture',
			static function ( $veto, $order, $kind ) {
				if ( 'close' !== $kind ) {
					return $veto;
				}
				$order->set_total( '10.00' );
				$order->save();
				return new WP_Error( 'wcpos_test_refused', 'Store credit changed.' );
			},
			10,
			3
		);

		$response = $this->close( $order );

		$this->assert_veto( $response );
		$this->assertSame( '10.00', $response->get_data()['data']['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertFalse( $stored->is_paid() );
	}

	/** M24: refusal leaves the command id unused. */
	public function test_pre_capture_identical_refused_command_runs_again_then_records(): void {
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '50.00' );
		$route   = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$body    = $this->envelope( $order, array( 'payment' => $payment ) );
		$this->refuse( array( 'record' ) );

		$first  = $this->command( $route, $body );
		$second = $this->command( $route, $body );

		$this->assert_veto( $first );
		$this->assert_veto( $second );
		$this->assertCount( 2, $this->calls );
		$this->assertNull( $this->stored_row( $order->get_id(), $payment['id'] ) );
		remove_all_filters( 'wcpos_payment_pre_capture' );
		$accepted = $this->command( $route, $body );
		$this->assertSame( 200, $accepted->get_status() );
		$this->assertSame( $payment['id'], $accepted->get_data()['payment']['id'] );
		$this->assertSame( 'captured', $this->stored_row( $order->get_id(), $payment['id'] )['status'] );
	}

	/** M24: a refusal which moved the revision makes the old request stale. */
	public function test_pre_capture_refusal_moves_revision_resend_is_stale(): void {
		$order = $this->create_pos_order();
		$route = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$body  = $this->envelope( $order, array( 'payment' => $this->payment( 'pos_cash', '50.00' ) ) );
		add_filter(
			'wcpos_payment_pre_capture',
			static function ( $veto, $order, $kind ) {
				if ( 'record' !== $kind ) {
					return $veto;
				}
				$order->set_total( '100.00' );
				$order->save();
				return new WP_Error( 'wcpos_test_refused', 'Order changed.' );
			},
			10,
			3
		);

		$first = $this->command( $route, $body );
		remove_all_filters( 'wcpos_payment_pre_capture' );
		$second = $this->command( $route, $body );

		$this->assert_veto( $first );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'wcpos_revision_stale', $second->get_data()['code'] );
	}

	/** Already answered commands replay even when a callback would now refuse. */
	public function test_pre_capture_record_replay_skips_veto(): void {
		$order = $this->create_pos_order();
		$route = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$body  = $this->envelope( $order, array( 'payment' => $this->payment( 'pos_cash', '50.00' ) ) );
		$first = $this->command( $route, $body );
		$this->assertSame( 200, $first->get_status() );
		$this->refuse( array( 'record' ) );

		$replay = $this->command( $route, $body );

		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( $first->get_data()['payment']['id'], $replay->get_data()['payment']['id'] );
		$this->assertSame( array(), $this->calls );
	}

	/** The stale-revision check has precedence over the store. */
	public function test_pre_capture_stale_revision_skips_veto(): void {
		$order = $this->create_pos_order();
		$this->refuse( array( 'record' ) );
		$body = $this->envelope( $order, array( 'payment' => $this->payment( 'pos_cash', '50.00' ) ) );
		$order->set_total( '100.00' );
		$order->save();

		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', $body );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_revision_stale', $response->get_data()['code'] );
		$this->assertSame( array(), $this->calls );
	}

	/** The status poll and the other money commands never ask for a veto. */
	public function test_pre_capture_other_routes_only_count_setup_intent(): void {
		$this->use_route_handler();
		$order = $this->create_pos_order();
		$this->refuse( array() );
		$payment = $this->intended( $order, '92.95' );
		$route   = $this->payment_path( $order, $payment['id'] );

		$this->void( $order, $payment['id'] );
		$this->server->dispatch( $this->wp_rest_get_request( $route . '/status' ) );
		$this->command( $route . '/correct', $this->envelope( $order, array( 'method_id' => 'pos_card' ) ) );
		$this->command( $route . '/refund', $this->envelope( $order, array( 'amount' => '1.00', 'refund_id' => 1 ) ) );
		$this->cancel( $order, 'a3e555f4-a844-4418-819a-a5dd60fe334c', 'pos_cash' );

		$this->assertSame( array( 'intent' ), array_column( $this->calls, 'kind' ) );
	}

	/** R5: a later filter can pass through the first refusal. */
	public function test_pre_capture_chained_callbacks_preserve_first_error(): void {
		$order    = $this->create_pos_order();
		$received = null;
		$this->refuse( array( 'record' ), 'wcpos_test_first' );
		add_filter(
			'wcpos_payment_pre_capture',
			static function ( $veto ) use ( &$received ) {
				$received = $veto;
				return $veto;
			},
			20
		);

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response, 'wcpos_test_first' );
		$this->assertInstanceOf( WP_Error::class, $received );
		$this->assertSame( 'wcpos_test_first', $received->get_error_code() );
	}

	/** An extension cannot return an unrelated REST error code. */
	public function test_pre_capture_unprefixed_code_uses_default_code_and_keeps_message(): void {
		$order = $this->create_pos_order();
		$this->refuse( array( 'record' ), 'store_credit_short', 'Credit changed.' );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response, 'wcpos_payment_vetoed' );
		$this->assertSame( 'Credit changed.', $response->get_data()['message'] );
	}

	/**
	 * A reserved refusal code uses the default code and keeps the callback's message.
	 *
	 * @dataProvider reserved_codes
	 * @param string $code Refusal code.
	 */
	public function test_pre_capture_reserved_code_uses_default_code_and_keeps_message( string $code ): void {
		$order = $this->create_pos_order();
		$this->refuse( array( 'record' ), $code, 'Credit changed.' );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response, 'wcpos_payment_vetoed' );
		$this->assertSame( 'Credit changed.', $response->get_data()['message'] );
	}

	/** Refusal codes reserved by the till or core. */
	public static function reserved_codes(): array {
		return array(
			'resendable stale'      => array( 'wcpos_revision_stale' ),
			'resendable locked'     => array( 'wcpos_payment_locked' ),
			'resendable unprefixed' => array( 'woo_rxdb_sync_record_locked' ),
			'core already paid'     => array( 'wcpos_order_already_paid' ),
			'core conflict'         => array( 'wcpos_payment_conflict' ),
			'core service stock'    => array( 'wcpos_insufficient_stock' ),
		);
	}

	/** A refusal keeps the extension's own code and message. */
	public function test_pre_capture_free_wcpos_code_passes_through(): void {
		$order = $this->create_pos_order();
		$this->refuse( array( 'record' ), 'wcpos_store_credit_insufficient', 'Credit changed.' );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response, 'wcpos_store_credit_insufficient' );
		$this->assertSame( 'Credit changed.', $response->get_data()['message'] );
	}

	/** An empty callback message gets cashier-readable copy. */
	public function test_pre_capture_empty_message_uses_default_message(): void {
		$order = $this->create_pos_order();
		$this->refuse( array( 'record' ), 'wcpos_test_empty', '' );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response, 'wcpos_test_empty' );
		$this->assertSame( 'The store refused this payment. Check the order and try again.', $response->get_data()['message'] );
	}

	/** Detail passes through untouched; the callback cannot set the HTTP status. */
	public function test_pre_capture_extension_data_keeps_detail_and_core_status(): void {
		$order  = $this->create_pos_order();
		$detail = array( 'store_credit' => array( 'code' => 'X', 'applied_before' => '50.00', 'applied_now' => '20.00' ) );
		$this->refuse( array( 'record' ), 'wcpos_test_refused', 'Credit changed.', array( 'detail' => $detail, 'status' => 400 ) );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assert_veto( $response );
		$this->assertSame( $detail, $response->get_data()['data']['detail'] );
		$this->assertSame( 409, $response->get_data()['data']['status'] );
	}

	/** R3: a throwing extension is logged, not treated as a refusal. */
	public function test_pre_capture_throwing_callback_logs_and_allows_record(): void {
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '50.00' );
		add_filter(
			'wcpos_payment_pre_capture',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		$response = $this->record( $order, $payment );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'captured', $this->stored_row( $order->get_id(), $payment['id'] )['status'] );
		$this->assertNotEmpty( array_filter( $this->logged_messages, static fn( $line ) => false !== strpos( $line, 'WCPOS payment veto' ) && false !== strpos( $line, 'boom' ) ) );
	}

	/**
	 * Only a WP_Error refuses the command.
	 *
	 * @dataProvider non_error_values
	 * @param mixed $value Callback return value.
	 */
	public function test_pre_capture_non_error_value_allows_record( $value ): void {
		$order = $this->create_pos_order();
		add_filter( 'wcpos_payment_pre_capture', static fn() => $value );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/** Values that do not veto a command. */
	public static function non_error_values(): array {
		return array( array( true ), array( 'no' ), array( array( 'refuse' ) ) );
	}

	/** Without an extension the record route takes its ordinary path. */
	public function test_pre_capture_without_filter_allows_record(): void {
		$order = $this->create_pos_order();
		$this->assertFalse( has_filter( 'wcpos_payment_pre_capture' ) );

		$response = $this->record( $order, $this->payment( 'pos_cash', '50.00' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Observe each call and refuse just the listed kinds.
	 *
	 * @param array  $kinds   Kinds to refuse.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Error data.
	 */
	private function refuse( array $kinds, string $code = 'wcpos_test_refused', string $message = 'Refused for the test.', $data = array() ): void {
		add_filter(
			'wcpos_payment_pre_capture',
			function ( $veto, $order, $kind, $context ) use ( $kinds, $code, $message, $data ) {
				$this->calls[] = array( 'kind' => $kind, 'context' => $context, 'order_id' => $order->get_id() );
				if ( is_wp_error( $veto ) ) {
					return $veto;
				}
				return in_array( $kind, $kinds, true ) ? new WP_Error( $code, $message, $data ) : null;
			},
			10,
			4
		);
	}

	/**
	 * The stored money state and paid transition count that a veto must leave alone.
	 *
	 * @param \WC_Order $order Order object.
	 */
	private function snapshot( \WC_Order $order ): array {
		$stored = wc_get_order( $order->get_id() );
		return array(
			'ledger'   => $this->stored_meta( $order->get_id(), Ledger::META_KEY ),
			'commands' => $this->stored_meta( $order->get_id(), Command_Log::META_KEY ),
			'status'   => $stored->get_status(),
			'paid'     => $stored->is_paid(),
			'complete' => did_action( 'woocommerce_payment_complete' ),
		);
	}

	/**
	 * Check the common refusal envelope.
	 *
	 * @param \WP_REST_Response $response Command response.
	 * @param string            $code     Expected error code.
	 */
	private function assert_veto( \WP_REST_Response $response, string $code = 'wcpos_test_refused' ): void {
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertSame( true, $response->get_data()['data']['vetoed'] );
	}

	/**
	 * Check exactly one call, including the full cashier context.
	 *
	 * @param \WC_Order   $order  Order object.
	 * @param string      $kind   Command kind.
	 * @param int|null    $amount Amount in minor units.
	 * @param string|null $method Method id.
	 */
	private function assert_call( \WC_Order $order, string $kind, ?int $amount, ?string $method ): void {
		$context = array( 'amount' => $amount, 'method' => $method, 'cashier_id' => get_current_user_id() );
		$this->assertSame( array( array( 'kind' => $kind, 'context' => $context, 'order_id' => $order->get_id() ) ), $this->calls );
	}

	/** Register the local counting handler. */
	private function use_route_handler(): void {
		Capture_Mode_Registry::instance()->register( 'veto_route', Veto_Route_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static fn() => 'veto_route' );
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
	 * Close through the route.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function close( \WC_Order $order ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' );
		$request->set_body_params( $this->envelope( $order ) );
		return $this->server->dispatch( $request );
	}

	/** Create an open POS order at the contract total. */
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

	/**
	 * Submit an attempt through the locked route.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $attempt Attempt UUID.
	 * @param mixed     $values  Submitted fields.
	 * @param string    $method  Gateway ID.
	 */
	private function submit( $order, $attempt, $values, $method = 'wcpos_sent_test' ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/submit' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'attempt_id' => $attempt,
					'values'     => $values,
				)
			)
		);
		return $this->server->dispatch( $request );
	}

	/**
	 * Cancel through the locked route.
	 *
	 * @param \WC_Order   $order   Order.
	 * @param string|null $attempt The attempt to cancel; null sends none.
	 * @param string      $method  Gateway ID.
	 */
	private function cancel( $order, $attempt, $method = 'wcpos_sent_test' ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/cancel' );
		$body    = array( 'reason' => 'Customer changed mind' );
		if ( null !== $attempt ) {
			$body['attempt_id'] = $attempt;
		}
		$request->set_body_params( $this->envelope( $order, $body ) );
		return $this->server->dispatch( $request );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Route fixtures stay with their tests.
/** Counts provider calls so a veto cannot accidentally take money. */
class Veto_Route_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler {
	/** @var int Provider intent calls. */
	public static $intents = 0;

	/** @var int Provider capture calls. */
	public static $captures = 0;

	/**
	 * Describe the local capture mode.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor                    = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'veto_route';
		return $descriptor;
	}

	/**
	 * Return the reader handoff for a pending intent.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function intent( array $row, array $context ) {
		++self::$intents;
		$row['handoff'] = array( 'reader' => $context['reader'] );
		return $row;
	}

	/**
	 * Confirm capture when called.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function capture( array $row, array $context ) {
		++self::$captures;
		return array_merge( array( 'status' => 'captured' ), $context );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
