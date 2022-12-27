<?php

/**
 * POS Settings.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION;

class Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	/**
	 * Add Settings page to admin menu.
	 */
	public function admin_menu(): void {
		$page_hook_suffix = add_submenu_page(
			PLUGIN_NAME,
			// translators: wordpress
			__( 'Settings' ),
			// translators: wordpress
			__( 'Settings' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-settings',
			array( $this, 'display_settings_page' )
		);

		add_action( "load-{$page_hook_suffix}", array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Output the settings pages.
	 */
	public function display_settings_page(): void {
		echo '<div id="' . PLUGIN_NAME . '-settings">
			<div id="' . PLUGIN_NAME . '-js-error" class="wrap">
				<h1>' . __( 'Error', 'woocommerce-pos' ) . '</h1>
				<p>' . __( 'Settings failed to load, please contact support' ) . ' <a href="mailto:support@wcpos.com">support@wcpos.com</a></p>
			</div>
		</div>';
	}


	public function enqueue_assets() {
		if ( isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'] ) {
			return $this->enqueue_development_assets();
		}

		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . 'assets/css/settings.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-react-query',
			'https://unpkg.com/@tanstack/react-query@4/build/umd/index.production.js',
			array(
				'react',
			),
			VERSION,
			true
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-transifex',
			'https://cdn.jsdelivr.net/npm/@transifex/native/dist/browser.native.min.js',
			array(),
			VERSION,
			true
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-settings',
			PLUGIN_URL . 'assets/js/settings.js',
			array(
				'react',
				PLUGIN_NAME . '-react-query',
				'react-dom',
				'lodash',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
				PLUGIN_NAME . '-transifex'
			),
			VERSION,
			true
		);

		do_action( 'woocommerce_pos_admin_settings_enqueue_assets' );
	}

	/**
	 * Note: SCRIPT_DEBUG should be set in the wp-config.php file for debugging
	 * We also remove react-query from the dependencies so we can use the unminified version.
	 */
	public function enqueue_development_assets(): void {
		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . 'build/css/settings.css',
			array(),
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
				'wp-i18n',
				'wp-api-fetch',
			),
			VERSION,
			true
		);

		wp_enqueue_script(
			'webpack-live-reload',
			'http://localhost:35729/livereload.js',
			null,
			null,
			true
		);

		do_action( 'woocommerce_pos_admin_settings_enqueue_assets' );
	}

}
