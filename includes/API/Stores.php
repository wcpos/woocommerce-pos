<?php

namespace WCPOS\WooCommercePOS\API;

use WC_Admin_Settings;

class Stores extends Controller {
	/**
	 * Stores constructor.
	 */
	public function __construct() {
	}

	
	public function register_routes(): void {
		register_rest_route($this->namespace, '/stores', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stores' ),
			'permission_callback' => '__return_true',
		));
	}

	
	public function get_stores() {
		$data = array(
			$this->get_store(),
		);

		return rest_ensure_response( $data );
	}


	
	public function get_store(): array {
		$general_settings = apply_filters( 'woocommerce_settings-general', array() );
		$tax_settings     = apply_filters( 'woocommerce_settings-tax', array() );
		$settings         = array_merge( $general_settings, $tax_settings );

		$filtered_settings = array();
		$settings_prefix   = 'woocommerce_';
		foreach ( $settings as $setting ) {
			if ( $settings_prefix === substr( $setting['id'], 0, \strlen( $settings_prefix ) ) ) {
				$id = substr( $setting['id'], \strlen( $settings_prefix ) );

				$option_key = $setting['option_key'];
				$default    = $setting['default'] ?? '';
				// Get the option value.
				if ( \is_array( $option_key ) ) {
					$option           = get_option( $option_key[0] );
					$setting['value'] = $option[ $option_key[1] ] ?? $default;
				} else {
					$admin_setting_value = WC_Admin_Settings::get_option( $option_key, $default );
					$setting['value']    = $admin_setting_value;
				}

				$filtered_settings[ $id ] = $setting['value'];
			}
		}

		return array_merge(
			array(
				'id'     => 0,
				'name'   => get_option( 'blogname' ),
				'locale' => get_locale(),
			),
			$filtered_settings
		);
	}
}
