<?php

namespace WCPOS\WooCommercePOS;

class Form_Handler {

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

		if ( woocommerce_pos_request() && isset( $_POST['woocommerce_pay'], $_GET['key'] ) ) {
			$order_id  = absint( $wp->query_vars['order-pay'] );
			$order     = wc_get_order( $order_id );

			// set customer
			wp_set_current_user( $order->get_customer_id() );
		}

	}

	/**
	 *
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
