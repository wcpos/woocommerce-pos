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
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

class Settings {
	/* @var string The db prefix for WP Options table */
	const DB_PREFIX = 'woocommerce_pos_settings_';

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

		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . 'build/css/settings.css',
			array( 'wp-components' ),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-settings',
			PLUGIN_URL . 'build/js/settings.js',
			array(
				'react',
				'react-dom',
				'lodash',
				'wp-element',
				'wp-components',
				'wp-i18n',
				'wp-api-fetch',
			),
			VERSION,
			true
		);
		wp_add_inline_script( PLUGIN_NAME . '-settings', $this->stringify_settings(), 'before' );

		if ( $development ) {
			wp_enqueue_script(
				'webpack-live-reload',
				'http://localhost:35729/livereload.js',
				null,
				null,
				true
			);
		}

	}

	/**
	 *
	 */
	public function stringify_settings() {
		$settings = new \WCPOS\WooCommercePOS\API\Settings();
		$payload  = array(
			'settings'       => $settings->get_all_settings(),
			'barcode_fields' => $settings->get_barcode_fields(),
			'order_statuses' => wc_get_order_statuses(),
			'gateways'       => $settings->get_gateways(),
		);

		return 'var wcpos = ' . wp_json_encode( $payload ) . ';';
	}
}
