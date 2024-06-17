<?php

/**
 * Activation checks and set up.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use const DOING_AJAX;

/**
 *
 */
class Activator {
	public function __construct() {
		register_activation_hook( PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Checks for valid install and begins execution of the plugin.
	 */
	public function init(): void {
		// Check for min requirements to run
		if ( $this->php_check() && $this->woocommerce_check() ) {
			// check permalinks
			if ( is_admin() && ( ! defined( '\DOING_AJAX' ) || ! DOING_AJAX ) ) {
				$this->permalink_check();
			}

			// Init update script if required
			$this->version_check();
			$this->pro_version_check();

			// resolve plugin plugins
			$this->plugin_check();

			new Init();
		}
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param $network_wide
	 */
	public function activate( $network_wide ): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_wide ) {
				// Get all blog ids
				$blog_ids = $this->get_blog_ids();

				foreach ( $blog_ids as $blog_id ) {
					switch_to_blog( $blog_id );
					$this->single_activate();

					restore_current_blog();
				}
			} else {
				self::single_activate();
			}
		} else {
			self::single_activate();
		}
	}

	/**
	 * Fired when the plugin is activated.
	 */
	public function single_activate(): void {
		// create POS specific roles
		$this->create_pos_roles();

		// add pos capabilities to non POS roles
		$this->add_pos_capability(
			array(
				'administrator' => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
				'shop_manager'  => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
			)
		);

		// set the auto redirection on next page load
		// set_transient( 'woocommere_pos_welcome', 1, 30 );
	}

	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param $blog_id
	 */
	public function activate_new_site( $blog_id ): void {
		if ( 1 !== did_action( 'wpmu_new_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		$this->single_activate();
		restore_current_blog();
	}

	/**
	 * Check min version of PHP.
	 */
	private function php_check() {
		$php_version = PHP_VERSION;
		if ( version_compare( $php_version, PHP_MIN_VERSION, '>' ) ) {
			return true;
		}

		$message = sprintf(
			__( '<strong>WooCommerce POS</strong> requires PHP %1$s or higher. Read more information about <a href="%2$s">how you can update</a>', 'woocommerce-pos' ),
			PHP_MIN_VERSION,
			'http://www.wpupdatephp.com/update/'
		) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * Check min version of WooCommerce installed.
	 */
	private function woocommerce_check() {
		if ( class_exists( '\WooCommerce' ) && version_compare( WC()->version, WC_MIN_VERSION, '>=' ) ) {
			return true;
		}

		$message = sprintf(
			__( '<strong>WooCommerce POS</strong> requires <a href="%1$s">WooCommerce %2$s or higher</a>. Please <a href="%3$s">install and activate WooCommerce</a>', 'woocommerce-pos' ),
			'http://wordpress.org/plugins/woocommerce/',
			WC_MIN_VERSION,
			admin_url( 'plugins.php' )
		) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * POS Frontend will give 404 if pretty permalinks not active.
	 */
	private function permalink_check(): void {
		$permalinks = get_option( 'permalink_structure' );

		// early return
		if ( $permalinks ) {
			return;
		}

		$message = __( '<strong>WooCommerce REST API</strong> requires <em>pretty</em> permalinks to work correctly', 'woocommerce-pos' ) . '. ';
		$message .= sprintf( '<a href="%s">%s</a>', admin_url( 'options-permalink.php' ), __( 'Enable permalinks', 'woocommerce-pos' ) ) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * Check version number, runs every admin page load.
	 */
	private function version_check(): void {
		$old = Services\Settings::get_db_version();
		if ( version_compare( $old, VERSION, '<' ) ) {
			Services\Settings::bump_versions();
			$this->db_upgrade( $old, VERSION );
		}
	}

	/**
	 * Plugin conflicts.
	 *
	 * - NextGEN Gallery is a terrible plugin. It buffers all content on 'init' action, priority -1 and inserts junk code.
	 */
	private function plugin_check(): void {
		// disable NextGEN Gallery resource manager
		// if ( ! \defined( 'NGG_DISABLE_RESOURCE_MANAGER' ) ) {
		// \define( 'NGG_DISABLE_RESOURCE_MANAGER', true );
		// }
	}

	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted.
	 */
	private function get_blog_ids() {
		global $wpdb;

		// get an array of blog ids
		$sql = "SELECT blog_id FROM $wpdb->blogs
      WHERE archived = '0' AND spam = '0'
      AND deleted = '0'";

		return $wpdb->get_col( $sql );
	}

	/**
	 * add POS specific roles.
	 */
	private function create_pos_roles(): void {
		// Cashier role
		$cashier_capabilities = array(
			'read'                      => true,
			'read_private_products'     => true,
			'read_private_shop_orders'  => true,
			'publish_shop_orders'       => true,
			'list_users'                => true,
			'read_private_shop_coupons' => true,
			'manage_product_terms'      => true,
		);

		add_role(
			'cashier',
			__( 'Cashier', 'woocommerce-pos' ),
			$cashier_capabilities
		);

		$this->add_pos_capability(
			array(
				'cashier' => array( 'access_woocommerce_pos' ),
			)
		);
	}

	/**
	 * add default pos capabilities to administrator and
	 * shop_manager roles.
	 *
	 * @param $roles - an array of arrays representing the roles and their POS capabilities
	 */
	private function add_pos_capability( $roles ): void {
		foreach ( $roles as $slug => $caps ) {
			$role = get_role( $slug );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Upgrade database.
	 *
	 * @param $old
	 * @param $current
	 */
	private function db_upgrade( $old, $current ): void {
		$db_updates = array(
			'0.4'          => 'updates/update-0.4.php',
			'0.4.6'        => 'updates/update-0.4.6.php',
			'1.0.0-beta.1' => 'updates/update-1.0.0-beta.1.php',
			'1.6.1'        => 'updates/update-1.6.1.php',
		);
		foreach ( $db_updates as $version => $updater ) {
			if ( version_compare( $version, $old, '>' ) &&
			 version_compare( $version, $current, '<=' ) ) {
				include $updater;
			}
		}
	}

	/**
	 * If \WCPOS\WooCommercePOSPro\ is installed, check the version is above MIN_PRO_VERSION
	 */
	private function pro_version_check() {
		if ( class_exists( '\WCPOS\WooCommercePOSPro\Activator' ) ) {
			if ( version_compare( \WCPOS\WooCommercePOSPro\VERSION, MIN_PRO_VERSION, '<' ) ) {
				/**
				 * NOTE: the deactivate_plugins function is not available in the frontend or ajax
				 * This is an extreme situation where the Pro plugin could crash the site, so we need to deactivate it
				 */
				if ( ! function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . '/wp-admin/includes/plugin.php';
				}

				// WooCommerce POS Pro is activated, but the version is too low
				deactivate_plugins( 'woocommerce-pos-pro/woocommerce-pos-pro.php' );

				$message = sprintf(
					__( '<strong>WooCommerce POS</strong> requires <a href="%1$s">WooCommerce POS Pro %2$s or higher</a>. Please <a href="%3$s">install and activate WooCommerce POS Pro</a>', 'woocommerce-pos' ),
					'https://wcpos.com/my-account',
					MIN_PRO_VERSION,
					admin_url( 'plugins.php' )
				) . ' &raquo;';

				Admin\Notices::add( $message );
			}
		}
	}
}
