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

		// Early exit for WooCommerce settings, ie: don't show POS gateways
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
	 * Get available payment POS gateways,
	 * - Order and set default order
	 * - Also going to remove icons from the gateways
	 *
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
		$api = new Settings();
		$settings = $api->get_payment_gateways_settings();
		$enabled_gateway_ids = array_reduce($settings['gateways'], function ( $result, $gateway ) {
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
				$gateway->icon = '';
				$gateway->enabled = 'yes';
				$gateway->chosen = $gateway->id === $settings['default_gateway'];
				$_available_gateways[ $gateway->id ] = $gateway;
			}
		}

		// Create an array of gateway order values
		$gateway_order = array();
		foreach ( $settings['gateways'] as $gateway ) {
			if ( isset( $gateway['id'] ) && isset( $gateway['order'] ) ) {
				$gateway_order[ $gateway['id'] ] = $gateway['order'];
			}
		}

		// Order the available gateways according to the settings
		uksort( $_available_gateways, function ( $a, $b ) use ( $gateway_order ) {
			if ( ! isset( $gateway_order[ $a ] ) || ! isset( $gateway_order[ $b ] ) ) {
				return 0;
			}
			return $gateway_order[ $a ] <=> $gateway_order[ $b ];
		});

		return $_available_gateways;
	}
}
