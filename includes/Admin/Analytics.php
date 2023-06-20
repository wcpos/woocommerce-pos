<?php

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION;

class Analytics {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'analytics_css' ) );
	}

	/**
	 * Add CSS to the admin head
	 */
	public function analytics_css() {
		echo '<style>.woocommerce-pos-upgrade-notice { margin: 0 0 24px 0; }</style>';
	}

	/**
	 *
	 */
	public function enqueue_assets() {
		$is_development = isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'];
		$dir = $is_development ? 'build' : 'assets';

		wp_enqueue_script(
			PLUGIN_NAME . '-analytics',
			PLUGIN_URL . $dir . '/js/analytics.js',
			array(
				'wp-hooks',
				'wc-components',
			),
			VERSION,
			true
		);
	}
}
