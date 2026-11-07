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

			/*
			 * A WCPOS guest has no email and the pay page collects no billing
			 * details, so WooCommerce Stripe would refuse to create its customer
			 * ("Missing required customer field: email"). Stripe accepts a customer
			 * with no email, so lift the requirement for a request that has none.
			 */
			if ( '' === trim( (string) $order->get_billing_email() ) ) {
				add_filter( 'wc_stripe_create_customer_required_fields', array( $this, 'stripe_guest_required_fields' ), 10, 2 );
				add_filter( 'wc_stripe_create_customer_args', array( $this, 'stripe_guest_customer_args' ) );
			}
		}
	}

	/**
	 * Lift Stripe's required customer fields for a request with no email.
	 *
	 * Returns no required fields rather than dropping only 'email': with no
	 * Stripe context, 'name' and 'address' are required too, and a WCPOS guest
	 * has neither.
	 *
	 * @param array $required_fields         The fields Stripe requires.
	 * @param array $create_customer_request The Stripe customer request (Stripe 10.2.0+).
	 *
	 * @return array
	 */
	public function stripe_guest_required_fields( $required_fields, $create_customer_request = array() ) {
		$email = \is_array( $create_customer_request ) && isset( $create_customer_request['email'] ) && \is_scalar( $create_customer_request['email'] ) ? (string) $create_customer_request['email'] : '';

		if ( '' === trim( $email ) ) {
			return array();
		}

		return $required_fields;
	}

	/**
	 * Drop the blank email and blank address entries from a Stripe customer
	 * request with no email.
	 *
	 * @param array $args The Stripe customer request.
	 *
	 * @return array
	 */
	public function stripe_guest_customer_args( $args ) {
		if ( ! \is_array( $args ) ) {
			return $args;
		}

		$email = isset( $args['email'] ) && \is_scalar( $args['email'] ) ? (string) $args['email'] : '';
		if ( '' !== trim( $email ) ) {
			return $args;
		}

		unset( $args['email'] );

		if ( isset( $args['address'] ) && \is_array( $args['address'] ) ) {
			foreach ( $args['address'] as $key => $value ) {
				if ( null === $value || ( \is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
					unset( $args['address'][ $key ] );
				}
			}

			if ( empty( $args['address'] ) ) {
				unset( $args['address'] );
			}
		}

		return $args;
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

		// Same proof of access as pay_action(): the order key from the pay URL. The coupon
		// form posts back to that URL (action=""), so the key is always present for a real pay page.
		$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the access check; the nonce was verified above.
		if ( ! $order || '' === $provided_key || ! $order->key_is_valid( $provided_key ) ) {
			return;
		}

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
