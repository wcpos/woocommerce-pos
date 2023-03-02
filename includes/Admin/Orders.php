<?php

namespace WCPOS\WooCommercePOS\Admin;

class Orders {

	public function __construct() {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
	}

	/**
	 * Hides uuid from appearing on Order Edit page
	 *
	 * @param array $meta_keys
	 * @return array
	 */
	public function hidden_order_itemmeta( array $meta_keys ) {
		return array_merge( $meta_keys, array( '_woocommerce_pos_uuid' ) );
	}

}
