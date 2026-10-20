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
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/** Submission reads money from the gateway, not from the verb. */
class Test_Gateway_Submission extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

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
		Sent_Test_Gateway::$empty_cart  = false;
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
		$order->set_payment_method( 'pos_cash' );
		$order->save();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wcpos_provider_error', $response->get_data()['code'] );
		$this->assertStringContainsString( 'pay.example-provider.test', $response->get_data()['data']['detail'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame( 'pos_cash', $stored->get_payment_method() );
		// Arrange / Act: a page on this site.
		Sent_Test_Gateway::$redirect = home_url( '/thank-you/' );
		$response                    = $this->submit( $this->create_pos_order(), wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		// Arrange / Act: WordPress served from its own host, the gateway built its URL from it.
		$site_url                    = static fn() => 'https://wp.example-store.test';
		Sent_Test_Gateway::$redirect = 'https://wp.example-store.test/checkout/order-received/1/';
		add_filter( 'site_url', $site_url );
		try {
			$response = $this->submit( $this->create_pos_order(), wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		} finally {
			remove_filter( 'site_url', $site_url );
		}
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
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
		$this->assertSame( array( $attempt => 'sent' ), Gateway_Submission::read_attempts( $stored ) );
		$this->assertSame( get_current_user_id(), $stamp['cashier_id'] );
		$this->assertNotEmpty( $stamp['sent_at_gmt'] );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$this->assertSame( $this->without_revision( $response ), $this->without_revision( $this->submit( $order, $attempt, array() ) ) );
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
		$this->assertSame( 409, $replay->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $replay->get_data()['code'] );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
		$stored = wc_get_order( $order->get_id() );
		$stamp  = Gateway_Submission::read_stamp( $stored );
		$this->assertSame( $again, $stamp['attempt_id'] );
		$this->assertSame( 'fixed@example.com', $stamp['destination'] );
		$this->assertSame(
			array(
				$first => 'superseded',
				$again => 'sent',
			),
			Gateway_Submission::read_attempts( $stored )
		);
	}

	/** A gateway that empties the cart, as WooCommerce's own BACS, cheque and COD do, works on a REST request. */
	public function test_submit_gateway_that_empties_the_cart_without_a_cart(): void {
		// Arrange.
		Sent_Test_Gateway::$empty_cart = true;
		$order                         = $this->create_pos_order();
		$cart                          = WC()->cart;
		$customer                      = WC()->customer;
		WC()->cart                     = null;
		WC()->customer                 = null;
		// Act.
		try {
			$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		} finally {
			WC()->cart     = $cart;
			WC()->customer = $customer;
		}
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** A refused attempt leaves the order's payment method as it was. */
	public function test_submit_failure_restores_the_previous_payment_method(): void {
		// Arrange.
		Sent_Test_Gateway::$failure = 'failure';
		$order                      = $this->create_pos_order();
		$order->set_payment_method( 'pos_cash' );
		$order->set_payment_method_title( 'Cash' );
		$order->save();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 502, $response->get_status() );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos_cash', $stored->get_payment_method() );
		$this->assertSame( 'Cash', $stored->get_payment_method_title() );
		$this->assertSame( 'wcpos_sent_test', Sent_Test_Gateway::$posted_method );
	}

	/** @dataProvider paid_replay_rows */
	public function test_paid_attempt_replay_never_dispatches_gateway( bool $row ): void {
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		if ( $row ) { Ledger::instance()->save( $order, array( array( 'id' => $attempt, 'method_id' => 'wcpos_sent_test', 'amount' => '92.95', 'status' => 'failed' ) ) ); }
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, array( $attempt => 'paid' ) );
		$order->save();
		$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'paid', $response->get_data()['outcome'] );
		$this->assertSame( Ledger::instance()->summary( wc_get_order( $order->get_id() ) ), $response->get_data()['order'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
	}
	public function paid_replay_rows(): array {
		return array( 'no row' => array( false ), 'with row' => array( true ) );
	}

	/** Replacing then cancelling an invoice leaves no unresolved historical send. */
	public function test_superseded_send_is_terminal(): void {
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$again = wp_generate_uuid4();
		$logs = array();
		$log = static function ( $enabled, $message ) use ( &$logs ) { $logs[] = $message; return false; };
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );
		try {
			$this->assertSame( 200, $this->submit( $order, $first, array( 'invoice_email' => 'first@example.com' ) )->get_status() );
			$this->assertSame( 200, $this->submit( $order, $again, array( 'invoice_email' => 'second@example.com' ) )->get_status() );
		} finally { remove_filter( 'woocommerce_pos_logging', $log, 10 ); }
		$this->assertSame( 200, $this->cancel( $order, $again )->get_status() );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( 'superseded', Gateway_Submission::read_attempts( $saved )[ $first ] );
		$this->assertNotEmpty( array_filter( $logs, static fn( $message ) => is_string( $message ) && false !== strpos( $message, $first ) && false !== strpos( $message, 'cancel' ) ) );
		$replay = $this->submit( $order, $first, array( 'invoice_email' => 'first@example.com' ) );
		$this->assertSame( 409, $replay->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $replay->get_data()['code'] );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
	}

	public function test_settled_submission_supersedes_prior_send(): void {
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-pending' ) );
		$this->assertSame( 'settled', $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['outcome'] );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( 'superseded', Gateway_Submission::read_attempts( $saved )[ $first ] );
	}

	/** @dataProvider dispatch_throw_phases */
	public function test_submission_throw_tracks_whether_dispatch_started( bool $before_dispatch ): void {
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$throw = static function ( $saving ) use ( $order, $attempt, &$throw ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order->get_id() && 'sent' === ( Gateway_Submission::read_attempts( $saving )[ $attempt ] ?? null ) ) {
				remove_action( 'woocommerce_after_order_object_save', $throw );
				throw new \Error( 'After-save failure before dispatch' );
			}
		};
		if ( $before_dispatch ) {
			add_action( 'woocommerce_after_order_object_save', $throw );
		} else {
			Sent_Test_Gateway::$failure = 'throw';
		}
		try {
			$this->assertSame( 502, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		} finally { remove_action( 'woocommerce_after_order_object_save', $throw ); }
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( $before_dispatch ? 'cancelled' : 'sent', Gateway_Submission::read_attempts( $saved )[ $attempt ] );
		$replay = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( $before_dispatch ? 409 : 200, $replay->get_status() );
		$this->assertSame( $before_dispatch ? 0 : 1, Sent_Test_Gateway::$calls );
	}

	public function dispatch_throw_phases(): array {
		return array( 'before dispatch' => array( true ), 'after dispatch' => array( false ) );
	}

	public function test_capture_persistence_failure_replay_does_not_charge_again(): void {
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$refuse = static function ( $saving ) use ( $order ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order->get_id() && ( $saving->get_date_paid() || Ledger::instance()->read( $saving ) ) ) {
				throw new \RuntimeException( 'Simulated ledger persistence failure' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX );
		try {
			$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		} finally { remove_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX ); }
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$replay = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
		$this->assertSame( 'sent', $replay->get_data()['outcome'] );
		$this->assertSame( 'sent', Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) )[ $attempt ] );
	}

	public function test_clean_provider_failure_leaves_terminal_attempt(): void {
		$order = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Sent_Test_Gateway::$failure = 'failure';
		$this->assertSame( 502, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$this->assertSame( 'cancelled', Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) )[ $attempt ] ?? null );
		$this->assertSame( 409, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** A mint refused after the gateway took the money is loud, and a retry never dispatches again. */
	public function test_paid_mint_failure_is_loud_and_retry_does_not_redispatch(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$deny    = static function ( $saving ) use ( $order ): void {
			if ( $saving->get_id() === $order->get_id() && Ledger::instance()->read( $saving ) ) {
				throw new \RuntimeException( 'Mint unavailable' );
			}
		};
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		add_action( 'woocommerce_before_order_object_save', $deny, PHP_INT_MAX );
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );

		// Act.
		try {
			$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $deny, PHP_INT_MAX );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
		}
		$retry = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame(
			'sent',
			Gateway_Submission::read_attempts( $saved )[ $attempt ] ?? null
		);
		$this->assertSame( array(), Ledger::instance()->read( $saved ) );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'WCPOS: payment gateway-transaction (92.95 ', $notes );
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'gateway-transaction (92.95 ' ) ) );
		$this->assertSame( 'sent', $retry->get_data()['outcome'] );
		$this->assertArrayHasKey( 'payment', $retry->get_data() );
		$this->assertNull( $retry->get_data()['payment'] );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** An Error from a save hook while the paid row is adopted answers 409, loudly, never 500. */
	public function test_paid_mint_save_hook_error_is_loud_conflict(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$deny    = static function ( $saving ) use ( $order ): void {
			if ( $saving->get_id() !== $order->get_id() ) {
				return;
			}
			$property = new \ReflectionProperty( \WC_Data::class, 'meta_data' );
			$property->setAccessible( true );
			foreach ( $property->getValue( $saving ) ?? array() as $meta ) {
				if ( Ledger::META_KEY === $meta->key && null !== $meta->value && ( ! $meta->id || $meta->get_changes() ) ) {
					throw new \Error( 'simulated' );
				}
			}
		};
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		add_action( 'woocommerce_before_order_object_save', $deny, PHP_INT_MAX );
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );

		// Act.
		try {
			$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $deny, PHP_INT_MAX );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
		}

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( 'sent', Gateway_Submission::read_attempts( $saved )[ $attempt ] ?? null );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'WCPOS: payment gateway-transaction (92.95 ', $notes );
		$this->assertNotEmpty( array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'gateway-transaction (92.95 ' ) && false !== strpos( $message, 'simulated' ) ) );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** An attempt that cannot be stored before dispatch answers a retryable 409 and never reaches the gateway. */
	public function test_unpersisted_attempt_is_a_conflict_before_dispatch(): void {
		// Arrange: WooCommerce swallows this refusal, so the attempt never reaches storage.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$refuse  = static function ( $saving ) use ( $order, $attempt ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order->get_id() && 'sent' === ( Gateway_Submission::read_attempts( $saving )[ $attempt ] ?? null ) ) {
				throw new \RuntimeException( 'Simulated refused attempt write' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX );

		// Act.
		try {
			$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX );
		}

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
		$this->assertArrayNotHasKey( $attempt, Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) ) );
	}

	/** A failure building the paid row after the gateway took the money answers 409, loudly, and never redispatches. */
	public function test_paid_row_build_error_is_loud_conflict(): void {
		// Arrange: the row-amount total read in mint() fails for this order.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$reads   = 0;
		$crash   = static function ( $total, $paying ) use ( $order, &$reads ) {
			if ( $paying->get_id() !== $order->get_id() ) {
				return $total;
			}
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'mint' === ( $frame['function'] ?? '' ) && 2 === ++$reads ) {
					throw new \RuntimeException( 'total unavailable' );
				}
			}
			return $total;
		};
		add_filter( 'woocommerce_order_get_total', $crash, 10, 2 );

		// Act.
		try {
			$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		} finally {
			remove_filter( 'woocommerce_order_get_total', $crash, 10 );
		}

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( 'sent', Gateway_Submission::read_attempts( $saved )[ $attempt ] ?? null );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'WCPOS: payment gateway-transaction', $notes );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** Only the paying attempt becomes paid; an earlier invoice it replaced is superseded. */
	public function test_paying_submission_supersedes_an_earlier_sent_invoice(): void {
		// Arrange: attempt A is sent as an invoice.
		$order = $this->create_pos_order();
		$sent  = wp_generate_uuid4();
		$pays  = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $sent, array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['outcome'] );

		// Act: attempt B pays through the paying gateway.
		$this->assertSame( 'recorded', $this->submit( wc_get_order( $order->get_id() ), $pays, array(), 'wcpos_paid_test' )->get_data()['outcome'] );

		// Assert.
		$history = Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'superseded', $history[ $sent ] ?? null );
		$this->assertSame( 'paid', $history[ $pays ] ?? null );
		$replay_sent = $this->submit( wc_get_order( $order->get_id() ), $sent, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( 409, $replay_sent->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $replay_sent->get_data()['code'] );
		$this->assertSame( 'paid', $this->submit( wc_get_order( $order->get_id() ), $pays, array(), 'wcpos_paid_test' )->get_data()['outcome'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
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
		$this->assertSame( array( 'outcome' => 'paid', 'payment' => null, 'order' => $data['order'] ), $this->without_revision( $this->submit( $order, $attempt, array(), 'wcpos_paid_test' ) ) );
		$this->assertSame( 1, Paid_Test_Gateway::$calls );
	}

	/** A completed sale owes money after a refund, yet a new attempt is refused, not dispatched (§4.2). */
	public function test_submit_on_a_completed_sale_is_refused(): void {
		// Arrange: paid in cash, 20.00 refunded and allocated.
		$order = $this->create_pos_order();
		$cash  = Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '92.95',
			)
		);
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '20.00',
				'refund_payment' => false,
			)
		);
		$this->assertIsArray( Ledger::instance()->refund( wc_get_order( $order->get_id() ), $cash['id'], $refund->get_id(), '20.00' ) );
		$this->assertSame( '20.00', Ledger::instance()->summary( wc_get_order( $order->get_id() ) )['balance'] );
		$attempt = wp_generate_uuid4();

		// Act.
		$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'customer@example.com' ) );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $response->get_data()['code'] );
		$this->assertSame( 0, Sent_Test_Gateway::$calls );
		$this->assertArrayNotHasKey( $attempt, Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) ) );
	}

	/** An invoice sent from the till and later marked paid in wp-admin records the attempt as the payment. */
	public function test_a_sent_invoice_marked_paid_without_payment_complete_records_the_attempt(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $attempt, array( 'invoice_email' => 'customer@example.com' ) )->get_data()['outcome'] );
		\WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp::reset();

		// Act: a later request lands the paid status without payment_complete().
		wc_get_order( $order->get_id() )->update_status( 'processing' );

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$rows   = Ledger::instance()->read( $stored );
		$this->assertSame( array( $attempt ), array_column( $rows, 'id' ) );
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( array( $attempt => 'paid' ), Gateway_Submission::read_attempts( $stored ) );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
	}

	/** A sent invoice paid by a provider callback that calls payment_complete() records the attempt as its row. */
	public function test_a_sent_invoice_paid_by_a_payment_complete_callback_is_marked_paid(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $attempt, array( 'invoice_email' => 'customer@example.com' ) )->get_data()['outcome'] );
		\WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp::reset();

		// Act: the provider's callback in a later request.
		wc_get_order( $order->get_id() )->payment_complete( 'txn_callback' );

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$rows   = Ledger::instance()->read( $stored );
		$this->assertSame( array( $attempt ), array_column( $rows, 'id' ) );
		$this->assertSame( 'webview', $rows[0]['source'] );
		$this->assertSame( array( $attempt => 'paid' ), Gateway_Submission::read_attempts( $stored ) );
		$replay = $this->submit( $stored, $attempt, array( 'invoice_email' => 'customer@example.com' ) );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( 'paid', $replay->get_data()['outcome'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
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
		// A late retry of the cancelled send never sends again, and the earlier send is still remembered.
		$retry = $this->submit( $order, $again, array( 'invoice_email' => 'fixed@example.com' ) );
		$this->assertSame( 409, $retry->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $retry->get_data()['code'] );
		$this->assertSame( 'wcpos_payment_conflict', $this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['code'] );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
		$this->assertSame(
			array(
				$first => 'superseded',
				$again => 'cancelled',
			),
			Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) )
		);
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

	/** A lost response is replayed even after the merchant switched the method off; only a new attempt is refused. */
	public function test_replay_survives_the_method_being_disabled(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['outcome'] );
		$this->enable_for_pos( false );
		// Act / Assert.
		$replay = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( 'sent', $replay->get_data()['outcome'] );
		$this->assertSame( 403, $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
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

	/** The merchant's stored POS Order Status, reached by the gateway, settles the sale at the till. */
	public function test_submit_stored_status_reached_is_settled_and_replays(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-pending' ) );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'settled', $response->get_data()['outcome'] );
		$this->assertNull( $response->get_data()['payment'] );
		$this->assertSame( 'pending', $response->get_data()['order']['status'] );
		$this->assertSame( 'wcpos_sent_test', $response->get_data()['order']['payment_method'] );
		// Nobody paid: the balance is still due and WooCommerce did not stamp date_paid on the way
		// onto the stored status (Orders::payment_complete_order_status() answers it).
		$this->assertSame( '92.95', $response->get_data()['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame( array( $attempt => 'settled' ), Gateway_Submission::read_attempts( $stored ) );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertContains( 'Settled via POS: Send invoice on Pending payment', $notes );
		// A replay answers settled again without calling the gateway; nothing awaits cancelling.
		$this->assertSame( $this->without_revision( $response ), $this->without_revision( $this->submit( $order, $attempt, array() ) ) );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
		$this->assertSame( 409, $this->cancel( $order, $attempt )->get_status() );
	}

	/** A stored status the gateway did not reach is the ordinary send-and-leave. */
	public function test_submit_stored_status_not_reached_is_sent(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-completed' ) );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $stored->get_status() );
		$this->assertSame( $attempt, Gateway_Submission::read_stamp( $stored )['attempt_id'] );
		$this->assertSame( array( $attempt => 'sent' ), Gateway_Submission::read_attempts( $stored ) );
	}

	/** A stored status the reader never honours (failed) cannot settle, even when the gateway lands on it. */
	public function test_submit_stored_never_settled_status_is_not_settled(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-failed' ) );
		Sent_Test_Gateway::$land_status = 'failed';
		$order                          = $this->create_pos_order();
		$attempt                        = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $stored->get_status() );
		$this->assertSame( $attempt, Gateway_Submission::read_stamp( $stored )['attempt_id'] );
		$this->assertSame( array( $attempt => 'sent' ), Gateway_Submission::read_attempts( $stored ) );
	}

	/** A purchase-order style gateway stored as On hold settles when it lands there. */
	public function test_submit_stored_on_hold_reached_is_settled(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-on-hold' ) );
		Sent_Test_Gateway::$land_status = 'on-hold';
		$order                          = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 'settled', $response->get_data()['outcome'] );
		$this->assertSame( 'on-hold', $response->get_data()['order']['status'] );
		$this->assertSame( '92.95', $response->get_data()['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame( array( 'settled' ), array_values( Gateway_Submission::read_attempts( $stored ) ) );
	}

	/** Money observed wins over a stored status the gateway also reached. */
	public function test_submit_money_observed_wins_over_stored_status(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_paid_test' => 'wc-processing' ) );
		Paid_Test_Gateway::$status_only = true;
		$order                          = $this->create_pos_order();
		$attempt                        = wp_generate_uuid4();
		// Act.
		$response = $this->submit( $order, $attempt, array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertSame( $attempt, $response->get_data()['payment']['id'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $stored->get_status() );
		$this->assertCount( 1, Ledger::instance()->read( $stored ) );
		$this->assertSame( array( $attempt => 'paid' ), Gateway_Submission::read_attempts( $stored ) );
	}

	/** The date_paid suppression lets observed money through: payment_complete() still stamps the order paid. */
	public function test_submit_payment_complete_with_stored_unpaid_status_still_stamps_date_paid(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_paid_test' => 'wc-pending' ) );
		$order = $this->create_pos_order();
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertNotNull( $stored->get_date_paid( 'edit' ) );
		$this->assertCount( 1, Ledger::instance()->read( $stored ) );
	}

	/** An order already on its stored status keeps it when real money lands: the suppression never answers payment_complete(). */
	public function test_submit_payment_complete_on_order_already_at_stored_status_keeps_it(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_paid_test' => 'wc-on-hold' ) );
		$order = $this->park_unpaid( $this->create_pos_order(), 'on-hold' );
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array(), 'wcpos_paid_test' );
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$this->assertSame( 'on-hold', $response->get_data()['order']['status'] );
		$this->assertSame( '0.00', $response->get_data()['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $stored->get_status() );
		$this->assertNotNull( $stored->get_date_paid( 'edit' ) );
		$this->assertCount( 1, Ledger::instance()->read( $stored ) );
	}

	/** An order already at its stored status settles with the balance still due and no date_paid. */
	public function test_submit_settled_on_order_already_at_stored_status_leaves_date_paid_unset(): void {
		// Arrange.
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-on-hold' ) );
		Sent_Test_Gateway::$land_status = 'on-hold';
		$order                          = $this->park_unpaid( $this->create_pos_order(), 'on-hold' );
		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'settled', $response->get_data()['outcome'] );
		$this->assertSame( '92.95', $response->get_data()['order']['balance'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
	}

	/** Send again after the merchant stores the status: the settled attempt clears the earlier send's stamp. */
	public function test_submit_settled_clears_a_stale_stamp_from_an_earlier_send(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$again = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $first, array( 'invoice_email' => 'buyer@example.com' ) )->get_data()['outcome'] );
		$this->assertSame( $first, Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) )['attempt_id'] );
		$this->enable_for_pos( true, array( 'wcpos_sent_test' => 'wc-pending' ) );
		// Act.
		$response = $this->submit( $order, $again, array( 'invoice_email' => 'fixed@example.com' ) );
		// Assert.
		$this->assertSame( 'settled', $response->get_data()['outcome'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame(
			array(
				$first => 'superseded',
				$again => 'settled',
			),
			Gateway_Submission::read_attempts( $stored )
		);
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
		// The replaced invoice is terminal, never dispatched again.
		$this->assertSame( 'wcpos_payment_conflict', $this->submit( $order, $first, array() )->get_data()['code'] );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
	}

	/**
	 * Enable (or disable) the fixtures for the POS through the settings option the builder reads.
	 *
	 * @param bool                  $enabled        Whether the fixtures are POS-enabled.
	 * @param array<string, string> $order_statuses Stored POS Order Status per fixture id, with the `wc-` prefix.
	 */
	private function enable_for_pos( bool $enabled, array $order_statuses = array() ): void {
		self::$fixtures_enabled = $enabled;
		$gateways               = array( 'pos_cash' => array( 'enabled' => true ) );
		foreach ( array( 'wcpos_sent_test', 'wcpos_paid_test' ) as $id ) {
			$gateways[ $id ] = array( 'enabled' => $enabled );
			if ( isset( $order_statuses[ $id ] ) ) {
				$gateways[ $id ]['order_status'] = $order_statuses[ $id ];
			}
		}
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => $gateways,
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

	/**
	 * Park an order on an unpaid status with date_paid still empty.
	 *
	 * WooCommerce runs maybe_set_date_paid() from set_status() on a real status change; outside
	 * a submit nothing on `next` suppresses it, and the settings view answers bacs (the helper's
	 * method) with On hold, so a plain set_status( 'on-hold' ) would stamp date_paid on an
	 * unpaid order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $status Status without the `wc-` prefix.
	 */
	private function park_unpaid( \WC_Order $order, string $status ): \WC_Order {
		add_filter( 'woocommerce_payment_complete_order_status', '__return_empty_string', PHP_INT_MAX );
		try {
			$order->set_status( $status );
			$order->save();
		} finally {
			remove_filter( 'woocommerce_payment_complete_order_status', '__return_empty_string', PHP_INT_MAX );
		}
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertNull( $order->get_date_paid( 'edit' ) );
		return $order;
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

	/**
	 * An answer without its revision: a replay under a new command id keeps an entry, which moves it.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private function without_revision( \WP_REST_Response $response ): array {
		$data = $response->get_data();
		unset( $data['revision'] );
		return $data;
	}

	/**
	 * POST a gateway command body as given (its own command id and revision).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $verb   submit or cancel.
	 * @param array     $body   Body.
	 * @param string    $method Gateway ID.
	 */
	private function gateway_command( \WC_Order $order, string $verb, array $body, string $method = 'wcpos_sent_test' ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/' . $verb );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * The order's command map as storage holds it.
	 *
	 * @param int $order_id Order id.
	 */
	private function stored_commands( int $order_id ): array {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( '_wcpos_commands' === $meta->meta_key ) {
				return (array) json_decode( (string) maybe_unserialize( $meta->meta_value ), true );
			}
		}
		return array();
	}

	/** Pin 8: a submit that answered `sent` replays from the command map, even once the attempt history lost it. */
	public function test_submit_that_returned_sent_replays_sent_from_the_command_map_with_a_stale_revision(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$body  = $this->envelope(
			$order,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);

		// Act.
		$first = $this->gateway_command( $order, 'submit', $body );
		$again = $this->gateway_command( $order, 'submit', $body );
		$evict = wc_get_order( $order->get_id() );
		$evict->delete_meta_data( Gateway_Submission::ATTEMPTS_META_KEY );
		$evict->save();
		$after = $this->gateway_command( $order, 'submit', $body );

		// Assert.
		$this->assertSame( 'sent', $first->get_data()['outcome'] );
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( 'sent', $again->get_data()['outcome'] );
		$this->assertSame( 200, $after->get_status(), wp_json_encode( $after->get_data() ) );
		$this->assertSame( 'sent', $after->get_data()['outcome'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
		$notes = array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ) => 0 === strpos( $note->content, 'Sent via POS' ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'sent', $this->stored_commands( $order->get_id() )[ $body['command_id'] ]['outcome'] );
	}

	/** Pin 9: the same submit command id with other values is a 422; nothing is sent or stamped again. */
	public function test_submit_replay_with_a_different_body_is_422(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$body  = $this->envelope(
			$order,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);
		$this->assertSame( 200, $this->gateway_command( $order, 'submit', $body )->get_status() );
		$stamp = Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) );

		// Act.
		$response = $this->gateway_command( $order, 'submit', array_merge( $body, array( 'values' => array( 'invoice_email' => 'other@example.com' ) ) ) );

		// Assert.
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_command_conflict', $response->get_data()['code'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
		$this->assertSame( $stamp, Gateway_Submission::read_stamp( wc_get_order( $order->get_id() ) ) );
	}

	/** Pin 10: a definite gateway failure keeps no command; the attempt stays terminal; a new attempt dispatches. */
	public function test_a_definite_gateway_failure_stores_no_command_and_leaves_the_attempt_terminal(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$body    = $this->envelope(
			$order,
			array(
				'attempt_id' => $attempt,
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);
		Sent_Test_Gateway::$failure = 'failure';

		// Act.
		$failed = $this->gateway_command( $order, 'submit', $body );
		Sent_Test_Gateway::$failure = '';
		$retried = $this->gateway_command( $order, 'submit', array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );
		$another = $this->submit( $order, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) );

		// Assert.
		$this->assertSame( 502, $failed->get_status() );
		$this->assertSame( 'wcpos_provider_error', $failed->get_data()['code'] );
		$this->assertArrayNotHasKey( $body['command_id'], $this->stored_commands( $order->get_id() ) );
		$this->assertSame( 'cancelled', Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) )[ $attempt ] );
		$this->assertSame( 409, $retried->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $retried->get_data()['code'] );
		$this->assertSame( 200, $another->get_status() );
		$this->assertSame( 2, Sent_Test_Gateway::$calls );
	}

	/** Pin 11: a cancel retried with its command replays from the command map on the revision before it. */
	public function test_cancel_replays_from_the_command_map(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 200, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$body = $this->envelope(
			$order,
			array(
				'attempt_id' => $attempt,
				'reason'     => 'Customer changed mind',
			)
		);

		// Act.
		$first = $this->gateway_command( $order, 'cancel', $body );
		$notes = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$again = $this->gateway_command( $order, 'cancel', $body );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( array( 'order', 'revision' ), array_keys( $again->get_data() ) );
		$this->assertSame( 'pos-open', $again->get_data()['order']['status'] );
		$this->assertSame( $notes, count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
		$this->assertSame( 'cancelled', Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) )[ $attempt ] );
		$this->assertSame( $attempt, $this->stored_commands( $order->get_id() )[ $body['command_id'] ]['ref'] );
	}

	/** Pin 27: a submit retried after its attempt was cancelled or superseded meets the state conflict, not its stored `sent`. */
	public function test_a_submit_replay_after_the_attempt_was_cancelled_is_the_state_conflict(): void {
		// Arrange: A sent under c1, then cancelled.
		$order = $this->create_pos_order();
		$body  = $this->envelope(
			$order,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);
		$this->assertSame( 200, $this->gateway_command( $order, 'submit', $body )->get_status() );
		$this->assertSame( 200, $this->cancel( $order, $body['attempt_id'] )->get_status() );

		// Act.
		$cancelled = $this->gateway_command( $order, 'submit', $body );

		// Arrange: A2 sent under c3, superseded by B.
		$other = $this->create_pos_order();
		$sent  = $this->envelope(
			$other,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);
		$this->assertSame( 200, $this->gateway_command( $other, 'submit', $sent )->get_status() );
		$this->assertSame( 200, $this->submit( $other, wp_generate_uuid4(), array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );

		// Act.
		$superseded = $this->gateway_command( $other, 'submit', $sent );

		// Assert.
		$this->assertSame( 409, $cancelled->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $cancelled->get_data()['code'] );
		$this->assertSame( 409, $superseded->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $superseded->get_data()['code'] );
		$this->assertSame( 3, Sent_Test_Gateway::$calls );
	}
	/** Pin 37: a submit whose command entry does not reach storage before dispatch is a retryable conflict; nothing is sent. */
	public function test_a_submit_whose_command_entry_is_not_stored_is_refused_before_dispatch(): void {
		// Arrange: every order save loses a staged command map.
		$order = $this->create_pos_order();
		$body  = $this->envelope(
			$order,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'values'     => array( 'invoice_email' => 'buyer@example.com' ),
			)
		);
		$drop = static function ( $saving ): void {
			$saving->delete_meta_data( '_wcpos_commands' );
		};
		add_action( 'woocommerce_before_order_object_save', $drop, 1 );

		// Act.
		try {
			$refused = $this->gateway_command( $order, 'submit', $body );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $drop, 1 );
		}
		$attempts = Gateway_Submission::read_attempts( wc_get_order( $order->get_id() ) );
		$sent     = $this->gateway_command( $order, 'submit', array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 409, $refused->get_status(), wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $refused->get_data()['code'] );
		$this->assertArrayNotHasKey( $body['attempt_id'], $attempts );
		$this->assertSame( 200, $sent->get_status(), wp_json_encode( $sent->get_data() ) );
		$this->assertSame( 'sent', $sent->get_data()['outcome'] );
		$this->assertSame( 1, Sent_Test_Gateway::$calls );
		$this->assertSame( 'sent', $this->stored_commands( $order->get_id() )[ $body['command_id'] ]['outcome'] );
	}
	/** A hook that drops the staged command map from every order save, for the storage-miss pins. */
	private function drop_command_map(): callable {
		$drop = static function ( $saving ): void {
			$saving->delete_meta_data( '_wcpos_commands' );
		};
		add_action( 'woocommerce_before_order_object_save', $drop, 1 );
		return $drop;
	}

	/** Pin 38: a cancel whose entry does not reach storage is answered 409; the cancel itself stands. */
	public function test_a_cancel_whose_command_entry_is_not_stored_is_a_retryable_conflict(): void {
		// Arrange: a sent attempt; then every save loses the command map.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->assertSame( 200, $this->submit( $order, $attempt, array( 'invoice_email' => 'buyer@example.com' ) )->get_status() );
		$body = $this->envelope(
			$order,
			array(
				'attempt_id' => $attempt,
				'reason'     => 'Customer changed mind',
			)
		);
		$drop = $this->drop_command_map();

		// Act.
		try {
			$refused = $this->gateway_command( $order, 'cancel', $body );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $drop, 1 );
		}
		$stored = wc_get_order( $order->get_id() );
		$notes  = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$again  = $this->gateway_command( $order, 'cancel', array_merge( $body, array( 'revision' => $this->current_revision( $order ) ) ) );

		// Assert.
		$this->assertSame( 409, $refused->get_status(), wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $refused->get_data()['code'] );
		$this->assertNull( Gateway_Submission::read_stamp( $stored ) );
		$this->assertSame( 'cancelled', Gateway_Submission::read_attempts( $stored )[ $attempt ] );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $again->get_data()['code'] );
		$this->assertSame( $notes, count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
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
	/**
	 * Whether process_payment() empties the cart, as WooCommerce's own offline gateways do.
	 *
	 * @var bool
	 */
	public static $empty_cart = false;

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
		if ( self::$empty_cart ) {
			WC()->cart->empty_cart();
		}
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
