<?php

/**
 * Loads the POS Payment Gateways
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Gateways {

	public function __construct() {
		add_action( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
	}

	/**
	 * Add POS gateways
	 *
	 * @param $gateways
	 *
	 * @return array
	 */
	public function payment_gateways( array $gateways ) {
		global $plugin_page;

		// don't show POS gateways on WC settings page or online checkout
		if ( is_admin() && 'wc-settings' == $plugin_page || ! is_admin() && ! woocommerce_pos_request() ) {
			return $gateways;
		}

		return array_merge( $gateways, array(
			'WCPOS\WooCommercePOS\Gateways\Cash',
			'WCPOS\WooCommercePOS\Gateways\Card',
		) );
	}
}
