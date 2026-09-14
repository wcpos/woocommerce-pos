<?php
/**
 * Payments controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
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
		Logger::reset_dedup_state();
		$this->logged_messages = array();
		$this->log_filter = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'woocommerce_pos_logging', $this->log_filter, 10, 2 );
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
		$this->assertSame( array( sprintf( 'WCPOS payment %s refused on order #%d: order_already_paid (10.00 USD tendered, 0.00 owed)', $payment['id'], $order->get_id() ) ), $this->payment_logs() );
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
			$path = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
			if ( 'record' !== $action ) {
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
			array( 'record', 'intent', 'capture', 'status', 'void', 'refund' )
		);
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

	/** Refund responses contain only the payment row. */
	public function test_refund_response_contains_only_payment(): void {
		$order = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
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
