<?php
/**
 * Loads the POS Payment Gateways.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 * Gateways class.
 */
class Gateways {
	/**
	 *
	 */
	public function __construct() {
		add_action( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'available_payment_gateways' ), 99 );
	}

	/**
	 * Add POS gateways
	 * BEWARE: some gateways/themes/plugins call this very early on every page!!
	 * We cannot guarantee that $wp is set, so we cannot use woocommerce_pos_request.
	 *
	 * @param null|array $gateways
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
		return array_merge(
			$gateways,
			array(
				'WCPOS\WooCommercePOS\Gateways\Cash',
				'WCPOS\WooCommercePOS\Gateways\Card',
			)
		);
	}

	/**
	 * Get available payment POS gateways,
	 * - Order and set default order
	 * - Also going to remove icons from the gateways.
	 *
	 * - NOTE: lots of plugins/themes call this filter and I can't guarantee that $gateways is an array
	 *
	 * @param null|array $gateways The available payment gateways.
	 *
	 * @return null|array The available payment gateways.
	 */
	public function available_payment_gateways( ?array $gateways ): ?array {
		// early exit.
		if ( ! woocommerce_pos_request() ) {
			return $gateways;
		}

		// use POS settings.
		$settings = woocommerce_pos_get_settings( 'payment_gateways' );

		// Get all payment gateways.
		$all_gateways = WC()->payment_gateways->payment_gateways;

		$_available_gateways = array();

		foreach ( $all_gateways as $gateway ) {
			if ( isset( $settings['gateways'][ $gateway->id ] ) && $settings['gateways'][ $gateway->id ]['enabled'] ) {
				if ( isset( $settings['gateways'][ $gateway->id ]['title'] ) ) {
					$gateway->title = $settings['gateways'][ $gateway->id ]['title'];
				}
				/*
				 * There is an issue over-writing the description field because some gateways use this for info,
				 * eg: Account Funds uses it to show the current balance.
				 */
				// if ( isset( $settings['gateways'][ $gateway->id ]['description'] ) ) {
				// $gateway->description = $settings['gateways'][ $gateway->id ]['description'];
				// }

				$gateway->icon    = '';
				$gateway->enabled = 'yes';
				$gateway->chosen  = $gateway->id === $settings['default_gateway'];

				$_available_gateways[ $gateway->id ] = $gateway;
			}
		}

		// Order the available gateways according to the settings
		uksort(
			$_available_gateways,
			function ( $a, $b ) use ( $settings ) {
				return $settings['gateways'][ $a ]['order'] <=> $settings['gateways'][ $b ]['order'];
			}
		);

		return $_available_gateways;
	}
}
