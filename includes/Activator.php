<?php
/**
 * Activation checks and set up.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use const DOING_AJAX;

/**
 * Activator class.
 */
class Activator {
	/**
	 * Option key used as a short-lived migration lock.
	 */
	private const DB_UPGRADE_LOCK_OPTION = 'woocommerce_pos_db_upgrade_lock';

	/**
	 * Lock TTL in seconds.
	 */
	private const DB_UPGRADE_LOCK_TTL = 600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		register_activation_hook( PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Checks for valid install and begins execution of the plugin.
	 */
	public function init(): void {
		// Check for min requirements to run.
		if ( $this->php_check() && $this->woocommerce_check() ) {
			// Defer permalink check to admin_init so __() calls happen after
			// after_setup_theme (WordPress 6.7+ triggers a notice otherwise).
			if ( is_admin() && ( ! \defined( '\DOING_AJAX' ) || ! DOING_AJAX ) ) { // @phpstan-ignore-line
				add_action(
					'admin_init',
					function () {
						$this->permalink_check();
					}
				);
			}

			// Init update script if required.
			$this->version_check();
			$this->pro_version_check();

			// resolve plugin plugins.
			$this->plugin_check();

			new Init();
		}
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param bool $network_wide Whether to activate network-wide.
	 */
	public function activate( $network_wide ): void {
		if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_wide ) {
				// Get all blog ids.
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
		// create POS specific roles.
		$this->create_pos_roles();

		// add pos capabilities to non POS roles.
		$this->add_pos_capability(
			array(
				'administrator' => array(
					'manage_woocommerce_pos',
					'access_woocommerce_pos',
					'edit_wcpos_store',
					'read_wcpos_store',
					'delete_wcpos_store',
					'edit_wcpos_stores',
					'edit_others_wcpos_stores',
					'publish_wcpos_stores',
					'read_private_wcpos_stores',
					'delete_wcpos_stores',
					'delete_private_wcpos_stores',
					'delete_published_wcpos_stores',
					'delete_others_wcpos_stores',
					'edit_private_wcpos_stores',
					'edit_published_wcpos_stores',
				),
				'shop_manager'  => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
			)
		);

		// set the auto redirection on next page load
		// set_transient( 'woocommere_pos_welcome', 1, 30 );.
	}

	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param int $blog_id Blog ID.
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

		// Defer __() call to avoid "too early" warning in WordPress 6.7+.
		add_action(
			'admin_init',
			function () {
				$message = \sprintf(
					// translators: 1: Minimum PHP version, 2: Update URL.
					__( '<strong>WCPOS</strong> requires PHP %1$s or higher. Read more information about <a href="%2$s">how you can update</a>', 'woocommerce-pos' ),
					PHP_MIN_VERSION,
					'http://www.wpupdatephp.com/update/'
				) . ' &raquo;';

				Admin\Notices::add( $message );
			}
		);
	}

	/**
	 * Check min version of WooCommerce installed.
	 */
	private function woocommerce_check() {
		if ( class_exists( '\WooCommerce' ) && version_compare( WC()->version, WC_MIN_VERSION, '>=' ) ) {
			return true;
		}

		// Defer __() call to avoid "too early" warning in WordPress 6.7+.
		add_action(
			'admin_init',
			function () {
				$message = \sprintf(
					// translators: 1: WooCommerce URL, 2: Minimum WC version, 3: Plugins URL.
					__( '<strong>WCPOS</strong> requires <a href="%1$s">WooCommerce %2$s or higher</a>. Please <a href="%3$s">install and activate WooCommerce</a>', 'woocommerce-pos' ),
					'http://wordpress.org/plugins/woocommerce/',
					WC_MIN_VERSION,
					admin_url( 'plugins.php' )
				) . ' &raquo;';

				Admin\Notices::add( $message );
			}
		);
	}

	/**
	 * POS Frontend will give 404 if pretty permalinks not active.
	 */
	private function permalink_check(): void {
		$permalinks = get_option( 'permalink_structure' );

		// early return.
		if ( $permalinks ) {
			return;
		}

		$message = __( '<strong>WooCommerce REST API</strong> requires <em>pretty</em> permalinks to work correctly', 'woocommerce-pos' ) . '. ';
		$message .= \sprintf( '<a href="%s">%s</a>', admin_url( 'options-permalink.php' ), __( 'Enable permalinks', 'woocommerce-pos' ) ) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * Check version number, runs every admin page load.
	 */
	private function version_check(): void {
		$old = (string) Services\Settings::get_db_version();
		if ( ! version_compare( $old, VERSION, '<' ) ) {
			return;
		}

		if ( ! $this->acquire_db_upgrade_lock() ) {
			return;
		}

		$locked_old = (string) Services\Settings::get_db_version();
		if ( ! version_compare( $locked_old, VERSION, '<' ) ) {
			delete_option( self::DB_UPGRADE_LOCK_OPTION );
			return;
		}

		Services\Settings::bump_versions();

		// Defer db_upgrade to woocommerce_init when WC is fully loaded.
		// This prevents conflicts with plugins like WC Subscriptions that hook
		// into before_delete_post and assume WC()->order_factory is available.
		add_action(
			'woocommerce_init',
			function () use ( $locked_old ) {
				try {
					$this->db_upgrade( $locked_old, VERSION );
				} finally {
					delete_option( self::DB_UPGRADE_LOCK_OPTION );
				}
			}
		);
	}

	/**
	 * Acquire the DB upgrade lock.
	 *
	 * @return bool True when this request owns the lock.
	 */
	private function acquire_db_upgrade_lock(): bool {
		$now = time();

		// Atomic fast path for unlocked state.
		if ( add_option( self::DB_UPGRADE_LOCK_OPTION, (string) $now, '', false ) ) {
			return true;
		}

		$lock_started = (int) get_option( self::DB_UPGRADE_LOCK_OPTION, 0 );
		if ( $lock_started > 0 && ( $now - $lock_started ) < self::DB_UPGRADE_LOCK_TTL ) {
			return false;
		}

		global $wpdb;

		// Try to atomically steal a stale lock.
		$stale_before = $now - self::DB_UPGRADE_LOCK_TTL;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s
				WHERE option_name = %s
					AND CAST(option_value AS UNSIGNED) < %d",
				(string) $now,
				self::DB_UPGRADE_LOCK_OPTION,
				$stale_before
			)
		);

		return 1 === (int) $updated;
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
		// }.
	}

	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted.
	 */
	private function get_blog_ids() {
		global $wpdb;

		// get an array of blog ids.
		$sql = "SELECT blog_id FROM $wpdb->blogs
      WHERE archived = '0' AND spam = '0'
      AND deleted = '0'";

		return $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query, no user input
	}

	/**
	 * Add POS specific roles.
	 */
	private function create_pos_roles(): void {
		// WC 9.9 replaced promote_users with create_customers for customer creation.
		$customer_create_cap = \defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) // @phpstan-ignore-line
			? 'create_customers'
			: 'promote_users';

		// Cashier role.
		$cashier_capabilities = array(
			'read'                      => true,
			'read_private_products'     => true,
			'read_private_shop_orders'  => true,
			'publish_shop_orders'       => true,
			'edit_shop_orders'          => true,
			'edit_others_shop_orders'   => true,
			'list_users'                => true,
			$customer_create_cap        => true,
			'edit_users'                => true,
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
	 * Add default pos capabilities to administrator and shop_manager roles.
	 *
	 * @param array $roles An array of arrays representing the roles and their POS capabilities.
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
	 * @param string $old     Old version.
	 * @param string $current Current version.
	 */
	private function db_upgrade( $old, $current ): void {
		$db_updates = array(
			'0.4'          => 'updates/update-0.4.php',
			'0.4.6'        => 'updates/update-0.4.6.php',
			'1.0.0-beta.1' => 'updates/update-1.0.0-beta.1.php',
			'1.6.1'        => 'updates/update-1.6.1.php',
			'1.8.0'        => 'updates/update-1.8.0.php',
			'1.8.7'        => 'updates/update-1.8.7.php',
			'1.8.12'       => 'updates/update-1.8.12.php',
			'1.8.13'       => 'updates/update-1.8.13.php',
		);
		foreach ( $db_updates as $version => $updater ) {
			if ( version_compare( $version, $old, '>' ) &&
			 version_compare( $version, $current, '<=' ) ) {
				include $updater;
			}
		}
	}

	/**
	 * If \WCPOS\WooCommercePOSPro\ is installed, check the version is above MIN_PRO_VERSION.
	 */
	private function pro_version_check(): void {
		if ( class_exists( '\WCPOS\WooCommercePOSPro\Activator' ) ) {
			if ( version_compare( \WCPOS\WooCommercePOSPro\VERSION, MIN_PRO_VERSION, '<' ) ) { // @phpstan-ignore-line

				/*
				 * NOTE: the deactivate_plugins function is not available in the frontend or ajax
				 * This is an extreme situation where the Pro plugin could crash the site, so we need to deactivate it
				 */
				if ( ! \function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . '/wp-admin/includes/plugin.php';
				}

				// WCPOS Pro is activated, but the version is too low - use the constant for dynamic folder name.
				deactivate_plugins( \WCPOS\WooCommercePOSPro\PLUGIN_FILE ); // @phpstan-ignore-line

				// Defer __() call to avoid "too early" warning in WordPress 6.7+.
				add_action(
					'admin_init',
					function () {
						$message = \sprintf(
							// translators: 1: WCPOS Pro URL, 2: Minimum Pro version, 3: Plugins URL.
							__( '<strong>WCPOS</strong> requires <a href="%1$s">WCPOS Pro %2$s or higher</a>. Please <a href="%3$s">install and activate WCPOS Pro</a>', 'woocommerce-pos' ),
							'https://wcpos.com/my-account',
							MIN_PRO_VERSION,
							admin_url( 'plugins.php' )
						) . ' &raquo;';

						Admin\Notices::add( $message );
					}
				);
			}
		}
	}
}
