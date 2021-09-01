<?php

/**
 * POS Settings
 *
 * @package  WCPOS\WooCommercePOS\Admin\Settings
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION;

class Settings {
	/**
	 * Admin and API Settings classes share the same traits
	 */
	use \WCPOS\WooCommercePOS\Traits\Settings;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	/**
	 * Add Settings page to admin menu
	 */
	public function admin_menu() {
		$page_hook_suffix = add_submenu_page(
			PLUGIN_NAME,
			/* translators: wordpress */
			__( 'Settings' ),
			/* translators: wordpress */
			__( 'Settings' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-settings',
			array( $this, 'display_settings_page' )
		);

		add_action( "load-{$page_hook_suffix}", array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Output the settings pages
	 */
	public function display_settings_page() {
		echo '<div id="' . PLUGIN_NAME . '-settings"></div>';
	}

	/**
	 *
	 */
	public function enqueue_assets() {
		$development = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$assets      = $development ? 'build' : 'assets';

		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . $assets . '/css/settings.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-settings',
			PLUGIN_URL . $assets . '/js/settings.js',
			array(
				'react',
				'react-dom',
				'lodash',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
			),
			VERSION,
			true
		);
		wp_add_inline_script( PLUGIN_NAME . '-settings', $this->inline_js(), 'before' );

		if ( $development ) {
			wp_enqueue_script(
				'webpack-live-reload',
				'http://localhost:35729/livereload.js',
				null,
				null,
				true
			);
		}

		do_action( 'woocommerce_pos_admin_settings_enqueue_assets' );
	}

	/**
	 *
	 */
	public function inline_js() {
		$settings            = $this->get_all_settings();
		$default_customer_id = $settings['general']['default_customer'] ?? 0;
		$default_customer    = get_userdata( $default_customer_id );

		$vars = array(
			'homepage'         => home_url(),
			'version'          => VERSION,
			'settings'         => $this->get_all_settings(),
			'barcode_fields'   => $this->get_barcode_fields(),
			'order_statuses'   => wc_get_order_statuses(),
			'default_customer' => array(
				'value' => $default_customer_id,
				'label' => $default_customer ? $default_customer->display_name : 'Guest',
			),
		);

		$vars = apply_filters( 'woocommerce_pos_admin_settings_inline_vars', $vars );

		return 'var wcpos = ' . wp_json_encode( $vars ) . ';';
	}

	/**
	 * @return array
	 */
	public function get_barcode_fields() {
		global $wpdb;

		$result = $wpdb->get_col(
			"
			SELECT DISTINCT(pm.meta_key)
			FROM $wpdb->postmeta AS pm
			JOIN $wpdb->posts AS p
			ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			ORDER BY pm.meta_key
			"
		);

		// maybe add custom barcode field
		$custom_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		if ( ! empty( $custom_field ) ) {
			array_push( $result, $custom_field );
		}

		sort( $result );

		return array_unique( $result );
	}
}
