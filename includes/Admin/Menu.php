<?php
/**
 * WP Admin Menu Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

use const HOUR_IN_SECONDS;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 *
 */
class Menu {
	/**
	 * Unique top level menu identifier.
	 *
	 * @var string
	 */
	public $toplevel_screen_id;

	/**
	 * Unique top level menu identifier.
	 *
	 * @var string
	 */
	public $settings_screen_id;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( current_user_can( 'manage_woocommerce_pos' ) ) {
			$this->register_pos_admin();
			add_filter( 'custom_menu_order', '__return_true' );
			add_filter( 'menu_order', array( $this, 'menu_order' ), 9, 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_landing_scripts_and_styles' ) );
		}

		// add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'analytics_menu_items' ) );
	}

	/**
	 * Filters the order of administration menu items.
	 *
	 * A truthy value must first be passed to the {@see 'custom_menu_order'} filter
	 * for this filter to work. Use the following to enable custom menu ordering:
	 *
	 *     add_filter( 'custom_menu_order', '__return_true' );
	 *
	 * @param array $menu_order An ordered array of menu items.
	 *
	 * @return array
	 */
	public function menu_order( array $menu_order ): array {
		$woo = array_search( 'woocommerce', $menu_order, true );
		$pos = array_search( PLUGIN_NAME, $menu_order, true );

		if ( false !== $woo && false !== $pos ) {
			// rearrange menu
			unset( $menu_order[ $pos ] );
			array_splice( $menu_order, ++$woo, 0, PLUGIN_NAME );

			// rearrange submenu
			global $submenu;
			$pos_submenu      = &$submenu[ PLUGIN_NAME ];
			$pos_submenu[500] = $pos_submenu[1];
			unset( $pos_submenu[1] );
		}

		return $menu_order;
	}

	/**
	 * Render the upgrade page.
	 */
	public function display_upgrade_page(): void {
		include_once 'templates/upgrade.php';
	}

	/**
	 * Add POS submenu to WooCommerce Analytics menu.
	 */
	public function analytics_menu_items( array $report_pages ): array {
		// Find the position of the 'Orders' item.
		$position = array_search( 'Orders', array_column( $report_pages, 'title' ), true );

		// Use array_splice to add the new item.
		array_splice(
			$report_pages,
			$position + 1,
			0,
			array(
				array(
					'id'       => 'woocommerce-analytics-pos',
					'title'    => __( 'POS', 'woocommerce-pos' ),
					'parent'   => 'woocommerce-analytics',
					'path'     => '/analytics/pos',
					'nav_args' => array(
						'order'  => 45,
						'parent' => 'woocommerce-analytics',
					),
				),
			)
		);

		return $report_pages;
	}

	/**
	 * Add POS to Admin sidebar.
	 */
	private function register_pos_admin(): void {
		$this->toplevel_screen_id = add_menu_page(
			__( 'POS', 'woocommerce-pos' ),
			__( 'POS', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME,
			array( $this, 'display_upgrade_page' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjYwIDEyNjAiPgo8cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNMTE3MCwwaC05MEg5MDBINzIwSDU0MEgzNjBIMTgwSDkwQzMwLDAsMCwzMCwwLDkwdjE4MGMwLDQ5LjcsNDAuMyw5MCw5MCw5MHM5MC00MC4zLDkwLTkwVjkwaDE4MHYxODAKCWMwLDQ5LjcsNDAuMyw5MCw5MCw5MHM5MC00MC4zLDkwLTkwVjkwaDE4MHYxODBjMCw0OS43LDQwLjMsOTAsOTAsOTBzOTAtNDAuMyw5MC05MFY5MGgxODB2MTgwYzAsNDkuNyw0MC4zLDkwLDkwLDkwczkwLTQwLjMsOTAtOTAKCVY5MEMxMjYwLDMwLDEyMzAsMCwxMTcwLDB6Ii8+CjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0xMDgwLDM2MGMtNDUsNDUtMTM1LDQ1LTE4MCwwYy00NSw0NS0xMzUsNDUtMTgwLDBjLTQ1LDQ1LTEzNSw0NS0xODAsMGMtNDUsNDUtMTM1LDQ1LTE4MCwwYy00NSw0NS0xMzUsNDUtMTgwLDAKCWMtNDUsNDUtMTM1LDQ1LTE4MCwwdjkwMGwzNjAtMjcwaDgxMGM2MCwwLDkwLTMwLDkwLTkwVjM2MEMxMjE1LDQwNSwxMTI1LDQwNSwxMDgwLDM2MHogTTI2MC41LDgyOGMtMzUuNSwwLTY4LjUtMTEuMy05NS41LTMwLjQKCXYxMjUuOWMwLDE5LjMtMTUuNywzNS0zNSwzNXMtMzUtMTUuNy0zNS0zNVY1MzJjMC0xOS4zLDE1LjctMzUsMzUtMzVjMTcuOCwwLDMyLjYsMTMuMywzNC43LDMwLjZjMjcuMS0xOS4zLDYwLjEtMzAuNiw5NS44LTMwLjYKCWM5MS4zLDAsMTY1LjUsNzQuMiwxNjUuNSwxNjUuNVMzNTEuOCw4MjgsMjYwLjUsODI4eiBNNjMwLDgyOGMtOTEuNSwwLTE2Ni03NC41LTE2Ni0xNjZjMC05MS41LDc0LjUtMTY2LDE2Ni0xNjYKCWM5MS41LDAsMTY2LDc0LjUsMTY2LDE2NkM3OTYsNzUzLjUsNzIxLjUsODI4LDYzMCw4Mjh6IE05MTguMyw2MTMuOWMxMS41LDUuOCwzNS4xLDEyLjYsODIuMiwxMi42YzQ5LjUsMCw4Ni42LDYuNSwxMTMuNSwyMAoJYzMzLjUsMTYuOCw1Miw0NS4zLDUyLDgwLjJzLTE4LjUsNjMuNS01Miw4MC4yYy0yNi45LDEzLjUtNjQuMSwyMC0xMTMuNSwyMEM5NDYsODI3LDg5Niw4MTMuNiw4NTIsNzg3LjJjLTE2LjYtOS45LTIyLTMxLjQtMTItNDgKCWM5LjktMTYuNiwzMS40LTIyLDQ4LTEyYzMzLDE5LjgsNzAuOCwyOS44LDExMi41LDI5LjhjNDcuMSwwLDcwLjctNi45LDgyLjItMTIuNmM3LjMtMy42LDEzLjMtNy41LDEzLjMtMTcuNnMtNi0xNC0xMy4zLTE3LjYKCWMtMTEuNS01LjgtMzUuMS0xMi42LTgyLjItMTIuNmMtNDkuNSwwLTg2LjYtNi41LTExMy41LTIwYy0zMy41LTE2LjgtNTItNDUuMy01Mi04MC4yYzAtMzUsMTguNS02My41LDUyLTgwLjIKCWMyNi45LTEzLjUsNjQuMS0yMCwxMTMuNS0yMGM1NC41LDAsMTA0LjUsMTMuNCwxNDguNSwzOS44YzE2LjYsOS45LDIyLDMxLjQsMTIsNDhjLTkuOSwxNi42LTMxLjQsMjEuOS00OCwxMgoJYy0zMy0xOS44LTcwLjgtMjkuOC0xMTIuNS0yOS44Yy00Ny4xLDAtNzAuNyw2LjktODIuMiwxMi42Yy03LjMsMy42LTEzLjMsNy41LTEzLjMsMTcuNlM5MTEsNjEwLjMsOTE4LjMsNjEzLjl6Ii8+CjxjaXJjbGUgZmlsbD0iI2E3YWFhZCIgY3g9IjYzMCIgY3k9IjY2MiIgcj0iOTYiLz4KPGNpcmNsZSBmaWxsPSIjYTdhYWFkIiBjeD0iMjYwLjUiIGN5PSI2NjIuNSIgcj0iOTUuNSIvPgo8L3N2Zz4K'
		);

		add_submenu_page(
			PLUGIN_NAME,
			__( 'View POS', 'woocommerce-pos' ),
			__( 'View POS', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-view-pos',
		);

		$this->settings_screen_id = add_submenu_page(
			PLUGIN_NAME,
			// translators: wordpress
			__( 'Settings' ),
			// translators: wordpress
			__( 'Settings' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-settings',
			array( '\WCPOS\WooCommercePOS\Admin\Settings', 'display_settings_page' )
		);

		// adjust submenu
		global $submenu;
		$pos_submenu       = &$submenu[ PLUGIN_NAME ];
		$pos_submenu[0][0] = __( 'Upgrade to Pro', 'woocommerce-pos' );
		$pos_submenu[1][2] = woocommerce_pos_url();

		/*
		 * Fires after POS admin menus are registered.
		 *
		 * The array arguments, `$this->toplevel_screen_id` and
		 * `$this->settings_screen_id`, refers to the top-level POS menu ID and
		 * settings submenu ID respectively.
		 *
		 * @since 1.0.0
		 *
		 * @param array $menus {
		 *     An array of admin menu IDs.
		 *
		 *     @type string $toplevel The top-level POS menu ID.
		 *     @type string $settings The settings submenu ID.
		 * }
		 */
		do_action(
			'woocommerce_pos_register_pos_admin',
			array(
				'toplevel' => $this->toplevel_screen_id,
				'settings' => $this->settings_screen_id,
			)
		);
	}

	/**
	 * Enqueue landing page scripts and styles.
	 */
	public function enqueue_landing_scripts_and_styles( $hook_suffix ): void {
		if ( $hook_suffix === $this->toplevel_screen_id ) {
			$is_development = isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'];
			$url            = $is_development ? 'http://localhost:9000/' : 'https://cdn.jsdelivr.net/gh/wcpos/wp-admin-landing/assets/';

			// Enqueue the landing page CSS from CDN
			wp_enqueue_style(
				'wcpos-landing',
				$url . 'css/landing.css',
				array(),
				PLUGIN_VERSION
			);

			// Ensure WordPress bundled React and lodash are loaded as dependencies
			wp_enqueue_script( 'react' );
			wp_enqueue_script( 'lodash' );

			// Enqueue the landing page JS from CDN, with React and lodash as dependencies
			wp_enqueue_script(
				'wcpos-landing',
				$url . 'js/landing.js',
				array(
					'react',
					'react-dom',
					'wp-element',
					'lodash',
				),
				PLUGIN_VERSION,
				true
			);
		}
	}
}
