<?php

/**
 * Activation checks and set up
 *
 * @package   WCPOS\WooCommercePOS\Activator
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use const DOING_AJAX;

class Activator {

	// minimum requirements
	const WC_MIN_VERSION = '2.3.7';
	const PHP_MIN_VERSION = '5.6';

	/**
	 *
	 */
	public function __construct() {
		register_activation_hook( PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );

	}

	/**
	 * Checks for valid install and begins execution of the plugin.
	 */
	public function init() {
		// Check for min requirements to run
		if ( $this->php_check() && $this->woocommerce_check() ) {

			// check permalinks
			if ( is_admin() && ( ! defined( '\DOING_AJAX' ) || ! DOING_AJAX ) ) {
				$this->permalink_check();
			}

			// Init update script if required
			$this->version_check();

			// resolve plugin plugins
			$this->plugin_check();

			new Init();
		}
	}

	/**
	 * Check min version of PHP
	 */
	private function php_check() {
		$php_version = phpversion();
		if ( version_compare( $php_version, self::PHP_MIN_VERSION, '>' ) ) {
			return true;
		}

		$message = sprintf(
            __( '<strong>WooCommerce POS</strong> requires PHP %1$s or higher. Read more information about <a href="%2$s">how you can update</a>', 'woocommerce-pos' ),
            self::PHP_MIN_VERSION,
            'http://www.wpupdatephp.com/update/'
        ) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * Check min version of WooCommerce installed
	 */
	private function woocommerce_check() {
		if ( class_exists( '\WooCommerce' ) && version_compare( WC()->version, self::WC_MIN_VERSION, '>=' ) ) {
			return true;
		}

		$message = sprintf(
            __( '<strong>WooCommerce POS</strong> requires <a href="%1$s">WooCommerce %2$s or higher</a>. Please <a href="%3$s">install and activate WooCommerce</a>', 'woocommerce-pos' ),
            'http://wordpress.org/plugins/woocommerce/',
            self::WC_MIN_VERSION,
            admin_url( 'plugins.php' )
        ) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * POS Frontend will give 404 if pretty permalinks not active
	 * - requires autoloader, ie: WCPOS()
	 */
	private function permalink_check() {
		$fail = Status::permalinks_disabled();
		if ( $fail ) {
			$message = $fail['message'] . '. ';
			$message .= sprintf( '<a href="%s">%s</a>', $fail['buttons'][0]['href'], $fail['buttons'][0]['prompt'] ) . ' &raquo;';

			Admin\Notices::add( $message );
		}
	}

	/**
	 * Check version number, runs every admin page load
	 */
	private function version_check() {
		//      $old = Admin\Settings::get_db_version();
		//      if ( version_compare( $old, VERSION, '<' ) ) {
		//          Admin\Settings::bump_versions();
		//          $this->db_upgrade( $old, VERSION );
		//      }
	}

	/**
	 * Plugin conflicts
	 *
	 * - NextGEN Gallery is a terrible plugin. It buffers all content on 'init' action, priority -1 and inserts junk code.
	 *
	 */
	private function plugin_check() {

		// disable NextGEN Gallery resource manager
		if ( ! defined( 'NGG_DISABLE_RESOURCE_MANAGER' ) ) {
			define( 'NGG_DISABLE_RESOURCE_MANAGER', true );
		}

	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param $network_wide
	 */
	public function activate( $network_wide ) {
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
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted
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
	 * Fired when the plugin is activated.
	 */
	public function single_activate() {

		// create POS specific roles
		$this->create_pos_roles();

		// add pos capabilities to non POS roles
		$this->add_pos_capability( array(
			'administrator' => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
			'shop_manager'  => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
		) );

		// set the auto redirection on next page load
		//set_transient( 'woocommere_pos_welcome', 1, 30 );
	}

	/**
	 * add POS specific roles
	 */
	private function create_pos_roles() {

		// Cashier role
		$cashier_capabilities = array(
			'read'                      => true,
			'read_private_products'     => true,
			'read_private_shop_orders'  => true,
			'publish_shop_orders'       => true,
			'list_users'                => true,
			'read_private_shop_coupons' => true,
		);

		add_role(
			'cashier',
			__( 'Cashier', 'woocommerce-pos' ),
			$cashier_capabilities
		);

		$this->add_pos_capability( array(
			'cashier' => array( 'access_woocommerce_pos' ),
		) );

	}

	/**
	 * add default pos capabilities to administrator and
	 * shop_manager roles
	 *
	 * @param $roles - an array of arrays representing the roles and their POS capabilities
	 */
	private function add_pos_capability( $roles ) {

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
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param $blog_id
	 */
	public function activate_new_site( $blog_id ) {

		if ( 1 !== did_action( 'wpmu_new_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		$this->single_activate();
		restore_current_blog();

	}

	/**
	 * Upgrade database
	 *
	 * @param $old
	 * @param $current
	 */
	private function db_upgrade( $old, $current ) {
		$db_updates = array(
			'0.4'        => 'updates/update-0.4.php',
			'0.4.6'      => 'updates/update-0.4.6.php',
			'0.5.0-beta' => 'updates/update-0.5.php',
			'0.5.0'      => 'updates/update-0.5.php',
		);
		foreach ( $db_updates as $version => $updater ) {
			if ( version_compare( $version, $old, '>' ) &&
			     version_compare( $version, $current, '<=' ) ) {
				include( $updater );
			}
		}
	}

}
