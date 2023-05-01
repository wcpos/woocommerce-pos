<?php

namespace WCPOS\WooCommercePOS\Admin;

class Orders {

	public function __construct() {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );
	}

	/**
	 * Hides uuid from appearing on Order Edit page
	 *
	 * @param array $meta_keys
	 * @return array
	 */
	public function hidden_order_itemmeta( array $meta_keys ) {
		return array_merge( $meta_keys, array( '_woocommerce_pos_uuid', '_woocommerce_pos_tax_status' ) );
	}

	/**
	 * Makes POS orders editable by default
	 *
	 * @param bool $is_editable
	 * @param \WC_Order $order
	 * @return bool
	 */
	public function wc_order_is_editable( bool $is_editable, \WC_Order $order ) {
		if ( $order->get_status() == 'pos-open' ) {
			$is_editable = true;
		}
		return $is_editable;
	}

}
