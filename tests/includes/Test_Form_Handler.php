<?php
/**
 * Tests for the POS checkout Form_Handler.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

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
}
