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

class Admin {
	/**
	 * @vars string Unique menu identifier
	 */
	private $menu_ids;

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
	 * Load admin subclasses.
	 */
	private function init(): void {
		new Admin\Notices();
	}

	/**
	 * Fires before the administration menu loads in the admin.
	 */
	public function admin_menu(): void {
		$menu = new Admin\Menu();
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
                new Admin\Permalink();
                break;

            case 'product': // Single product page
                new Admin\Products\Single_Product();
                break;

            case 'edit-product': // List products page
                new Admin\Products\List_Products();
                break;

            case 'shop_order': // Add POS settings to orders pages
            case 'edit-shop_order': // Add POS settings to orders pages
                new Admin\Orders();
                break;

            case 'plugins': // Customise plugins page
                new Admin\Plugins();
                break;

		}

		// Load the Settings class
		if ( isset( $this->menu_ids['settings'] ) && $this->menu_ids['settings'] == $current_screen->id ) {
			new Admin\Settings();
		}

		// Load the Analytics class
		// Note screen->id = woocommerce_page_wc-admin is used in many places and is not unique to the analytics page.
		if ( class_exists( '\Automattic\WooCommerce\Admin\PageController' ) ) {
			$wc_admin_page_controller = PageController::get_instance();
			$wc_admin_current_page = $wc_admin_page_controller->get_current_page();
			if ( is_array( $wc_admin_current_page ) ) {
				if ( $wc_admin_current_page['id'] === 'woocommerce-analytics' || $wc_admin_current_page['parent'] === 'woocommerce-analytics' ) {
					new Admin\Analytics();
				}
			}
		}
	}

}
