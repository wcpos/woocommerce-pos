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
use WCPOS\WooCommercePOS\Admin\Orders\HPOS_List_Orders;
use WCPOS\WooCommercePOS\Admin\Orders\HPOS_Single_Order;
use WCPOS\WooCommercePOS\Admin\Orders\List_Orders;
use WCPOS\WooCommercePOS\Admin\Orders\Single_Order;
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
		$action = $_GET['action'] ?? '';

		// Main switch for screen IDs
		switch ( $current_screen->id ) {
			case 'options-permalink':
				new Permalink();

				return;
			case 'product':
				new Single_Product();

				return;
			case 'edit-product':
				new List_Products();

				return;
			case 'shop_order':
				new List_Orders();

				return;
			case 'edit-shop_order':
				new Single_Order();

				return;
			case 'woocommerce_page_wc-orders':
				if ('edit' === $action) {
					new HPOS_Single_Order();
				} else {
					new HPOS_List_Orders();
				}

				return;
			case 'plugins':
				new Plugins();

				return;
			default:
				// Check if the current screen matches a custom setting page ID
				if ($this->is_woocommerce_pos_setting_page($current_screen)) {
					new Settings();

					return;
				}
				// Check if the current screen is for WooCommerce Analytics
				if ($this->is_woocommerce_analytics($current_screen)) {
					new Analytics();

					return;
				}
		}
	}

	/**
	 * Check if the current screen matches the POS setting page ID.
	 *
	 * @param mixed $current_screen
	 */
	private function is_woocommerce_pos_setting_page($current_screen) {
		return \array_key_exists('settings', $this->menu_ids) && $this->menu_ids['settings'] === $current_screen->id;
	}

	/**
	 * Check if the current screen is for WooCommerce Analytics.
	 *
	 * @param mixed $current_screen
	 */
	private function is_woocommerce_analytics($current_screen) {
		if (class_exists('\Automattic\WooCommerce\Admin\PageController')) {
			$wc_admin_page_controller = PageController::get_instance();
			$wc_admin_current_page    = $wc_admin_page_controller->get_current_page();
			$id                       = $wc_admin_current_page['id']         ?? null;
			$parent                   = $wc_admin_current_page['parent']     ?? null;

			return 'woocommerce-analytics' === $id || 'woocommerce-analytics' === $parent;
		}

		return false;
	}


	/**
	 * Load admin subclasses.
	 */
	private function init(): void {
		new Notices();
	}
}
