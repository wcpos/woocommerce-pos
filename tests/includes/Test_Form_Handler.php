<?php
/**
 * Tests for the POS checkout Form_Handler.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Form_Handler;
use WCPOS\WooCommercePOS\Services\Auth;
use WC_Unit_Test_Case;

/**
 * Form_Handler tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Form_Handler extends WC_Unit_Test_Case {
	/**
	 * Cashier user ID.
	 *
	 * @var int
	 */
	private $cashier;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->cashier = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;
		unset( $wp->query_vars['order-pay'], $wp->query_vars['wcpos'] );
		unset( $_GET['key'], $_GET['token'], $_POST['woocommerce_pay'] );
		unset( $_POST['pos_apply_coupon'], $_POST['pos_remove_coupon'], $_POST['pos_coupon_code'], $_POST['pos_coupon_nonce'] );
		wp_set_current_user( 0 );
		// Hooks added during the test (ours and Form_Handler's) are restored by
		// the WP test framework's hook backup in parent::tearDown().
		parent::tearDown();
	}

	/**
	 * Mint the pay nonce the way Templates\Payment does for a guest order: the
	 * template registers a nonce_user_logged_out filter that forces the
	 * logged-out identity to 0 for 'woocommerce-pay', then renders
	 * wp_nonce_field( 'woocommerce-pay' ). The filter is template-scoped, so it
	 * is gone by the time the form POSTs back.
	 *
	 * @return string The minted nonce.
	 */
	private function mint_template_pay_nonce(): string {
		$force_zero = function ( $uid, $action ) {
			return 'woocommerce-pay' === $action ? 0 : $uid;
		};
		add_filter( 'nonce_user_logged_out', $force_zero, 10, 2 );
		$nonce = wp_create_nonce( 'woocommerce-pay' );
		remove_filter( 'nonce_user_logged_out', $force_zero, 10 );

		return $nonce;
	}

	/**
	 * Stand-in for WC_Session_Handler::nonce_user_logged_out() as it behaves on
	 * the pay POST when the client replays the guest session cookie the pay page
	 * itself set: the logged-out identity resolves to the session's 't_…'
	 * customer id instead of 0. Pinned here because the WC test environment does
	 * not run the real cookie-backed session handler.
	 *
	 * @return callable The registered filter callback, for later removal.
	 */
	private function register_guest_session_identity(): callable {
		$session_identity = function ( $uid, $action ) {
			if ( \is_string( $action ) && 0 === strpos( $action, 'woocommerce' ) ) {
				return 't_1234567890abcdef1234567890abcd';
			}

			return $uid;
		};
		// Priority 10, same as WC_Session_Handler.
		add_filter( 'nonce_user_logged_out', $session_identity, 10, 2 );

		return $session_identity;
	}

	/**
	 * Point the request context at a POS pay POST for the given order.
	 *
	 * @param \WC_Order $order The order being paid.
	 */
	private function arrange_pos_pay_post( \WC_Order $order ): void {
		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		$wp->query_vars['wcpos']     = 1;
		$_GET['key']                 = $order->get_order_key();
		$_POST['woocommerce_pay']    = '1';

		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->cashier ) );
		$this->assertIsString( $token, 'precondition: cashier JWT minted' );
		$_GET['token'] = $token;
	}

	/**
	 * Point the request context at a POS coupon POST for the given order.
	 *
	 * @param \WC_Order $order  The order being updated.
	 * @param string    $action The coupon action: apply or remove.
	 * @param string    $code   The coupon code.
	 * @param ?string   $key    The order key, or null to omit it.
	 */
	private function arrange_pos_coupon_post( \WC_Order $order, string $action, string $code, ?string $key ): void {
		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		$wp->query_vars['wcpos']     = 1;
		$_POST['pos_coupon_nonce']  = wp_create_nonce( 'pos_coupon_action' );
		if ( 'apply' === $action ) {
			$_POST['pos_apply_coupon'] = '1';
			$_POST['pos_coupon_code']  = $code;
		} else {
			$_POST['pos_remove_coupon'] = $code;
		}
		if ( null !== $key ) {
			$_GET['key'] = $key;
		}
	}

	/**
	 * A missing order key must not allow a coupon to be applied.
	 */
	public function test_coupon_action_without_order_key_does_not_apply_coupon(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role' => 'customer',
				)
			)
		);
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', null );

		// Act.
		( new Form_Handler() )->coupon_action();

		// Assert.
		$this->assertSame( array(), wc_get_order( $order->get_id() )->get_coupon_codes() );
	}

	/**
	 * A wrong order key must not allow a coupon to be removed.
	 */
	public function test_coupon_action_with_wrong_order_key_does_not_remove_coupon(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		CouponHelper::create_coupon( 'keycheck' );
		$order->apply_coupon( 'keycheck' );
		$order->save();
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role' => 'customer',
				)
			)
		);
		$this->arrange_pos_coupon_post( $order, 'remove', 'keycheck', 'wc_order_wrongkey' );

		// Act.
		( new Form_Handler() )->coupon_action();

		// Assert.
		$this->assertSame( array( 'keycheck' ), wc_get_order( $order->get_id() )->get_coupon_codes() );
	}

	/**
	 * The correct order key allows a coupon to be applied.
	 */
	public function test_coupon_action_with_order_key_applies_coupon(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role' => 'customer',
				)
			)
		);
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', $order->get_order_key() );

		// Act.
		( new Form_Handler() )->coupon_action();

		// Assert.
		$this->assertSame( array( 'keycheck' ), wc_get_order( $order->get_id() )->get_coupon_codes() );
	}

	/**
	 * The correct order key allows a coupon to be removed.
	 */
	public function test_coupon_action_with_order_key_removes_coupon(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		CouponHelper::create_coupon( 'keycheck' );
		$order->apply_coupon( 'keycheck' );
		$order->save();
		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role' => 'customer',
				)
			)
		);
		$this->arrange_pos_coupon_post( $order, 'remove', 'keycheck', $order->get_order_key() );

		// Act.
		( new Form_Handler() )->coupon_action();

		// Assert.
		$this->assertSame( array(), wc_get_order( $order->get_id() )->get_coupon_codes() );
	}

	/**
	 * An unknown order must return without attempting to apply a coupon.
	 */
	public function test_coupon_action_unknown_order_returns_without_error(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['order-pay'] = PHP_INT_MAX;
		$wp->query_vars['wcpos']     = 1;
		$_POST['pos_coupon_nonce']  = wp_create_nonce( 'pos_coupon_action' );
		$_POST['pos_apply_coupon']  = '1';
		$_POST['pos_coupon_code']   = 'keycheck';
		$_GET['key']               = 'wc_order_wrongkey';

		// Act.
		( new Form_Handler() )->coupon_action();

		// Assert.
		$this->assertTrue( true );
	}

	/**
	 * A guest order's pay nonce is minted with logged-out identity 0, but a
	 * client that replays the guest session cookie (native WebViews always do)
	 * gets identity 't_…' at verify time — the mismatch made
	 * WC_Form_Handler::pay_action() drop the payment silently. pay_action() must
	 * re-register the identity correction so the same nonce still verifies when
	 * WooCommerce's handler runs later on the 'wp' hook.
	 */
	public function test_pay_nonce_verifies_with_guest_session_cookie(): void {
		$order = OrderHelper::create_order();
		$order->set_customer_id( 0 );
		$order->save();

		$nonce = $this->mint_template_pay_nonce();
		$this->register_guest_session_identity();

		// Precondition (the bug): with the session identity in play and no
		// correction registered, the template-minted nonce does not verify.
		// If this assertion fails the harness is not reproducing the mismatch
		// and the test below would pass vacuously.
		wp_set_current_user( 0 );
		$this->assertFalse(
			(bool) wp_verify_nonce( $nonce, 'woocommerce-pay' ),
			'precondition: template-minted nonce must NOT verify under the guest session identity'
		);

		$this->arrange_pos_pay_post( $order );
		( new Form_Handler() )->pay_action();

		// WC_Form_Handler::pay_action() runs after ours on the same 'wp' hook;
		// by then the nonce must verify again.
		$this->assertNotFalse(
			wp_verify_nonce( $nonce, 'woocommerce-pay' ),
			'pay nonce must verify on the POS pay POST despite the guest session cookie'
		);
	}

	/**
	 * The correction is scoped to the pay nonce: other logged-out nonce actions
	 * keep whatever identity other filters resolve.
	 */
	public function test_identity_correction_is_scoped_to_pay_nonce(): void {
		$order = OrderHelper::create_order();
		$order->set_customer_id( 0 );
		$order->save();

		$this->arrange_pos_pay_post( $order );
		( new Form_Handler() )->pay_action();

		wp_set_current_user( 0 );
		$this->assertSame(
			'unrelated-identity',
			apply_filters( 'nonce_user_logged_out', 'unrelated-identity', 'some_other_action' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook.
			'non-pay nonce actions must pass through untouched'
		);
	}

	/**
	 * Without a valid cashier token the handler dies before touching the nonce
	 * identity — the correction must not be registered for unauthenticated POSTs.
	 */
	public function test_no_identity_correction_without_pay_post(): void {
		// No POS pay POST context at all.
		( new Form_Handler() )->pay_action();

		wp_set_current_user( 0 );
		$this->assertSame(
			'session-identity',
			apply_filters( 'nonce_user_logged_out', 'session-identity', 'woocommerce-pay' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook.
			'the identity correction must only be registered on a POS pay POST'
		);
	}

	/**
	 * Create an order paid by the POS with the given billing email.
	 *
	 * @param string $billing_email The order's billing email.
	 *
	 * @return \WC_Order The saved order.
	 */
	private function create_pos_order_with_billing_email( string $billing_email ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_customer_id( 0 );
		$order->set_billing_email( $billing_email );
		$order->save();

		return $order;
	}

	/**
	 * A POS guest has no email and the pay page collects none, so Stripe's
	 * customer requirements are lifted for a request with no email.
	 */
	public function test_pay_action_guest_without_email_lifts_stripe_customer_requirements(): void {
		// Arrange.
		$order = $this->create_pos_order_with_billing_email( '' );
		$this->arrange_pos_pay_post( $order );

		// Act.
		( new Form_Handler() )->pay_action();
		$stripe_required = array(
			'email'   => true,
			'name'    => true,
			'address' => array( 'line1' => true ),
		);
		$required_fields = apply_filters( 'wc_stripe_create_customer_required_fields', $stripe_required, array( 'email' => '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.

		// Assert.
		$this->assertSame( array(), $required_fields );
	}

	/**
	 * Blank email and address entries are dropped from a guest's Stripe customer
	 * request; everything else is kept.
	 */
	public function test_pay_action_guest_without_email_strips_blank_stripe_customer_args(): void {
		// Arrange.
		$order = $this->create_pos_order_with_billing_email( '' );
		$this->arrange_pos_pay_post( $order );

		// Act.
		( new Form_Handler() )->pay_action();
		$args = apply_filters(
			'wc_stripe_create_customer_args', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
			array(
				'email'       => '',
				'description' => 'Guest',
				'address'     => array(
					'line1'   => '',
					'country' => 'GB',
				),
			)
		);
		$all_blank_args = apply_filters(
			'wc_stripe_create_customer_args', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
			array(
				'email'       => '',
				'description' => 'Guest',
				'address'     => array(
					'line1'   => '',
					'country' => '',
				),
			)
		);

		// Assert.
		$this->assertArrayNotHasKey( 'email', $args );
		$this->assertSame( 'Guest', $args['description'] );
		$this->assertSame( array( 'country' => 'GB' ), $args['address'] );
		$this->assertArrayNotHasKey( 'email', $all_blank_args );
		$this->assertArrayNotHasKey( 'address', $all_blank_args );
		$this->assertSame( 'Guest', $all_blank_args['description'] );
	}

	/**
	 * An order with a billing email keeps Stripe's requirements and request.
	 */
	public function test_pay_action_order_with_email_keeps_stripe_customer_requirements(): void {
		// Arrange.
		$order = $this->create_pos_order_with_billing_email( 'buyer@example.com' );
		$this->arrange_pos_pay_post( $order );
		$required_fields = array( 'email' => true );
		$request         = array(
			'email'   => 'buyer@example.com',
			'address' => array(
				'line1'   => '',
				'country' => 'GB',
			),
		);

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertFalse( has_filter( 'wc_stripe_create_customer_required_fields' ) );
		$this->assertFalse( has_filter( 'wc_stripe_create_customer_args' ) );
		$this->assertSame( $required_fields, apply_filters( 'wc_stripe_create_customer_required_fields', $required_fields, $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
		$this->assertSame( $request, apply_filters( 'wc_stripe_create_customer_args', $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
	}

	/**
	 * A blank order email with an email in Stripe's request (a registered
	 * customer's user email) keeps Stripe's requirements and request.
	 */
	public function test_pay_action_blank_order_email_with_request_email_keeps_stripe_customer_requirements(): void {
		// Arrange.
		$order = $this->create_pos_order_with_billing_email( '' );
		$this->arrange_pos_pay_post( $order );
		$required_fields = array(
			'email'   => true,
			'name'    => true,
			'address' => array( 'line1' => true ),
		);
		$request         = array(
			'email'       => 'a@example.com',
			'description' => 'Customer',
			'address'     => array(
				'line1'   => '',
				'country' => '',
			),
		);

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertSame( $required_fields, apply_filters( 'wc_stripe_create_customer_required_fields', $required_fields, $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
		$this->assertSame( $request, apply_filters( 'wc_stripe_create_customer_args', $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
	}

	/**
	 * Outside a POS request (a store's own pay page) the Stripe filters are not
	 * registered, even for an order with a blank billing email.
	 */
	public function test_pay_action_outside_pos_request_leaves_stripe_customer_filters_alone(): void {
		global $wp;

		// Arrange.
		$order = $this->create_pos_order_with_billing_email( '' );
		$this->arrange_pos_pay_post( $order );
		unset( $wp->query_vars['wcpos'] );
		$required_fields = array( 'email' => true );
		$request         = array(
			'email'   => '',
			'address' => array( 'line1' => '' ),
		);

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertFalse( has_filter( 'wc_stripe_create_customer_required_fields' ) );
		$this->assertFalse( has_filter( 'wc_stripe_create_customer_args' ) );
		$this->assertSame( $required_fields, apply_filters( 'wc_stripe_create_customer_required_fields', $required_fields, $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
		$this->assertSame( $request, apply_filters( 'wc_stripe_create_customer_args', $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Stripe's hook.
	}

	/**
	 * Stripe below 10.2.0 passes only the required fields; with no request to
	 * read, the email counts as blank.
	 */
	public function test_stripe_guest_required_fields_accepts_one_argument(): void {
		// Arrange.
		$handler = new Form_Handler();

		// Act.
		$required_fields = $handler->stripe_guest_required_fields( array( 'email' => true ) );

		// Assert.
		$this->assertSame( array(), $required_fields );
	}
}
