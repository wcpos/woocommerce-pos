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

    /**
     * @var
     */
    protected $settings_service;

	public function __construct() {
        $this->settings_service = new \WCPOS\WooCommercePOS\Services\Settings();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Output the settings pages.
	 */
	public static function display_settings_page(): void {
		printf('<div id="woocommerce-pos-settings">
			<div id="woocommerce-pos-js-error" class="wrap">
				<h1>%s</h1>
				<p>%s <a href="mailto:support@wcpos.com">support@wcpos.com</a></p>
			</div>
		</div>',
            __( 'Error', 'woocommerce-pos' ),
            __( 'Settings failed to load, please contact support', 'woocommerce-pos' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * Note: SCRIPT_DEBUG should be set in the wp-config.php file for debugging
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$is_development = isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'];
		$dir = $is_development ? 'build' : 'assets';

		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . $dir . '/css/settings.css',
			array(
				'wp-components',
			),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-transifex',
			'https://cdn.jsdelivr.net/npm/@transifex/native/dist/browser.native.min.js',
			array(),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-settings',
			PLUGIN_URL . $dir . '/js/settings.js',
			array(
				'react',
				'react-dom',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
				'lodash',
				PLUGIN_NAME . '-transifex',
			),
			VERSION,
			true
		);

        // Add inline script
        wp_add_inline_script( PLUGIN_NAME . '-settings', $this->inline_script(), 'before' );

        if ( $is_development ) {
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
     * @return string
     */
    private function inline_script(): string {
        $barcodes = array_values( $this->settings_service->get_barcodes() );
        $order_statuses = $this->settings_service->get_order_statuses();
        return sprintf( 'var wcpos = wcpos || {}; wcpos.settings = {
            barcodes: %s,
            order_statuses: %s
        }', json_encode( $barcodes ), json_encode( $order_statuses ) );
    }
}
