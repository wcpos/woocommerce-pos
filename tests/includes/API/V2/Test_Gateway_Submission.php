<?php
/**
 * Gateway submission route tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Submission reads money from the gateway, not from the verb. */
class Test_Gateway_Submission extends WCPOS_REST_Unit_Test_Case {
	/** Install real test gateways before registering routes. */
	public function setUp(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		add_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10, 2 );
		add_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10, 2 );
		parent::setUp();
		\WC_Payment_Gateways::instance()->init();
		Sent_Test_Gateway::$calls = 0;
		Sent_Test_Gateway::$posted = array();
		Sent_Test_Gateway::$failure = '';
		Paid_Test_Gateway::$calls = 0;
		Paid_Test_Gateway::$status_only = false;
		wc_clear_notices();
	}

	/** Remove only our fixtures. */
	public function tearDown(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		remove_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10 );
		remove_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10 );
		\WC_Payment_Gateways::instance()->init();
		unset( $_POST['invoice_email'], $_POST['save_email'] );
		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * Register fixtures.
	 *
	 * @param array $gateways Woo gateways.
	 */
	public function gateways( array $gateways ): array {
		return array_merge( $gateways, array( Sent_Test_Gateway::class, Paid_Test_Gateway::class ) );
	}

	/**
	 * Opt fixtures into gateway capture.
	 *
	 * @param string              $mode Mode.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function mode( $mode, $gateway ): string {
		return in_array( $gateway->id, array( 'wcpos_sent_test', 'wcpos_paid_test' ), true ) ? 'gateway' : $mode;
	}

	/**
	 * Declare the invoice form.
	 *
	 * @param mixed               $fields Fields.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 * @return array|null
	 */
	public function fields( $fields, $gateway ) {
		if ( 'wcpos_sent_test' !== $gateway->id ) {
			return $fields;
		}
		return array(
			'verb' => array(
				'kind' => 'send',
				'label' => 'Send invoice',
			),
			'components' => array(
				array(
					'component' => 'field',
					'id' => 'invoice_email',
					'input' => 'email',
					'label' => 'Email address',
					'required' => true,
					'default' => '',
					'prefill' => 'order.billing.email',
				),
				array(
					'component' => 'checkbox',
					'id' => 'save_email',
					'label' => 'Save email',
					'default' => false,
					'prefill' => null,
				),
			),
		);
	}

	/** Empty required values refuse without a stamp or money. */
	public function test_submit_empty_email_returns_keyed_validation_error(): void {
		// Arrange.
		$order = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => '' ) );
		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_fields_invalid', $response->get_data()['code'] );
		$this->assertSame( 'Enter email', $response->get_data()['data']['errors']['invoice_email'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_awaiting_customer' ) );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertArrayNotHasKey( 'invoice_email', $_POST );
	}

	/** Sent attempts persist their answer and never send twice on replay. */
	public function test_submit_email_stamps_order_and_replay_does_not_send(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit(
			$order,
			$attempt,
			array(
				'invoice_email' => 'buyer@example.com',
				'save_email' => true,
				'undeclared' => 'drop',
			)
		);
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$this->assertNull( $response->get_data()['payment'] );
		$this->assertSame( 'pending', $response->get_data()['order']['status'] );
		$this->assertSame( 'buyer@example.com', Sent_Test_Gateway::$posted['invoice_email'] );
		$this->assertSame( '1', Sent_Test_Gateway::$posted['save_email'] );
		$this->assertArrayNotHasKey( 'undeclared', Sent_Test_Gateway::$posted );
		$this->assertArrayNotHasKey( 'invoice_email', $_POST );
		$this->assertArrayNotHasKey( 'save_email', $_POST );
		$stored = wc_get_order( $order->get_id() );
		$stamp = Gateway_Submission::read_stamp( $stored );
		$this->assertSame( 'wcpos_sent_test', $stamp['method_id'] );
		$this->assertSame( 'buyer@example.com', $stamp['destination'] );
		$this->assertSame( $attempt, $stamp['attempt_id'] );
		$this->assertSame( get_current_user_id(), $stamp['cashier_id'] );
		$this->assertNotEmpty( $stamp['sent_at_gmt'] );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$this->assertSame( $response->get_data(), $this->submit( $order, $attempt, array() )->get_data() );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** A paid gateway yields exactly one app row with replay identity. */
	public function test_submit_paid_gateway_records_once_and_replays(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'recorded', $data['outcome'] );
		$this->assertSame( 'captured', $data['payment']['status'] );
		$this->assertSame( 'gateway', $data['payment']['capture_mode'] );
		$this->assertSame( 'app', $data['payment']['source'] );
		$this->assertSame( 'wcpos_paid_test', $data['payment']['method_id'] );
		$this->assertSame( $attempt, $data['payment']['provider_refs']['attempt_id'] );
		$this->assertSame( 'gateway-transaction', $data['payment']['provider_refs']['transaction_id'] );
		$this->assertSame( '0.00', $data['order']['balance'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		// Compared as JSON: the wire row's empty `receipt` is a fresh stdClass per response.
		$this->assertSame( wp_json_encode( $data ), wp_json_encode( $this->submit( $order, $attempt, array(), 'wcpos_paid_test' )->get_data() ) );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** Landing a paid status is also evidence of money. */
	public function test_submit_paid_status_without_payment_complete_records(): void {
		// Arrange.
		Paid_Test_Gateway::$status_only = true;
		$order = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** A live cash leg prevents a full-order gateway submission. */
	public function test_submit_live_cash_returns_conflict(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '10.00',
			)
		);
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $response->get_data()['code'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** Cancellation clears the stamp and opens the order without another send. */
	public function test_cancel_stamped_order_returns_pos_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		// Act.
		$response = $this->cancel( $order );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos-open', $response->get_data()['order']['status'] );
		$this->assertNull( Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** There is nothing to cancel on a fresh order. */
	public function test_cancel_without_stamp_returns_conflict(): void {
		// Arrange / Act.
		$response = $this->cancel( $this->create_pos_order() );
		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
	}

	/** Projection preserves a sent order until money arrives. */
	public function test_derive_stamped_pending_stays_pending_then_cash_clears_stamp(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$order = wc_get_order( $order->get_id() );
		// Act / Assert.
		Ledger::instance()->derive( $order, array() );
		$this->assertSame( 'pending', $order->get_status() );
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '10.00',
			)
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'pos-partial', $order->get_status() );
		$this->assertNull( Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
	}

	/** Provider errors leave no contract writes and clean the POST bridge. */
	public function test_submit_provider_errors_return_notice_without_writes(): void {
		foreach ( array( 'failure', 'throw', 'non-array' ) as $failure ) {
			// Arrange.
			Sent_Test_Gateway::$failure = $failure;
			$order = $this->create_pos_order();
			// Act.
			$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
			// Assert.
			$this->assertSame( 502, $response->get_status() );
			$this->assertSame( 'wcpos_provider_error', $response->get_data()['code'] );
			$this->assertSame( 'Provider declined', $response->get_data()['data']['detail'] );
			$this->assertSame( array(), wc_get_notices( 'error' ) );
			$this->assertArrayNotHasKey( 'invoice_email', $_POST );
			$stored = wc_get_order( $order->get_id() );
			$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
			$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		}
	}

	/** Bad inputs and unsupported modes never call a gateway. */
	public function test_submit_invalid_inputs_and_mode_are_refused(): void {
		// Arrange.
		$order = $this->create_pos_order();
		// Act / Assert.
		$this->assertSame( 400, $this->submit( $order, 'not-a-uuid', array() )->get_status() );
		$this->assertSame( 400, $this->submit( $order, wp_generate_uuid4(), 'bad' )->get_status() );
		$this->assertSame( 404, $this->submit( $order, wp_generate_uuid4(), array(), 'missing' )->get_status() );
		$this->assertSame( 501, $this->submit( $order, wp_generate_uuid4(), array(), 'pos_cash' )->get_status() );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** Paid orders refuse a new attempt. */
	public function test_submit_paid_order_returns_already_paid(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '92.95',
			)
		);
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array() );
		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $response->get_data()['code'] );
	}

	/** Terminal status edits remove a stale awaiting-customer stamp. */
	public function test_stamp_terminal_status_clears_meta(): void {
		foreach ( array( 'processing', 'completed', 'cancelled', 'refunded', 'trash' ) as $status ) {
			// Arrange.
			$order = $this->create_pos_order();
			$order->update_meta_data( '_wcpos_awaiting_customer', array( 'attempt_id' => wp_generate_uuid4() ) );
			$order->save();
			// Act.
			$order->update_status( $status );
			// Assert.
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_awaiting_customer' ) );
		}
	}

	/** The family permission applies to both new routes. */
	public function test_routes_without_permission_refuse_access(): void {
		// Arrange.
		$order = $this->create_pos_order();
		wp_set_current_user( 0 );
		// Act / Assert.
		$this->assertSame( 401, $this->submit( $order, wp_generate_uuid4(), array() )->get_status() );
		$this->assertSame( 401, $this->cancel( $order )->get_status() );
	}

	/** Create an open POS order. */
	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}

	/**
	 * Submit an attempt through the locked route.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $attempt Attempt UUID.
	 * @param mixed     $values Submitted fields.
	 * @param string    $method Gateway ID.
	 */
	private function submit( $order, $attempt, $values, $method = 'wcpos_sent_test' ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/submit' );
		$request->set_body_params(
			array(
				'attempt_id' => $attempt,
				'values' => $values,
			)
		);
		return $this->server->dispatch( $request );
	}

	/**
	 * Cancel through the locked route.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function cancel( $order ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/wcpos_sent_test/cancel' );
		$request->set_body_params( array( 'reason' => 'Customer changed mind' ) );
		return $this->server->dispatch( $request );
	}
}

/** A gateway that sends an invoice, not money. */
class Sent_Test_Gateway extends \WC_Payment_Gateway {
	/** @var int Number of submissions. */
	public static $calls = 0;
	/** @var array Observed POST bridge. */
	public static $posted = array();
	/** @var string Error behavior. */
	public static $failure = '';

	/** Register fixture identity. */
	public function __construct() {
		$this->id = 'wcpos_sent_test';
		$this->title = 'Invoice';
		$this->enabled = 'yes';
	}

	/** Validate using the existing gateway POST API. */
	public function validate_fields() {
		if ( empty( $_POST['invoice_email'] ) ) {
			wc_add_notice( 'Enter email', 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Leave the order awaiting payment.
	 *
	 * @param int $order_id Order ID.
	 * @return mixed
	 */
	public function process_payment( $order_id ) {
		++self::$calls;
		self::$posted = $_POST;
		if ( self::$failure ) {
			wc_add_notice( 'Provider declined', 'error' );
			if ( 'throw' === self::$failure ) {
				throw new \RuntimeException( 'Provider exception' );
			}
			return 'non-array' === self::$failure ? null : array( 'result' => 'failure' );
		}
		wc_get_order( $order_id )->update_status( 'pending', 'Invoice sent by gateway' );
		return array( 'result' => 'success' );
	}
}

/** A gateway that actually collects money. */
class Paid_Test_Gateway extends \WC_Payment_Gateway {
	/** @var int Number of submissions. */
	public static $calls = 0;
	/** @var bool Whether to use a paid status instead of payment_complete. */
	public static $status_only = false;

	/** Register fixture identity. */
	public function __construct() {
		$this->id = 'wcpos_paid_test';
		$this->title = 'Paid';
		$this->enabled = 'yes';
	}

	/**
	 * Take payment through WooCommerce.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		++self::$calls;
		$order = wc_get_order( $order_id );
		if ( self::$status_only ) {
			$order->update_status( 'processing' );
		} else {
			$order->payment_complete( 'gateway-transaction' );
		}
		return array( 'result' => 'success' );
	}
}
