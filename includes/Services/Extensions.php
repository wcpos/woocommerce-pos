<?php
/**
 * Extensions service.
 *
 * Fetches the remote extension catalog and enriches it with local plugin status.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Extensions service class.
 */
class Extensions {

	/**
	 * Singleton instance.
	 *
	 * @var Extensions|null
	 */
	private static $instance = null;

	/**
	 * Remote catalog URL.
	 *
	 * @var string
	 */
	const CATALOG_URL = 'https://raw.githubusercontent.com/wcpos/extensions/main/catalog.json';

	/**
	 * Transient key for cached catalog.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'wcpos_extensions_catalog';

	/**
	 * Cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Constructor is private to prevent direct instantiation.
	 */
	private function __construct() {
		add_action( 'activated_plugin', array( $this, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_cache' ) );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return Extensions
	 */
	public static function instance(): Extensions {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the raw catalog from remote or cache.
	 *
	 * @return array
	 */
	public function get_catalog(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return $cached;
		}

		/**
		 * Filters the URL used to fetch the extensions catalog.
		 *
		 * @since 1.9.0
		 *
		 * @param string $url The catalog URL.
		 */
		$url = apply_filters( 'woocommerce_pos_extensions_catalog_url', self::CATALOG_URL );

		$response = wp_remote_get(
			$url,
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body    = wp_remote_retrieve_body( $response );
		$catalog = json_decode( $body, true );

		if ( ! \is_array( $catalog ) ) {
			return array();
		}

		set_transient( self::TRANSIENT_KEY, $catalog, self::CACHE_TTL );

		return $catalog;
	}

	/**
	 * Get extensions enriched with local install/active status.
	 *
	 * @return array
	 */
	public function get_extensions(): array {
		$catalog = $this->get_catalog();

		if ( empty( $catalog ) ) {
			return array();
		}

		if ( ! \function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$network_plugins = array();
		if ( is_multisite() ) {
			$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		}

		$extensions = array();

		foreach ( $catalog as $entry ) {
			$slug        = $entry['slug'] ?? '';
			$plugin_file = $this->find_plugin_file( $slug, $installed_plugins );
			$status      = 'not_installed';

			if ( $plugin_file ) {
				$local_version  = $installed_plugins[ $plugin_file ]['Version'] ?? '';
				$remote_version = $entry['latest_version'] ?? $entry['version'] ?? '';
				$is_active      = \in_array( $plugin_file, $active_plugins, true )
					|| \in_array( $plugin_file, $network_plugins, true );

				$has_update = $remote_version && version_compare( $local_version, $remote_version, '<' );

				if ( $has_update ) {
					$status = 'update_available';
				} elseif ( $is_active ) {
					$status = 'active';
				} else {
					$status = 'inactive';
				}

				$entry['installed_version'] = $local_version;
				$entry['plugin_file']       = $plugin_file;
			}

			$entry['status'] = $status;
			$extensions[]    = $entry;
		}

		return $extensions;
	}

	/**
	 * Find the plugin file path for a given extension slug.
	 *
	 * Looks for a plugin directory matching the slug.
	 *
	 * @param string $slug              Extension slug.
	 * @param array  $installed_plugins Installed plugins from get_plugins().
	 *
	 * @return string|null Plugin file path or null if not found.
	 */
	private function find_plugin_file( string $slug, array $installed_plugins ): ?string {
		foreach ( array_keys( $installed_plugins ) as $plugin_file ) {
			if ( 0 === strpos( $plugin_file, $slug . '/' ) ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Clear the catalog transient cache.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
