<?php

/**
 * WP Admin Class
 * conditionally loads classes for WP Admin.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Automattic\WooCommerce\Admin\PageController;
use WCPOS\WooCommercePOS\Admin\Analytics;
use WCPOS\WooCommercePOS\Admin\Notices;
use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\Admin\Plugins;
use WCPOS\WooCommercePOS\Admin\Products\List_Products;
use WCPOS\WooCommercePOS\Admin\Products\Single_Product;
use WCPOS\WooCommercePOS\Admin\Settings;

class Admin {
	/**
	 * @vars string Unique menu identifier
	 */
	private $menu_ids = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init();
		// NOTE: The admin_menu needs to hook the Analytics menu before WooCommerce calls the admin_menu hook.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 5 );
		add_action( 'current_screen', array( $this, 'current_screen' ) );
	}

	/**
	 * Fires before the administration menu loads in the admin.
	 */
	public function admin_menu(): void {
		$menu           = new Admin\Menu();
		$this->menu_ids = array(
			'toplevel' => $menu->toplevel_screen_id,
			'settings' => $menu->settings_screen_id,
		);
	}

	/**
	 * Conditionally load subclasses.
	 *
	 * @param $current_screen
	 */
	public function current_screen( $current_screen ): void {
		switch ( $current_screen->id ) {
			case 'options-permalink': // Add setting to permalink page
				new Permalink();

				break;

			case 'product': // Single product page
				new Single_Product();

				break;

			case 'edit-product': // List products page
				new List_Products();

				break;

			case 'shop_order': // Add POS settings to orders pages
			case 'edit-shop_order': // Add POS settings to orders pages
				new Orders();

				break;

			case 'plugins': // Customise plugins page
				new Plugins();

				break;

		}

		// Load the Settings class
		if ( \array_key_exists( 'settings', $this->menu_ids ) && $this->menu_ids['settings'] == $current_screen->id ) {
			new Settings();
		}

		// Load the Analytics class
		// Note screen->id = woocommerce_page_wc-admin is used in many places and is not unique to the analytics page.
		if ( class_exists( '\Automattic\WooCommerce\Admin\PageController' ) ) {
			$wc_admin_page_controller = PageController::get_instance();
			$wc_admin_current_page    = $wc_admin_page_controller->get_current_page();
			
			if ( \is_array( $wc_admin_current_page ) ) {
				$id     = $wc_admin_current_page['id']     ?? null;
				$parent = $wc_admin_current_page['parent'] ?? null;
					
				if ( 'woocommerce-analytics' === $id || 'woocommerce-analytics' === $parent ) {
					new Analytics();
				}
			}
		}
	}

	/**
	 * Load admin subclasses.
	 */
	private function init(): void {
		new Notices();
	}
}
