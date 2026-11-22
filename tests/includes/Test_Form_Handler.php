<?php
/**
 * Tests for the POS checkout Form_Handler.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\Internal\Utilities\Users;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Form_Handler;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Cashier;
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
	 * A role holding only what a till needs to open and edit its own orders.
	 */
	private const MINIMAL_POS_ROLE = 'wcpos_test_minimal_pos';

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
		unset( $_GET['key'], $_GET['token'], $_POST['woocommerce_pay'], $_POST['woocommerce-pay-nonce'], $_REQUEST['woocommerce-pay-nonce'] );
		unset( $_POST['pos_apply_coupon'], $_POST['pos_remove_coupon'], $_POST['pos_coupon_code'], $_POST['pos_coupon_nonce'] );
		wp_set_current_user( 0 );
		remove_role( self::MINIMAL_POS_ROLE );
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
	 * @param \WC_Order $order      The order being paid.
	 * @param int|null  $token_user The user the cashier token is minted for, or null for the default cashier.
	 */
	private function arrange_pos_pay_post( \WC_Order $order, ?int $token_user = null ): void {
		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		$wp->query_vars['wcpos']     = 1;
		$_GET['key']                 = $order->get_order_key();
		$_POST['woocommerce_pay']    = '1';

		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $token_user ?? $this->cashier ) );
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
	 * Mint a cashier token for the given user into the request, as the pay page URL carries it.
	 *
	 * @param int $user_id The user the cashier token is minted for.
	 */
	private function add_cashier_token( int $user_id ): void {
		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $user_id ) );
		$this->assertIsString( $token, 'precondition: cashier JWT minted' );
		$_GET['token'] = $token;
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
		$this->add_cashier_token( $this->cashier );

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
		$this->add_cashier_token( $this->cashier );

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
	 * Create a POS guest order with an email, past WooCommerce's 10-minute grace period.
	 *
	 * @return \WC_Order The saved order.
	 */
	private function create_pos_guest_order_past_grace_period(): \WC_Order {
		$order = $this->create_pos_order_with_billing_email( 'guest@example.test' );
		$order->set_date_created( time() - HOUR_IN_SECONDS );
		$order->save();

		return $order;
	}

	/**
	 * An authenticated POS pay POST lifts WC 11.3.1's order-pay email verification.
	 */
	public function test_pay_action_guest_with_email_after_grace_period_lifts_order_pay_email_verification(): void {
		// Arrange.
		$order = $this->create_pos_guest_order_past_grace_period();
		$this->arrange_pos_pay_post( $order );
		wp_set_current_user( 0 );
		$this->assertTrue( Users::should_user_verify_order_email( $order->get_id(), null, 'order-pay' ), 'precondition: verification required' );

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertFalse( Users::should_user_verify_order_email( $order->get_id(), null, 'order-pay' ) );
	}

	/**
	 * The lift covers only the order being paid, in the 'order-pay' context.
	 */
	public function test_order_pay_email_verification_lift_is_scoped_to_the_paid_order(): void {
		// Arrange.
		$order       = $this->create_pos_guest_order_past_grace_period();
		$other_order = $this->create_pos_guest_order_past_grace_period();
		$this->arrange_pos_pay_post( $order );

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertTrue( Users::should_user_verify_order_email( $other_order->get_id(), null, 'order-pay' ) );
		$this->assertTrue( Users::should_user_verify_order_email( $order->get_id(), null, 'order-received' ) );
	}

	/**
	 * Pay POSTs that fail WCPOS's order key or cashier token check.
	 *
	 * @return array<string, array{string}>
	 */
	public static function pay_refusal_cases(): array {
		return array(
			'bad_token'       => array( 'bad_token' ),
			'missing_token'   => array( 'missing_token' ),
			'expired_token'   => array( 'expired_token' ),
			'wrong_order_key' => array( 'wrong_order_key' ),
		);
	}

	/**
	 * Break the arranged POS pay POST the way the named refusal case says.
	 *
	 * @param string $refusal_case One of pay_refusal_cases().
	 */
	private function apply_pay_refusal( string $refusal_case ): void {
		switch ( $refusal_case ) {
			case 'bad_token':
				$_GET['token'] = 'not-a-jwt';
				break;
			case 'missing_token':
				unset( $_GET['token'] );
				break;
			case 'expired_token':
				$expire = static fn( $expire, $issued_at ) => $issued_at - 1;
				add_filter( 'wcpos_jwt_access_token_expire', $expire, 10, 2 );
				$_GET['token'] = Auth::instance()->generate_access_token( get_user_by( 'id', $this->cashier ) );
				remove_filter( 'wcpos_jwt_access_token_expire', $expire, 10 );
				break;
			case 'wrong_order_key':
				$_GET['key'] = 'wc_order_wrong';
				break;
		}
	}

	/**
	 * A pay POST that fails authentication dies without lifting the email gate.
	 *
	 * @dataProvider pay_refusal_cases
	 *
	 * @param string $refusal_case The way the POST fails authentication.
	 */
	public function test_pay_action_unauthenticated_pay_post_keeps_order_pay_email_verification( string $refusal_case ): void {
		// Arrange.
		$order = $this->create_pos_guest_order_past_grace_period();
		$this->arrange_pos_pay_post( $order );
		$this->apply_pay_refusal( $refusal_case );
		wp_set_current_user( 0 );

		// Act.
		try {
			( new Form_Handler() )->pay_action();
			$this->fail( 'pay_action() must die on a POST that fails authentication' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 ); // The refusal is the expected outcome.
		}

		// Assert.
		$this->assertFalse( has_filter( 'woocommerce_order_email_verification_required' ) );
		$this->assertTrue( Users::should_user_verify_order_email( $order->get_id(), null, 'order-pay' ) );
	}

	/**
	 * Outside a POS request the email gate is left to WooCommerce.
	 */
	public function test_pay_action_outside_pos_request_keeps_order_pay_email_verification(): void {
		global $wp;

		// Arrange.
		$order = $this->create_pos_guest_order_past_grace_period();
		$this->arrange_pos_pay_post( $order );
		unset( $wp->query_vars['wcpos'] );
		wp_set_current_user( 0 );

		// Act.
		( new Form_Handler() )->pay_action();

		// Assert.
		$this->assertFalse( has_filter( 'woocommerce_order_email_verification_required' ) );
		$this->assertTrue( Users::should_user_verify_order_email( $order->get_id(), null, 'order-pay' ) );
	}

	/**
	 * A third-party apply_filters() call with only the value keeps that value.
	 */
	public function test_lift_order_pay_email_verification_one_argument_returns_required(): void {
		// Arrange.
		$handler = new Form_Handler();

		// Act.
		$required = $handler->lift_order_pay_email_verification( true );

		// Assert.
		$this->assertTrue( $required );
	}

	/**
	 * WCPOS's handler then WooCommerce's, in 'wp' order: WooCommerce 11.3.1's
	 * pay_for_order and email gates let the guest POS payment through.
	 */
	public function test_pay_action_guest_past_grace_period_reaches_woocommerce_before_pay_action(): void {
		// Arrange.
		$order = $this->create_pos_guest_order_past_grace_period();
		$order->set_total( 50 ); // The helper order has a 0.00 total, so it would not need payment.
		$order->save();
		$this->arrange_pos_pay_post( $order );
		wp_set_current_user( 0 );
		$nonce                             = $this->mint_template_pay_nonce();
		$_POST['woocommerce-pay-nonce']    = $nonce;
		$_REQUEST['woocommerce-pay-nonce'] = $nonce; // WooCommerce reads the nonce from $_REQUEST.
		$this->assertTrue( $order->needs_payment(), 'precondition: the order needs payment' );
		$before_pay = did_action( 'woocommerce_before_pay_action' );
		$level      = ob_get_level();

		// Act.
		try {
			( new Form_Handler() )->pay_action();
			\WC_Form_Handler::pay_action();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean(); // WooCommerce's pay_action() opens a buffer it leaves open.
			}
		}

		// Assert.
		$this->assertSame( $before_pay + 1, did_action( 'woocommerce_before_pay_action' ) );
		wc_clear_notices();
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

	/**
	 * Create a user with the plugin's default cashier role, or with a role that
	 * may open the POS and edit only its own orders.
	 *
	 * @param bool $minimal Whether to use the minimal role.
	 *
	 * @return int The user ID.
	 */
	private function create_pos_user( bool $minimal ): int {
		if ( $minimal && ! get_role( self::MINIMAL_POS_ROLE ) ) {
			$caps = array( 'read', 'access_woocommerce_pos', 'publish_shop_orders', 'edit_shop_orders' );
			add_role( self::MINIMAL_POS_ROLE, 'Minimal POS', array_fill_keys( $caps, true ) );
		}
		$user_id = $this->factory->user->create( array( 'role' => $minimal ? self::MINIMAL_POS_ROLE : 'cashier' ) );
		$this->assertTrue( Cashier::instance()->can_open_pos( get_user_by( 'id', $user_id ) ), 'precondition: the user can open the POS' );
		$this->assertSame( ! $minimal, user_can( $user_id, 'edit_others_shop_orders' ), 'precondition: edit_others_shop_orders' );

		return $user_id;
	}

	/**
	 * Create a web order (created at checkout, no cashier) or a POS order assigned to a cashier.
	 *
	 * @param int|null $cashier_id The cashier the POS order belongs to, or null for a web order.
	 *
	 * @return \WC_Order The saved order.
	 */
	private function create_order_for( ?int $cashier_id ): \WC_Order {
		$order = OrderHelper::create_order( array( 'customer_id' => $this->factory->user->create( array( 'role' => 'customer' ) ) ) );
		$order->set_created_via( null === $cashier_id ? 'checkout' : 'woocommerce-pos' );
		if ( null !== $cashier_id ) {
			$order->update_meta_data( '_pos_user', (string) $cashier_id );
		}
		$order->save();

		return $order;
	}

	/**
	 * Run pay_action() under the given current user and assert the outcome. A pass
	 * registers the pay nonce correction and switches to the order's customer; a
	 * refusal is the token-failure 403 and leaves the current user unchanged.
	 *
	 * @param \WC_Order $order   The order being paid.
	 * @param bool      $passes  Whether the request must pass the gate.
	 * @param int       $current The request's current user before the gate.
	 */
	private function assert_pay_action( \WC_Order $order, bool $passes, int $current = 0 ): void {
		wp_set_current_user( $current );
		$handler = new Form_Handler();
		$refusal = null;
		$die     = static function () use ( &$refusal ) {
			return static function ( $message, $title, $args ) use ( &$refusal ) {
				$refusal = array( $message, $args['response'] ?? null );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Capture wp_die output, not rendering.
				throw new \WPDieException( $message );
			};
		};
		add_filter( 'wp_die_handler', $die );
		try {
			$handler->pay_action();
		} catch ( \WPDieException $exception ) {
			unset( $exception ); // Captured by the handler above.
		} finally {
			remove_filter( 'wp_die_handler', $die );
		}

		$this->assertSame( $passes ? null : array( 'Cashier token mismatch.', 403 ), $refusal );
		$this->assertSame( $passes ? 20 : false, has_filter( 'nonce_user_logged_out', array( $handler, 'nonce_user_logged_out' ) ) );
		$this->assertSame( $passes ? $order->get_customer_id() : $current, get_current_user_id() );
	}

	/** A default cashier pays a synced web order at the till. */
	public function test_pay_action_default_cashier_on_web_order_passes(): void {
		$order = $this->create_order_for( null );
		$this->arrange_pos_pay_post( $order, $this->create_pos_user( false ) );

		$this->assert_pay_action( $order, true );
	}

	/** A role that may edit only its own orders pays its own POS order. */
	public function test_pay_action_minimal_role_on_own_pos_order_passes(): void {
		$user  = $this->create_pos_user( true );
		$order = $this->create_order_for( $user );
		$this->arrange_pos_pay_post( $order, $user );

		$this->assert_pay_action( $order, true );
	}

	/** That role cannot pay another cashier's POS order, whoever the request is logged in as. */
	public function test_pay_action_minimal_role_on_other_cashiers_pos_order_is_refused(): void {
		$order = $this->create_order_for( $this->create_pos_user( true ) );
		$this->arrange_pos_pay_post( $order, $this->create_pos_user( true ) );

		$this->assert_pay_action( $order, false, $this->cashier );
	}

	/** That role cannot pay a web order, whoever the request is logged in as. */
	public function test_pay_action_minimal_role_on_web_order_is_refused(): void {
		$order = $this->create_order_for( null );
		$this->arrange_pos_pay_post( $order, $this->create_pos_user( true ) );

		$this->assert_pay_action( $order, false, $this->cashier );
	}

	/** A token minted before its user lost POS access no longer pays. */
	public function test_pay_action_demoted_cashier_is_refused(): void {
		$cashier = $this->create_pos_user( false );
		$order   = $this->create_order_for( null );
		$this->arrange_pos_pay_post( $order, $cashier );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos', false );
		$this->assertFalse( Cashier::instance()->can_open_pos( get_user_by( 'id', $cashier ) ), 'precondition: demoted' );

		$this->assert_pay_action( $order, false, $this->cashier );
	}

	/** WooCommerce refusing the order edit refuses the pay POST. */
	public function test_pay_action_woocommerce_edit_refusal_is_refused(): void {
		$order = $this->create_order_for( null );
		$this->arrange_pos_pay_post( $order, $this->create_pos_user( false ) );
		add_filter( 'woocommerce_rest_check_permissions', '__return_false', 1000 );

		try {
			$this->assert_pay_action( $order, false );
		} finally {
			remove_filter( 'woocommerce_rest_check_permissions', '__return_false', 1000 );
		}
	}

	/** A refusal leaves a logged-in request's current user as it was. */
	public function test_pay_action_refusal_leaves_current_user_unchanged(): void {
		$order = $this->create_order_for( null );
		$this->arrange_pos_pay_post( $order, $this->create_pos_user( true ) );

		$this->assert_pay_action( $order, false, $this->factory->user->create( array( 'role' => 'customer' ) ) );
	}

	/**
	 * Run coupon_action() and assert its refusal and the order's coupons afterwards.
	 *
	 * @param \WC_Order   $order   The order being updated.
	 * @param string|null $refusal The expected 403 message, or null when the request must pass.
	 * @param array       $codes   The order's coupon codes after the request.
	 */
	private function assert_coupon_action( \WC_Order $order, ?string $refusal, array $codes ): void {
		$handler  = new Form_Handler();
		$captured = null;
		$die      = static function () use ( &$captured ) {
			return static function ( $message, $title, $args ) use ( &$captured ) {
				$captured = array( $message, $args['response'] ?? null );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Capture wp_die output, not rendering.
				throw new \WPDieException( $message );
			};
		};
		add_filter( 'wp_die_handler', $die );
		try {
			$handler->coupon_action();
		} catch ( \WPDieException $exception ) {
			unset( $exception ); // Captured by the handler above.
		} finally {
			remove_filter( 'wp_die_handler', $die );
		}

		$this->assertSame( null === $refusal ? null : array( $refusal, 403 ), $captured );
		$this->assertSame( $codes, wc_get_order( $order->get_id() )->get_coupon_codes() );
	}

	/** The right order key without a cashier token does not apply a coupon. */
	public function test_coupon_action_without_token_is_refused(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', $order->get_order_key() );

		// Act and assert.
		$this->assert_coupon_action( $order, 'Token not provided.', array() );
	}

	/** A token minted before its user lost POS access no longer applies a coupon. */
	public function test_coupon_action_demoted_cashier_is_refused(): void {
		// Arrange.
		$cashier = $this->create_pos_user( false );
		$order   = $this->create_order_for( null );
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', $order->get_order_key() );
		$this->add_cashier_token( $cashier );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos', false );

		// Act and assert.
		$this->assert_coupon_action( $order, 'Cashier token mismatch.', array() );
	}

	/** A role that may edit only its own orders cannot change another cashier's POS order. */
	public function test_coupon_action_minimal_role_on_other_cashiers_pos_order_is_refused(): void {
		// Arrange.
		$order = $this->create_order_for( $this->create_pos_user( true ) );
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', $order->get_order_key() );
		$this->add_cashier_token( $this->create_pos_user( true ) );

		// Act and assert.
		$this->assert_coupon_action( $order, 'Cashier token mismatch.', array() );
	}

	/** A cashier who may edit others' orders applies a coupon to another cashier's POS order. */
	public function test_coupon_action_other_cashier_who_can_edit_passes(): void {
		// Arrange.
		$order = $this->create_order_for( $this->create_pos_user( false ) );
		CouponHelper::create_coupon( 'keycheck' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->arrange_pos_coupon_post( $order, 'apply', 'keycheck', $order->get_order_key() );
		$this->add_cashier_token( $this->create_pos_user( false ) );

		// Act and assert.
		$this->assert_coupon_action( $order, null, array( 'keycheck' ) );
	}
}
