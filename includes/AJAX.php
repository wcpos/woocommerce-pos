<?php

namespace WCPOS\WooCommercePOS;

class AJAX {

	/**
	 * Our hook for the Orders and Products admin will not register for AJAX requests.
	 * We need to load the classes manually here.
	 *
	 * @TODO Perhaps there a way to load for any AJAX request coming from a certain WooCommerce admin page?
	 *
	 * See WC_AJAX::add_ajax_events()
	 */
	public function __construct() {
		$product_actions = array(
			'woocommerce_load_variations',
			'woocommerce_save_variations',
		);
		$order_actions = array(
			'woocommerce_add_order_item',
			'woocommerce_add_order_fee',
			'woocommerce_add_order_shipping',
			'woocommerce_add_order_tax',
			'woocommerce_add_coupon_discount',
			'woocommerce_remove_order_coupon',
			'woocommerce_remove_order_item',
			'woocommerce_remove_order_tax',
			'woocommerce_calc_line_taxes',
			'woocommerce_save_order_items',
			'woocommerce_load_order_items',
		);

		if ( $this->is_action_in_list( $product_actions ) ) {
			new Admin\Products();
		}

		if ( $this->is_action_in_list( $order_actions ) ) {
			new Admin\Orders();
		}
	}

	/**
	 * @TODO - add nonce checks
	 *
	 * @param $actions
	 * @return bool
	 */
	private function is_action_in_list( $actions ) {
		return isset( $_POST['action'] ) && in_array( $_POST['action'], $actions );
	}
}
