<?php
/**
 * Form Handler.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Services\Auth as AuthService;

/**
 * Form_Handler class.
 */
class Form_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// May need $wp global to access query vars.
		add_action( 'wp', array( $this, 'pay_action' ), 10 );
		add_action( 'wp', array( $this, 'coupon_action' ), 10 );
	}

	/**
	 * Hook in methods.
	 */
	public static function init() {
		// May need $wp global to access query vars.
		add_action( 'wp', array( __CLASS__, 'pay_action' ), 10 );
	}

	/**
	 * Process the pay action.
	 *
	 * There's a problem if the woocommerce_pay nonce doesn't match the current user,
	 * so we need to check the order and set current user to the order's customer.
	 */
	public function pay_action() {
		global $wp;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in the pay form handler.
		if ( woocommerce_pos_request() && isset( $_POST['woocommerce_pay'], $_GET['key'] ) ) {
			$order_id  = absint( $wp->query_vars['order-pay'] );
			$order     = wc_get_order( $order_id );

			// Ensure the order exists.
			if ( ! $order ) {
				wp_die(
					/* translators: Checkout/payment form error message shown when the order cannot be found. */
					esc_html__( 'Order does not exist.', 'woocommerce-pos' ),
					/* translators: Checkout/payment form error message title. */
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			// Verify the order key matches the key provided in the URL.
			$provided_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			if ( $provided_key !== $order->get_order_key() ) {
				wp_die(
					/* translators: Checkout/payment form error message shown when the order key does not match. */
					esc_html__( 'Order key mismatch.', 'woocommerce-pos' ),
					/* translators: Checkout/payment form error message title. */
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			// Check for 'wcpos_jwt' and fall back to 'token' if not present.
			// remove 'token' when wcpos_jwt is fully implemented.
			$token_key = isset( $_GET['wcpos_jwt'] ) ? 'wcpos_jwt' : ( isset( $_GET['token'] ) ? 'token' : null );

			if ( null === $token_key || ! isset( $_GET[ $token_key ] ) ) {
				wp_die(
					/* translators: Checkout/payment form error message shown when no cashier token is provided. */
					esc_html__( 'Token not provided.', 'woocommerce-pos' ),
					/* translators: Checkout/payment form error message title. */
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			// Verify the cashier is authorized to access the order.
			$provided_token = sanitize_text_field( wp_unslash( $_GET[ $token_key ] ) );
			$auth = AuthService::instance();
			$user = $auth->validate_token( $provided_token );
			if ( is_wp_error( $user ) ) {
				wp_die(
					/* translators: Checkout/payment form error message shown when the cashier token does not match. */
					esc_html__( 'Cashier token mismatch.', 'woocommerce-pos' ),
					/* translators: Checkout/payment form error message title. */
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			// set customer.
			wp_set_current_user( $order->get_customer_id() );

			/*
			 * The pay nonce was minted in Templates\Payment with the logged-out nonce
			 * identity forced to 0 (its nonce_user_logged_out filter). That filter only
			 * exists while the template renders — it is not registered on this POST,
			 * which WooCommerce's own pay handler processes later on this same 'wp'
			 * hook (priority 20). Without the mirror here, a guest-session cookie
			 * (set by the pay page itself, and always replayed by the iOS/Android
			 * WebViews) makes WC_Session_Handler resolve the logged-out identity to
			 * its 't_…' customer id at verify time, the nonce hash no longer matches,
			 * and WC_Form_Handler::pay_action() drops the payment silently.
			 * Priority 20 so it wins over WC_Session_Handler's filter (priority 10).
			 */
			add_filter( 'nonce_user_logged_out', array( $this, 'nonce_user_logged_out' ), 20, 2 );
		}
	}

	/**
	 * Force the logged-out nonce identity to 0 for the pay nonce, matching the
	 * identity Templates\Payment mints it with.
	 *
	 * @param int|string $uid    The logged-out nonce identity.
	 * @param string|int $action The nonce action.
	 *
	 * @return int|string
	 */
	public function nonce_user_logged_out( $uid, $action ) {
		if ( 'woocommerce-pay' === $action ) {
			return 0;
		}

		return $uid;
	}

	/**
	 * Process the coupon action.
	 */
	public function coupon_action() {
		global $wp;

		$is_coupon_request = isset( $_POST['pos_apply_coupon'] ) || isset( $_POST['pos_remove_coupon'] );
		if ( ! woocommerce_pos_request() || ! $is_coupon_request ) {
			return;
		}

		// Check for nonce.
		if ( ! isset( $_POST['pos_coupon_nonce'] ) || ! wp_verify_nonce( $_POST['pos_coupon_nonce'], 'pos_coupon_action' ) ) {
			return;
		}

		$order_id    = absint( $wp->query_vars['order-pay'] );
		$order       = wc_get_order( $order_id );

		if ( isset( $_POST['pos_apply_coupon'] ) ) {
			$coupon_code = isset( $_POST['pos_coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pos_coupon_code'] ) ) : '';
			$apply_result = $order->apply_coupon( $coupon_code );
			if ( is_wp_error( $apply_result ) ) {
				wc_add_notice( $apply_result->get_error_message(), 'error' );
			}
		} elseif ( isset( $_POST['pos_remove_coupon'] ) ) {
			$coupon_code = sanitize_text_field( wp_unslash( $_POST['pos_remove_coupon'] ) );

			$remove_result = $order->remove_coupon( $coupon_code );
			if ( ! $remove_result ) {
				wc_add_notice( __( 'Error', 'woocommerce' ) );
			}
		}
	}
}
