<?php
/**
 * Payment Gateways Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WC_Payment_Gateways;

/**
 * The Payment Gateways Settings Section.
 *
 * Read is fully overridden: the stored option is merged over defaults, the
 * legacy global checkout order_status is applied in memory (never persisted
 * from a read path), and the view is rebuilt from the currently installed
 * WooCommerce gateways because gateways can be installed/uninstalled at any
 * time.
 */
class Payment_Gateways_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'payment_gateways';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'default_gateway' => 'pos_cash',
			'gateways'        => array(
				'pos_cash' => array(
					'order'        => 0,
					'enabled'      => true,
					'order_status' => 'wc-completed',
				),
				'pos_card' => array(
					'order'        => 1,
					'enabled'      => true,
					'order_status' => 'wc-completed',
				),
			),
		);
	}

	/**
	 * REST endpoint args for payment-gateway updates.
	 */
	public function endpoint_args(): array {
		return array(
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_gateway' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'gateways' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
		);
	}

	/**
	 * Read the gateway settings view. Pure — no DB writes.
	 */
	public function read(): array {
		// Note: I need to re-init the gateways here to pass the tests, but it seems to work fine in the app.
		WC_Payment_Gateways::instance()->init();
		$installed_gateways = WC_Payment_Gateways::instance()->payment_gateways();
		$raw_gw_option      = $this->read_raw();
		$gateways_settings  = array_replace_recursive( $this->defaults(), $raw_gw_option );

		// Migrate: if the old global checkout order_status exists, apply it to all
		// gateways that have no explicit per-gateway status. In memory only —
		// the legacy key stays in the checkout option until the user saves.
		$checkout_settings = get_option( self::DB_PREFIX . 'checkout', array() );
		if ( \is_array( $checkout_settings ) && isset( $checkout_settings['order_status'] ) ) {
			$global_status = $checkout_settings['order_status'];
			if ( \is_string( $global_status ) && '' !== $global_status ) {
				foreach ( $gateways_settings['gateways'] as $gw_id => &$gw_data ) {
					// Check the raw DB value, not the merged value (which includes defaults).
					if ( ! isset( $raw_gw_option['gateways'][ $gw_id ]['order_status'] ) ) {
						$gw_data['order_status'] = $global_status;
					}
				}
				unset( $gw_data );
			}
		}

		// NOTE - gateways can be installed and uninstalled, so we need to assume the settings data is stale.
		$response = array(
			'default_gateway' => $gateways_settings['default_gateway'],
			'gateways'        => array(),
		);

		// Gateways that represent deferred/unverified payment default to on-hold.
		$on_hold_gateways = array( 'bacs', 'cheque' );

		// loop through installed gateways and merge with saved settings.
		foreach ( $installed_gateways as $id => $gateway ) {
			// sanity check for gateway class.
			if ( ! is_a( $gateway, 'WC_Payment_Gateway' ) || 'pre_install_woocommerce_payments_promotion' === $id ) {
				continue;
			}

			$default_status = \in_array( $id, $on_hold_gateways, true ) ? 'wc-on-hold' : 'wc-completed';

			$response['gateways'][ $id ] = array_replace_recursive(
				array(
					'id'           => $gateway->id,
					'title'        => $gateway->title,
					'description'  => $gateway->description,
					'enabled'      => false,
					'order'        => 999,
					'order_status' => $default_status,
				),
				$gateways_settings['gateways'][ $id ] ?? array()
			);
		}

		/**
		 * Filters the payment gateways settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings The payment gateways settings.
		 *
		 * @hook woocommerce_pos_payment_gateways_settings
		 */
		return apply_filters( 'woocommerce_pos_payment_gateways_settings', $response );
	}
}
