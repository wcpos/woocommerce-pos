<?php

namespace WCPOS\WooCommercePOS;

class AJAX {

	/**
	 * WooCommerce AJAX actions that we need to hook into on the Product admin pages.
	 *
	 * @var string[]
	 */
	private $product_actions = array(
		'load_variations',
		'save_variations',
	);

	/**
	 * WooCommerce AJAX actions that we need to hook into on the Order admin pages.
	 *
	 * @var string[]
	 */
	private $order_actions = array(
		'add_order_item',
		'add_order_fee',
		'add_order_shipping',
		'add_order_tax',
		'add_coupon_discount',
		'remove_order_coupon',
		'remove_order_item',
		'remove_order_tax',
		'calc_line_taxes',
		'save_order_items',
		'load_order_items',
		'get_order_details',
	);

	/**
	 *
	 */
	public function __construct() {
		$this->woocommerce_ajax_actions();
	}

	/**
	 *
	 */
	private function woocommerce_ajax_actions() {
		foreach ( $this->product_actions as $ajax_event ) {
			add_action( 'wp_ajax_woocommerce_' . $ajax_event, array( $this, 'woocommerce_product_ajax' ), 9, 0 );
		}
		foreach ( $this->order_actions as $ajax_event ) {
			add_action( 'wp_ajax_woocommerce_' . $ajax_event, array( $this, 'woocommerce_order_ajax' ), 9, 0 );
		}
	}

	/**
	 * The Admin\Products class is not loaded for AJAX requests.
	 * We need to load it manually here.
	 */
	public function woocommerce_product_ajax() {
		new Admin\Products();
	}

	/**
	 * The Admin\Orders class is not loaded for AJAX requests.
	 * We need to load it manually here.
	 */
	public function woocommerce_order_ajax() {
		new Admin\Orders();
	}

}
