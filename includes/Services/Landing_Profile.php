<?php
/**
 * Landing page profile service.
 *
 * Gathers store metrics and configuration for the landing page React app.
 * Data is split into two tiers:
 * - Functional data (locale, version): always available, no consent needed
 * - Profile data (store metrics, UUIDs): requires explicit user consent
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use const HOUR_IN_SECONDS;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Landing Profile service class.
 */
class Landing_Profile {

	/**
	 * Transient key for cached metrics.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'wcpos_landing_profile';

	/**
	 * Cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Default updates server base URL.
	 *
	 * @var string
	 */
	const UPDATES_SERVER_URL = 'https://updates.wcpos.com';

	/**
	 * Get functional data that is always available (no consent required).
	 *
	 * Used for translations, feature gating, and schema versioning.
	 *
	 * @return array
	 */
	public function get_functional_data(): array {
		return array(
			'schema_version'  => 1,
			'locale'          => get_locale(),
			'plugin_version'  => PLUGIN_VERSION,
			'pro_active'      => class_exists( '\WCPOS\WooCommercePOSPro\WooCommercePOSPro' ),
		);
	}

	/**
	 * Get consent-gated data (store profile + service config).
	 *
	 * Only sent when the user has explicitly allowed tracking.
	 *
	 * @return array
	 */
	public function get_consented_data(): array {
		return array(
			'profile'        => $this->get_profile(),
			'updates_server' => $this->get_updates_server_config(),
		);
	}

	/**
	 * Get the store profile (consent-gated fields only).
	 *
	 * Merges cached expensive metrics with cheap/user-specific fields
	 * that are computed fresh on every call.
	 *
	 * @return array
	 */
	public function get_profile(): array {
		$cached = $this->get_cached_metrics();
		$user   = wp_get_current_user();

		return array_merge(
			$cached,
			array(
				'wc_version'  => WC()->version,
				'php_version' => PHP_VERSION,
				'site_uuid'   => get_option( 'woocommerce_pos_uuid', '' ),
				'user_uuid'   => get_user_meta( $user->ID, '_woocommerce_pos_uuid', true ),
				'user_role'   => ! empty( $user->roles ) ? $user->roles[0] : '',
				'wc_currency' => get_woocommerce_currency(),
				'wc_country'  => WC()->countries->get_base_country(),
			)
		);
	}

	/**
	 * Get updates server configuration.
	 *
	 * @return array
	 */
	public function get_updates_server_config(): array {
		/**
		 * Filters the updates server profile endpoint URL.
		 *
		 * @since 1.9.0
		 *
		 * @param string $profile_url The profile endpoint URL.
		 */
		$profile_url = apply_filters(
			'woocommerce_pos_updates_server_profile_url',
			self::UPDATES_SERVER_URL . '/v1/profile'
		);

		return array(
			'profile_url' => $profile_url,
		);
	}

	/**
	 * Get cached expensive metrics, computing them if the cache is stale.
	 *
	 * @return array
	 */
	private function get_cached_metrics(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return $cached;
		}

		$metrics = $this->compute_metrics();
		set_transient( self::TRANSIENT_KEY, $metrics, self::CACHE_TTL );

		return $metrics;
	}

	/**
	 * Compute expensive store metrics.
	 *
	 * @return array
	 */
	private function compute_metrics(): array {
		$installed_at = get_option( 'woocommerce_pos_installed_at' );
		if ( false === $installed_at ) {
			$installed_at = time();
			add_option( 'woocommerce_pos_installed_at', $installed_at );
		}
		$installed_at = (int) $installed_at;

		return array(
			'days_since_install' => max( 0, (int) floor( ( time() - $installed_at ) / DAY_IN_SECONDS ) ),
			'product_count'      => (int) wp_count_posts( 'product' )->publish,
			'order_count'        => $this->get_pos_order_count(),
			'pos_user_count'     => $this->get_pos_user_count(),
			'active_gateways'    => $this->get_active_gateway_ids(),
			'active_extensions'  => $this->get_active_extension_slugs(),
		);
	}

	/**
	 * Count POS orders using direct SQL for performance.
	 *
	 * Supports both HPOS (custom orders table) and legacy post-based storage.
	 *
	 * @return int
	 */
	private function get_pos_order_count(): int {
		global $wpdb;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders_table = method_exists( OrdersTableDataStore::class, 'get_orders_table_name' )
				? OrdersTableDataStore::get_orders_table_name()
				: $wpdb->prefix . 'wc_orders';
			$op_table     = method_exists( OrdersTableDataStore::class, 'get_operational_data_table_name' )
				? OrdersTableDataStore::get_operational_data_table_name()
				: $wpdb->prefix . 'wc_order_operational_data';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table} orders
					INNER JOIN {$op_table} op ON op.order_id = orders.id
					WHERE orders.type = 'shop_order'
					AND op.created_via = %s
					AND orders.status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')",
					'woocommerce-pos'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND pm.meta_key = '_created_via'
				AND pm.meta_value = %s
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')",
				'woocommerce-pos'
			)
		);
	}

	/**
	 * Count users with POS access capability.
	 *
	 * @return int
	 */
	private function get_pos_user_count(): int {
		$users = get_users(
			array(
				'capability' => 'access_woocommerce_pos',
				'fields'     => 'ID',
			)
		);

		return \count( $users );
	}

	/**
	 * Get IDs of enabled POS payment gateways.
	 *
	 * @return array
	 */
	private function get_active_gateway_ids(): array {
		$settings = Settings::instance()->get_payment_gateways_settings();
		$gateways = $settings['gateways'] ?? array();
		$active   = array();

		foreach ( $gateways as $id => $gw ) {
			if ( ! empty( $gw['enabled'] ) ) {
				$active[] = $id;
			}
		}

		return $active;
	}

	/**
	 * Get slugs of active WCPOS extensions.
	 *
	 * @return array
	 */
	private function get_active_extension_slugs(): array {
		$extensions = Extensions::instance()->get_extensions();
		$active     = array();

		foreach ( $extensions as $ext ) {
			if ( 'active' === ( $ext['status'] ?? '' ) || 'update_available' === ( $ext['status'] ?? '' ) ) {
				$active[] = $ext['slug'] ?? '';
			}
		}

		return array_values( array_filter( $active ) );
	}
}
