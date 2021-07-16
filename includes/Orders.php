<?php

/**
 * WCPOS Orders Class
 * Extends WooCommerce Orders
 *
 * @package  WCPOS\WooCommercePOS\Orders
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Orders {

	/**
	 *
	 */
	public function __construct() {
		$this->register_order_status();
		add_filter( 'wc_order_statuses', array( $this, 'wc_order_statuses' ), 10, 1 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array(
			$this,
			'valid_order_statuses_for_payment'
		), 10, 2 );
	}

	/**
	 *
	 */
	private function register_order_status() {
		/**
		 * Order status for open orders
		 */
		register_post_status( 'wc-pos-open', array(
			'label'                     => _x( 'POS - Open', 'Order status', PLUGIN_NAME ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'POS - Open <span class="count">(%s)</span>', 'POS - Open <span class="count">(%s)</span>' ),
		) );

		/**
		 * Order status for order during checkout
		 */
		register_post_status( 'wc-pos-checkout', array(
			'label'                     => _x( 'POS - Checkout', 'Order status', PLUGIN_NAME ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'POS - Checkout <span class="count">(%s)</span>', 'POS - Checkout <span class="count">(%s)</span>' ),
		) );
	}

	/**
	 *
	 * @param array $order_statuses
	 *
	 * @return array
	 */
	public function wc_order_statuses( array $order_statuses ): array {
		$order_statuses['wc-pos-open']     = _x( 'POS - Open', 'Order status', PLUGIN_NAME );
		$order_statuses['wc-pos-checkout'] = _x( 'POS - Checkout', 'Order status', PLUGIN_NAME );

		return $order_statuses;
	}

	/**
	 * @param array $order_statuses
	 * @param \WC_Order $order
	 *
	 * @return mixed
	 */
	public function valid_order_statuses_for_payment( array $order_statuses, \WC_Order $order ) {
		array_push( $order_statuses, 'pos-checkout' );

		return $order_statuses;
	}

}
