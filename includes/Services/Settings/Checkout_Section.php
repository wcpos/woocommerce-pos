<?php
/**
 * Checkout Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

/**
 * The Checkout Settings Section: receipt mode, email toggles, and POS
 * checkout-page asset dequeues.
 */
class Checkout_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'checkout';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'receipt_default_mode' => 'fiscal',
			'admin_emails'    => array(
				'enabled'         => true,
				'new_order'       => true,
				'cancelled_order' => true,
				'failed_order'    => true,
			),
			'customer_emails' => array(
				'enabled'                   => true,
				'customer_on_hold_order'    => true,
				'customer_processing_order' => true,
				'customer_completed_order'  => true,
				'customer_refunded_order'   => true,
				'customer_failed_order'     => true,
			),
			'cashier_emails'  => array(
				'enabled'   => false,
				'new_order' => true,
			),
			// this is used in the POS, not in WP Admin (at the moment).
			'dequeue_script_handles' => array(
				'admin-bar',
				'wc-add-to-cart',
				'wc-stripe-upe-classic',
			),
			'dequeue_style_handles' => array(
				'admin-bar',
				'woocommerce-general',
				'woocommerce-inline',
				'woocommerce-layout',
				'woocommerce-smallscreen',
				'woocommerce-blocktheme',
				'wp-block-library',
			),
			'disable_wp_head'   => false,
			'disable_wp_footer' => false,
		);
	}

	/**
	 * Migrate legacy boolean email settings to array format, in memory only.
	 * The stored boolean becomes the `enabled` flag over the default subkeys.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		$defaults = $this->defaults();
		foreach ( array( 'admin_emails', 'customer_emails' ) as $key ) {
			if ( isset( $raw[ $key ] ) && \is_bool( $raw[ $key ] ) ) {
				$migrated            = $defaults[ $key ];
				$migrated['enabled'] = $raw[ $key ];
				$raw[ $key ]         = $migrated;
			}
		}

		return $raw;
	}

	/**
	 * Strip the legacy global order_status key on save — but ONLY once its
	 * seed is reflected in the payment_gateways option. Gateway reads are
	 * pure (they apply the legacy seed in memory without persisting), so
	 * stripping earlier would destroy the seed before any payment-gateways
	 * save persists per-gateway statuses, silently reverting them to
	 * defaults on pre-1.9 sites.
	 *
	 * @param array $settings Settings about to be saved.
	 */
	protected function sanitize( array $settings ): array {
		if ( \array_key_exists( 'order_status', $settings ) ) {
			$gateways_option = get_option( self::DB_PREFIX . 'payment_gateways', array() );
			$seed_reflected  = false;
			if ( \is_array( $gateways_option ) && isset( $gateways_option['gateways'] ) && \is_array( $gateways_option['gateways'] ) ) {
				foreach ( $gateways_option['gateways'] as $gateway ) {
					if ( \is_array( $gateway ) && isset( $gateway['order_status'] ) ) {
						$seed_reflected = true;
						break;
					}
				}
			}
			if ( $seed_reflected ) {
				unset( $settings['order_status'] );
			}
		}

		return $settings;
	}

	/**
	 * REST endpoint args. Moved verbatim from
	 * API\Settings::get_checkout_endpoint_args() (also reused by the
	 * payment-gateways update route, as before).
	 */
	public function endpoint_args(): array {
		return array(
			'receipt_default_mode' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param ) && \in_array( $param, array( 'fiscal', 'live' ), true );
				},
			),
			'admin_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'customer_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'cashier_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
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
}
