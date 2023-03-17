<?php

namespace WCPOS\WooCommercePOS;

class Form_Handler {

	public function __construct() {
		// May need $wp global to access query vars.
		add_action( 'wp', array( $this, 'pay_action' ), 10 );
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

}
