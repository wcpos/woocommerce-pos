<?php

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Order;

class Single_Order {
	public function __construct() {
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );
	}

	/**
	 * Makes POS orders editable by default.
	 *
	 * @param bool     $is_editable
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function wc_order_is_editable( bool $is_editable, WC_Order $order ): bool {
		if ( 'pos-open' == $order->get_status() ) {
			$is_editable = true;
		}

		return $is_editable;
	}
}
