<?php

/**
 * WP Admin Class
 * conditionally loads classes for WP Admin.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 * @package WCPOS\Admin
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
use WCPOS\WooCommercePOS\Admin\Updaters\Pro_Plugin_Updater;

/**
 * Admin class.
 */
class Admin {
	/**
	 * POS Menu IDs.
	 *
	 * @var string[] Unique menu identifier.
	 */
	private $menu_ids = array();

	/**
	 * Registered screen handlers.
	 *
	 * @var array
	 */
	private $screen_handlers = array();

	/**
	 * Constructor.
	 *
	 * NOTE: WordPress fires the admin_menu hook before the admin_init.
	 * 1. admin_menu
	 * 2. admin_init
	 * 3. current_screen
	 *
	 * We need admin_menu at priority 5 so that we can hook the Analytics menu before WooCommerce.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'current_screen', array( $this, 'current_screen' ) );

		// register the screen handlers.
		$this->screen_handlers = array(
			'options-permalink' => Permalink::class,
			'product' => Single_Product::class,
			'edit-product' => List_Products::class,
			'shop_order' => Single_Order::class,
			'edit-shop_order' => List_Orders::class,
			'plugins' => Plugins::class,
			'woocommerce_page_wc-orders' => array( $this, 'handle_wc_hpos_orders_screen' ),
			'woocommerce_page_wc-admin' => array( $this, 'handle_wc_analytics_screen' ),
		);
	}

	/**
	 * Load admin subclasses.
	 */
	public function init(): void {
		new Notices();
		new Pro_Plugin_Updater();
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

		/**
		 * Add the settings screen using the WooCommerce POS menu ID.
		 */
		$this->screen_handlers[ $this->menu_ids['settings'] ] = Settings::class;
	}

	/**
	 * Conditionally load subclasses based on admin screen.
	 *
	 * @TODO - I need to register the instances to allow remove_action/remove_filter.
	 *
	 * @param \WP_Screen $current_screen Current screen object.
	 */
	public function current_screen( $current_screen ): void {
		/**
		 * Backwards compatibility for WooCommerce POS Pro 1.4.2 and below.
		 * DO NOT USE THIS!
		 *
		 * @TODO: Remove in WooCommerce POS 2.0.0.
		 */
		$this->screen_handlers['product'] = apply_filters( 'woocommerce_pos_single_product_admin_class', Single_Product::class );

		/**
		 * Filters the screen handlers for WooCommerce POS admin screens.
		 *
		 * @hook woocommerce_pos_admin_screen_handlers
		 *
		 * @since 1.4.10
		 *
		 * @param array       $handlers       Associative array of screen IDs and their corresponding handlers.
		 *                                    Handler can be a class name or a callback array.
		 * @param \WP_Screen  $current_screen The current WP_Screen object being loaded in the admin.
		 */
		$handlers = apply_filters( 'woocommerce_pos_admin_screen_handlers', $this->screen_handlers, $current_screen );

		// Check if the current screen has a handler.
		if ( isset( $handlers[ $current_screen->id ] ) ) {
			$handler = $handlers[ $current_screen->id ];

			if ( is_array( $handler ) && method_exists( $handler[0], $handler[1] ) ) {
				call_user_func( $handler );
			} elseif ( class_exists( $handler ) ) {
				new $handler();
			}
		}
	}

	/**
	 *
	 */
	public function handle_wc_hpos_orders_screen() {
		if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			new HPOS_Single_Order();
		} else {
			new HPOS_List_Orders();
		}
	}

	/**
	 *
	 */
	public function handle_wc_analytics_screen() {
		if ( class_exists( '\Automattic\WooCommerce\Admin\PageController' ) ) {
			$wc_admin_page_controller = PageController::get_instance();
			if ( $wc_admin_page_controller ) {
				$wc_admin_current_page    = $wc_admin_page_controller->get_current_page();
				$id                       = $wc_admin_current_page['id'] ?? null;
				$parent                   = $wc_admin_current_page['parent'] ?? null;

				if ( 'woocommerce-analytics' === $id || 'woocommerce-analytics' === $parent ) {
					new Analytics();
				}
			}
		}
	}
}
