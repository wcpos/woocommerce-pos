<?php

/**
 * Loads the POS Payment Gateways.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\API\Settings;

class Gateways {
	public function __construct() {
		add_action( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'available_payment_gateways' ) );
	}

	/**
	 * Add POS gateways
	 * BEWARE: some gateways/themes/plugins call this very early on every page!!
	 * We cannot guarantee that $wp is set, so we cannot use woocommerce_pos_request.
	 *
	 * @param $gateways
	 *
	 * @return array
	 */
	public function payment_gateways( array $gateways ) {
		global $plugin_page;

		// Remove gateways from WooCommerce settings, ie: they cannot be activated
		if ( is_admin() && 'wc-settings' == $plugin_page ) {
			return $gateways;
		}

		// All other cases, the default POS gateways are added
		return array_merge($gateways, array(
			'WCPOS\WooCommercePOS\Gateways\Cash',
			'WCPOS\WooCommercePOS\Gateways\Card',
		));
	}

	/**
	 * @param array $gateways
	 *
	 * @return array
	 */
	public function available_payment_gateways( array $gateways ): array {
		$_available_gateways = array();

		// early exit
		if ( ! woocommerce_pos_request() ) {
			return $gateways;
		}

		// use POS settings
		$gateway_settings    = Settings::get_gateways();
		$enabled_gateway_ids = array_reduce($gateway_settings, function ( $result, $gateway ) {
			if ( $gateway['id'] && $gateway['enabled'] ) {
				$result[] = $gateway['id'];
			};

			return $result;
		}, array());

		/*
		 * @TODO - WC()->payment_gateways->payment_gateways vs WC_Payment_Gateways::instance()->payment_gateways()
		 * @TODO - review settings/api/frontend overlap
		 */
		foreach ( WC()->payment_gateways->payment_gateways as $gateway ) {
			if ( \in_array( $gateway->id, $enabled_gateway_ids, true ) ) {
				$_available_gateways[ $gateway->id ] = $gateway;
			}
		}

		return $_available_gateways;
	}
}
