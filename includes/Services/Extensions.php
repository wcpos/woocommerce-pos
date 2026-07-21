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
	 * Option key used as an atomic forced-refresh lock.
	 *
	 * @var string
	 */
	const REFRESH_LOCK_KEY = 'wcpos_extensions_catalog_refresh_lock';

	/**
	 * Maximum lock lifetime before an interrupted request may be reclaimed.
	 *
	 * @var int
	 */
	const REFRESH_LOCK_TTL = 2 * MINUTE_IN_SECONDS;

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

		$catalog = $this->fetch_catalog();

		if ( is_wp_error( $catalog ) ) {
			return array();
		}

		set_transient( self::TRANSIENT_KEY, $catalog, self::CACHE_TTL );

		return $catalog;
	}

	/**
	 * Fetch and validate a candidate, swapping it into cache only while holding the lock.
	 *
	 * @return array|\WP_Error
	 */
	public function refresh_catalog() {
		$lock = $this->acquire_refresh_lock();

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$catalog = $this->fetch_catalog( true );

			if ( is_wp_error( $catalog ) ) {
				return $catalog;
			}

			set_transient( self::TRANSIENT_KEY, $catalog, self::CACHE_TTL );

			return $catalog;
		} finally {
			$this->release_refresh_lock( $lock );
		}
	}

	/**
	 * Atomically acquire the refresh lock, reclaiming only an expired owner.
	 *
	 * @return string|\WP_Error
	 */
	private function acquire_refresh_lock() {
		global $wpdb;

		$token = wp_generate_uuid4();
		$value = array(
			'token'      => $token,
			'expires_at' => time() + self::REFRESH_LOCK_TTL,
		);
		$serialized = maybe_serialize( $value );

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock insert; caches are invalidated after success.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::REFRESH_LOCK_KEY,
				$serialized,
				'no'
			)
		);

		if ( 1 === $inserted ) {
			$this->invalidate_refresh_lock_cache();
			return $token;
		}

		$current_serialized = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the exact stored lock value for compare-and-swap.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::REFRESH_LOCK_KEY )
		);
		$current = maybe_unserialize( $current_serialized );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) < time() ) {
			$reclaimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic exact-value lock reclamation; caches are invalidated after success.
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
					$serialized,
					'no',
					self::REFRESH_LOCK_KEY,
					$current_serialized
				)
			);
			if ( 1 === $reclaimed ) {
				$this->invalidate_refresh_lock_cache();
				return $token;
			}
		}

		return new \WP_Error(
			'woocommerce_pos_extensions_refresh_in_progress',
			__( 'Extension versions are already being refreshed. Please try again shortly.', 'woocommerce-pos' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Release the lock only when this request still owns it.
	 *
	 * @param string $token Lock ownership token.
	 */
	private function release_refresh_lock( string $token ): void {
		global $wpdb;

		$current_serialized = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the exact stored lock value for compare-and-delete.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::REFRESH_LOCK_KEY )
		);
		$current = maybe_unserialize( $current_serialized );

		if ( is_array( $current ) && ( $current['token'] ?? '' ) === $token ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic exact-value lock release; caches are invalidated after success.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
					self::REFRESH_LOCK_KEY,
					$current_serialized
				)
			);
			if ( 1 === $deleted ) {
				$this->invalidate_refresh_lock_cache();
			}
		}
	}

	/**
	 * Invalidate every core cache location that can retain the refresh lock.
	 */
	private function invalidate_refresh_lock_cache(): void {
		wp_cache_delete( self::REFRESH_LOCK_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Fetch and validate catalog data without changing the cached catalog.
	 *
	 * @param bool $validate_for_refresh Whether to enforce the complete refresh schema.
	 *
	 * @return array|\WP_Error
	 */
	private function fetch_catalog( bool $validate_for_refresh = false ) {
		/**
		 * Filters the URL used to fetch the extensions catalog.
		 *
		 * @since 1.9.0
		 *
		 * @param string $url The catalog URL.
		 */
		$url = apply_filters( 'woocommerce_pos_extensions_catalog_url', self::CATALOG_URL );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->refresh_error();
		}

		$catalog = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $catalog ) || ( $validate_for_refresh && ! $this->is_valid_catalog( $catalog ) ) ) {
			return $this->refresh_error();
		}

		return $catalog;
	}

	/**
	 * Validate every catalog field consumed by enrichment, settings, or Pro actions.
	 *
	 * @param mixed $catalog Decoded response.
	 */
	private function is_valid_catalog( $catalog ): bool {
		if ( ! is_array( $catalog ) || empty( $catalog ) || array_keys( $catalog ) !== range( 0, count( $catalog ) - 1 ) ) {
			return false;
		}

		$string_fields = array(
			'slug',
			'name',
			'description',
			'version',
			'author',
			'category',
			'requires_wp',
			'requires_wc',
			'requires_wcpos',
			'icon',
			'homepage',
			'download_url',
			'latest_version',
			'released_at',
		);

		foreach ( $catalog as $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}

			foreach ( $string_fields as $field ) {
				if ( ! array_key_exists( $field, $entry ) || ! is_string( $entry[ $field ] ) ) {
					return false;
				}
			}

			if ( '' === $entry['slug'] || '' === $entry['name'] || '' === $entry['latest_version'] ) {
				return false;
			}

			if ( ! isset( $entry['tags'] ) || ! is_array( $entry['tags'] ) || array_values( $entry['tags'] ) !== $entry['tags'] ) {
				return false;
			}
			foreach ( $entry['tags'] as $tag ) {
				if ( ! is_string( $tag ) ) {
					return false;
				}
			}

			if ( ! array_key_exists( 'requires_pro', $entry ) || ! is_bool( $entry['requires_pro'] ) ) {
				return false;
			}

			foreach ( array( 'settings_url', 'log_source' ) as $optional_string ) {
				if ( array_key_exists( $optional_string, $entry ) && ! is_string( $entry[ $optional_string ] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Build the error returned when a refreshed candidate cannot be used.
	 *
	 * @return \WP_Error
	 */
	private function refresh_error(): \WP_Error {
		return new \WP_Error(
			'woocommerce_pos_extensions_refresh_failed',
			__( 'Unable to refresh extension versions. Please try again.', 'woocommerce-pos' ),
			array( 'status' => 502 )
		);
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

		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

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

				if ( $has_update && $is_active ) {
					$status = 'update_available';
				} elseif ( $is_active ) {
					$status = 'active';
				} else {
					$status = 'inactive';
				}

				$entry['installed_version'] = $local_version;
				$entry['plugin_file']       = $plugin_file;
				$entry['auto_update']       = \in_array( $plugin_file, $auto_updates, true );

				if ( $has_update ) {
					$entry['has_update'] = true;
				}

				if ( ! empty( $entry['settings_url'] ) && \is_string( $entry['settings_url'] ) ) {
					$entry['settings_url'] = admin_url( $entry['settings_url'] );
				}
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
