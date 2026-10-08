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
	/** Install real test gateways, enabled for the POS, before registering routes. */
	public function setUp(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		add_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10, 2 );
		add_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10, 2 );
		$this->enable_for_pos( true );
		parent::setUp();
		\WC_Payment_Gateways::instance()->init();
		Sent_Test_Gateway::$calls       = 0;
		Sent_Test_Gateway::$posted      = array();
		Sent_Test_Gateway::$failure     = '';
		Sent_Test_Gateway::$land_status = 'pending';
		Sent_Test_Gateway::$redirect    = '';
		Paid_Test_Gateway::$calls       = 0;
		Paid_Test_Gateway::$status_only = false;
		Paid_Test_Gateway::$throw_after = false;
		wc_clear_notices();
	}

	/** Remove only our fixtures. */
	public function tearDown(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		remove_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10 );
		remove_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10 );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		remove_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
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
	 * @param string              $mode    Mode.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function mode( $mode, $gateway ): string {
		return in_array( $gateway->id, array( 'wcpos_sent_test', 'wcpos_paid_test' ), true ) ? 'gateway' : $mode;
	}

	/**
	 * Declare the invoice form.
	 *
	 * @param mixed               $fields  Fields.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 * @return array|null
	 */
	public function fields( $fields, $gateway ) {
		if ( 'wcpos_sent_test' !== $gateway->id ) {
			return $fields;
		}
		return array(
			'verb'       => array(
				'kind'  => 'send',
				'label' => 'Send invoice',
			),
			'components' => array(
				array(
					'component' => 'field',
					'id'        => 'invoice_email',
					'input'     => 'email',
					'label'     => 'Email address',
					'required'  => true,
					'default'   => '',
					'prefill'   => 'order.billing.email',
				),
				array(
					'component' => 'checkbox',
					'id'        => 'save_email',
					'label'     => 'Save email',
					'default'   => false,
					'prefill'   => null,
				),
			),
		);
	}

	/** Free enforces the declared schema before the gateway is called. */
	public function test_submit_empty_required_field_is_refused_before_the_gateway(): void {
		// Arrange.
		$order = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => '' ) );
		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_fields_invalid', $response->get_data()['code'] );
		$this->assertSame( 'This field is required.', $response->get_data()['data']['errors']['invoice_email'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_awaiting_customer' ) );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertArrayNotHasKey( 'invoice_email', $_POST );
	}

	/** A value of the wrong type is refused per component. */
	public function test_submit_wrong_value_type_is_refused(): void {
		// Arrange / Act.
		$response = $this->submit(
			$this->create_pos_order(),
			wp_generate_uuid4(),
			array(
				'invoice_email' => 'buyer@example.com',
				'save_email'    => 'yes',
			)
		);
		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'save_email' => 'This field must be true or false.' ), $response->get_data()['data']['errors'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** The gateway's own notices are keyed by their id data, or fall into _form. */
	public function test_submit_gateway_notices_are_keyed_by_id_or_form(): void {
		// Arrange.
		$order = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'bad@' ) );
		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array(
				'invoice_email' => 'Enter a valid email',
				'_form'         => array( 'Try again' ),
			),
			$response->get_data()['data']['errors']
		);
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
	}

	/** A silent false from validate_fields() still refuses, under _form. */
	public function test_submit_silent_validation_false_is_refused(): void {
		// Arrange / Act.
		$response = $this->submit( $this->create_pos_order(), wp_generate_uuid4(), array( 'invoice_email' => 'silent@example.com' ) );
		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_fields_invalid', $response->get_data()['code'] );
		$this->assertArrayHasKey( '_form', $response->get_data()['data']['errors'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** Bridged values are slashed like any WordPress request global, so wp_unslash() round-trips. */
	public function test_submit_slashes_bridged_values(): void {
		// Arrange / Act.
		$response = $this->submit( $this->create_pos_order(), wp_generate_uuid4(), array( 'invoice_email' => 'a\\b@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( wp_slash( 'a\\b@example.com' ), Sent_Test_Gateway::$posted['invoice_email'] );
		$this->assertSame( 'a\\b@example.com', wp_unslash( Sent_Test_Gateway::$posted['invoice_email'] ) );
	}

	/** A hosted checkout's redirect to another host is refused; the store's own pages are fine. */
	public function test_submit_redirect_elsewhere_is_refused_and_same_host_is_sent(): void {
		// Arrange.
		Sent_Test_Gateway::$redirect = 'https://pay.example-provider.test/session/abc';
		$order                       = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wcpos_provider_error', $response->get_data()['code'] );
		$this->assertStringContainsString( 'pay.example-provider.test', $response->get_data()['data']['detail'] );
		$this->assertNull( Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
		// Arrange / Act: a page on this site.
		Sent_Test_Gateway::$redirect = home_url( '/thank-you/' );
		$response                    = $this->submit( $this->create_pos_order(), wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
	}

	/** Sent attempts stamp the order, assign the gateway, and never send twice on replay. */
	public function test_submit_email_stamps_order_and_replay_does_not_send(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit(
			$order,
			$attempt,
			array(
				'invoice_email' => 'buyer@example.com',
				'save_email'    => true,
				'undeclared'    => 'drop',
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
		$this->assertSame( 'wcpos_sent_test', Sent_Test_Gateway::$posted_method );
		$this->assertArrayNotHasKey( 'invoice_email', $_POST );
		$this->assertArrayNotHasKey( 'save_email', $_POST );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'wcpos_sent_test', $stored->get_payment_method() );
		$this->assertSame( 'Invoice', $stored->get_payment_method_title() );
		$stamp = Gateway_Submission::read_stamp( $stored );
		$this->assertSame( 'wcpos_sent_test', $stamp['method_id'] );
		$this->assertSame( 'buyer@example.com', $stamp['destination'] );
		$this->assertSame( $attempt, $stamp['attempt_id'] );
		$this->assertSame( array( $attempt ), $stamp['attempts'] );
		$this->assertSame( get_current_user_id(), $stamp['cashier_id'] );
		$this->assertNotEmpty( $stamp['sent_at_gmt'] );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$this->assertSame( $response->get_data(), $this->submit( $order, $attempt, array() )->get_data() );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** Send again keeps every earlier attempt recognisable, so a late retry of the first never sends. */
	public function test_send_again_remembers_earlier_attempts(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$again = wp_generate_uuid4();
		$this->assertSame( 200, $this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		// Act.
		$response = $this->submit( $order, $again, array( 'invoice_email' => 'fixed@example.com' ) );
		$replay   = $this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$this->assertSame( 'sent', $replay->get_data()['outcome'] );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
		$stamp = Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) );
		$this->assertSame( $again, $stamp['attempt_id'] );
		$this->assertSame( 'fixed@example.com', $stamp['destination'] );
		$this->assertSame( array( $first, $again ), $stamp['attempts'] );
	}

	/** A paid gateway yields exactly one client-minted row, keyed by the attempt. */
	public function test_submit_paid_gateway_records_once_and_replays(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'recorded', $data['outcome'] );
		$this->assertSame( $attempt, $data['payment']['id'] );
		$this->assertSame( 'captured', $data['payment']['status'] );
		$this->assertSame( 'gateway', $data['payment']['capture_mode'] );
		$this->assertSame( 'app', $data['payment']['source'] );
		$this->assertSame( 'wcpos_paid_test', $data['payment']['method_id'] );
		$this->assertSame( '92.95', $data['payment']['amount'] );
		$this->assertSame( 'gateway-transaction', $data['payment']['provider_refs']['transaction_id'] );
		$this->assertArrayNotHasKey( 'attempt_id', $data['payment']['provider_refs'] );
		$this->assertSame( '0.00', $data['order']['balance'] );
		$this->assertContains( $data['order']['status'], wc_get_is_paid_statuses() );
		$this->assertSame( 'wcpos_paid_test', $data['order']['payment_method'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		// Compared as JSON: the wire row's empty `receipt` is a fresh stdClass per response.
		$this->assertSame( wp_json_encode( $data ), wp_json_encode( $this->submit( $order, $attempt, array(), 'wcpos_paid_test' )->get_data() ) );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** Landing a paid status is also evidence of money. */
	public function test_submit_paid_status_without_payment_complete_records(): void {
		// Arrange.
		Paid_Test_Gateway::$status_only = true;
		$order                          = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** Money the listener saw is recorded even when the gateway's return is broken. */
	public function test_submit_paid_then_throwing_gateway_still_records(): void {
		// Arrange.
		Paid_Test_Gateway::$throw_after = true;
		$order                          = $this->create_pos_order();
		$attempt                        = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertSame( $attempt, $response->get_data()['payment']['id'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** A live cash leg prevents a full-order gateway submission. */
	public function test_submit_live_cash_returns_conflict(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '10.00',
			)
		);
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $response->get_data()['code'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** Cancellation names its attempt, clears the stamp and opens the order without another send. */
	public function test_cancel_stamped_order_returns_pos_open(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 200, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		// Act.
		$response = $this->cancel( $order, $attempt );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos-open', $response->get_data()['order']['status'] );
		$this->assertNull( Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** A stale, mismatched or unnamed cancel never undoes a newer send. */
	public function test_cancel_refusals(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$again = wp_generate_uuid4();
		$this->assertSame( 409, $this->cancel( $order, $first )->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $this->cancel( $order, $first )->get_data()['code'] );
		$this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->submit( $order, $again, array( 'invoice_email' => 'fixed@example.com' ) );
		// Act / Assert.
		$stale = $this->cancel( $order, $first );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $stale->get_data()['code'] );
		$wrong_method = $this->cancel( $order, $again, 'pos_cash' );
		$this->assertSame( 409, $wrong_method->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $wrong_method->get_data()['code'] );
		$this->assertSame( 400, $this->cancel( $order, null )->get_status() );
		$this->assertSame( $again, Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) )['attempt_id'] );
		$this->assertSame( 200, $this->cancel( $order, $again )->get_status() );
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
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '10.00',
			)
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'pos-partial', $order->get_status() );
		$this->assertNull( Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
	}

	/** A sent order the gateway left on-hold re-enters the projection when money lands. */
	public function test_on_hold_sent_order_projects_when_paid_at_the_till(): void {
		// Arrange.
		Sent_Test_Gateway::$land_status = 'on-hold';
		$order                          = $this->create_pos_order();
		$this->assertSame( 'sent', $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['outcome'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		// Act.
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '10.00',
			)
		);
		// Assert.
		$this->assertSame( 'pos-partial', $order->get_status() );
		$this->assertNull( Gateway_Submission::read_stamp( $order ) );
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '82.95',
			)
		);
		$this->assertContains( wc_get_order( $order->get_id() )->get_status(), wc_get_is_paid_statuses() );
	}

	/** Provider errors with no money leave no contract writes and clean the POST bridge. */
	public function test_submit_provider_errors_return_notice_without_writes(): void {
		foreach ( array( 'failure', 'throw', 'non-array' ) as $failure ) {
			// Arrange.
			Sent_Test_Gateway::$failure = $failure;
			$order                      = $this->create_pos_order();
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

	/** Bad inputs, unsupported modes, non-POS orders and disabled methods never call a gateway. */
	public function test_submit_invalid_inputs_and_mode_are_refused(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$shop  = OrderHelper::create_order();
		// Act / Assert.
		$this->assertSame( 400, $this->submit( $order, 'not-a-uuid', array() )->get_status() );
		$this->assertSame( 400, $this->submit( $order, wp_generate_uuid4(), 'bad' )->get_status() );
		$this->assertSame( 404, $this->submit( $order, wp_generate_uuid4(), array(), 'missing' )->get_status() );
		$this->assertSame( 501, $this->submit( $order, wp_generate_uuid4(), array(), 'pos_cash' )->get_status() );
		$non_pos = $this->submit( $shop, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( 409, $non_pos->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $non_pos->get_data()['code'] );
		$this->enable_for_pos( false );
		$disabled = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( 403, $disabled->get_status() );
		$this->assertSame( 'wcpos_payment_method_disabled', $disabled->get_data()['code'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}

	/** Paid orders refuse a new attempt. */
	public function test_submit_paid_order_returns_already_paid(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '92.95',
			)
		);
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array() );
		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $response->get_data()['code'] );
	}

	/** The route works on a request with no WooCommerce session, as every REST request is. */
	public function test_submit_without_a_woocommerce_session(): void {
		// Arrange.
		$order         = $this->create_pos_order();
		$session       = WC()->session;
		WC()->session  = null;
		// Act.
		try {
			$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
			$refused  = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'bad@' ) );
		} finally {
			WC()->session = $session;
		}
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'Enter a valid email', $refused->get_data()['data']['errors']['invoice_email'] );
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
		$this->assertSame( 401, $this->cancel( $order, wp_generate_uuid4() )->get_status() );
	}

	/**
	 * Enable (or disable) the fixtures for the POS through the settings option the builder reads.
	 *
	 * @param bool $enabled Whether the fixtures are POS-enabled.
	 */
	private function enable_for_pos( bool $enabled ): void {
		self::$fixtures_enabled = $enabled;
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash'        => array( 'enabled' => true ),
					'wcpos_sent_test' => array( 'enabled' => $enabled ),
					'wcpos_paid_test' => array( 'enabled' => $enabled ),
				),
			)
		);
		// Free's settings filter (API\V1\Settings::payment_gateways_settings) switches every
		// gateway but Cash and Card off; Pro lifts that. Stand in for Pro after it has run.
		remove_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
		add_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
	}

	/**
	 * Whether the fixtures are currently POS-enabled.
	 *
	 * @var bool
	 */
	private static $fixtures_enabled = true;

	/**
	 * Re-enable the fixtures after Free's own filter has disabled them.
	 *
	 * @param mixed $options The gateway options.
	 */
	public function keep_fixtures_enabled( $options ) {
		foreach ( array( 'wcpos_sent_test', 'wcpos_paid_test' ) as $id ) {
			if ( isset( $options['gateways'][ $id ] ) ) {
				$options['gateways'][ $id ]['enabled'] = self::$fixtures_enabled;
			}
		}
		return $options;
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
	 * @param \WC_Order $order   Order.
	 * @param string    $attempt Attempt UUID.
	 * @param mixed     $values  Submitted fields.
	 * @param string    $method  Gateway ID.
	 */
	private function submit( $order, $attempt, $values, $method = 'wcpos_sent_test' ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/submit' );
		$request->set_body_params(
			array(
				'attempt_id' => $attempt,
				'values'     => $values,
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
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}
}

/** A gateway that sends an invoice, not money. */
class Sent_Test_Gateway extends \WC_Payment_Gateway {
	/**
	 * Number of submissions.
	 *
	 * @var int
	 */
	public static $calls = 0;
	/**
	 * Observed POST bridge.
	 *
	 * @var array
	 */
	public static $posted = array();
	/**
	 * The order's payment method as process_payment() saw it.
	 *
	 * @var string
	 */
	public static $posted_method = '';
	/**
	 * Error behavior.
	 *
	 * @var string
	 */
	public static $failure = '';
	/**
	 * The status process_payment() leaves the order in.
	 *
	 * @var string
	 */
	public static $land_status = 'pending';
	/**
	 * The redirect process_payment() answers; empty means the order-received URL.
	 *
	 * @var string
	 */
	public static $redirect = '';

	/** Register fixture identity. */
	public function __construct() {
		$this->id      = 'wcpos_sent_test';
		$this->title   = 'Invoice';
		$this->enabled = 'yes';
	}

	/** Validate using the existing gateway POST API, with an id-keyed and an unkeyed notice. */
	public function validate_fields() {
		if ( empty( $_POST['invoice_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wc_add_notice( 'Enter email', 'error' );
			return false;
		}
		if ( 'bad@' === $_POST['invoice_email'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wc_add_notice( 'Enter a valid email', 'error', array( 'id' => 'invoice_email' ) );
			wc_add_notice( 'Try again', 'error' );
			return false;
		}
		if ( 'silent@example.com' === $_POST['invoice_email'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
		self::$posted        = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::$posted_method = wc_get_order( $order_id )->get_payment_method();
		if ( self::$failure ) {
			wc_add_notice( 'Provider declined', 'error' );
			if ( 'throw' === self::$failure ) {
				throw new \RuntimeException( 'Provider exception' );
			}
			return 'non-array' === self::$failure ? null : array( 'result' => 'failure' );
		}
		$order = wc_get_order( $order_id );
		$order->update_status( self::$land_status, 'Invoice sent by gateway' );
		return array(
			'result'   => 'success',
			'redirect' => '' !== self::$redirect ? self::$redirect : $order->get_checkout_order_received_url(),
		);
	}
}

/** A gateway that actually collects money. */
class Paid_Test_Gateway extends \WC_Payment_Gateway {
	/**
	 * Number of submissions.
	 *
	 * @var int
	 */
	public static $calls = 0;
	/**
	 * Whether to use a paid status instead of payment_complete.
	 *
	 * @var bool
	 */
	public static $status_only = false;
	/**
	 * Whether to throw after taking the money.
	 *
	 * @var bool
	 */
	public static $throw_after = false;

	/** Register fixture identity. */
	public function __construct() {
		$this->id      = 'wcpos_paid_test';
		$this->title   = 'Paid';
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
		if ( self::$throw_after ) {
			throw new \RuntimeException( 'Broken after paying' );
		}
		return array( 'result' => 'success' );
	}
}
