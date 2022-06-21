<?php

/**
 * WCPOS Form Handler
 * Extends WooCommerce Forms
 * See woocommerce/includes/class-wc-form-handler.php
 *
 * @package  WCPOS\WooCommercePOS\FormHandler
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;


class WCPOS_Form_Handlers {

	/**
	 * Hook in methods.
	 */
	public static function init() {
		// May need $wp global to access query vars.
		add_action( 'wp', array( __CLASS__, 'pay_action' ), 10 );
	}

	public static function pay_action() {
		global $wp;

		if ( ! function_exists( 'woocommerce_pos_request' ) || ! woocommerce_pos_request() ) {
			return;
		}

		if ( isset( $_POST['woocommerce_pay'], $_GET['key'] ) ) {
			$order_key = wp_unslash( $_GET['key'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$order_id  = absint( $wp->query_vars['order-pay'] );
			$order     = wc_get_order( $order_id );

			// set customer
			wp_set_current_user( $order->get_customer_id() );
		}

	}
}

WCPOS_Form_Handlers::init();
