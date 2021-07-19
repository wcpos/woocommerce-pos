<?php

/**
 * Admin Notices
 * - add notices via static method or filter
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

	/* @var string The settings screen id */
	protected $screen_id;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	/**
	 * Add Settings page to admin menu
	 */
	public function admin_menu() {
		$this->screen_id = add_submenu_page(
			PLUGIN_NAME,
			/* translators: wordpress */
			__( 'Settings' ),
			/* translators: wordpress */
			__( 'Settings' ),
			'manage_woocommerce_pos',
			SHORT_NAME . '_settings',
			array( $this, 'display_settings_page' )
		);

		add_action( 'load-' . $this->screen_id, array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Output the settings pages
	 */
	public function display_settings_page() {
		echo '<div id="' . PLUGIN_NAME . '-settings-container"></div>';
	}

	/**
	 *
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( PLUGIN_NAME . '-bundle', PLUGIN_URL . 'admin-client/js/bundle.js', array(
			'react',
			'react-dom'
		), VERSION, true );
	}
}
