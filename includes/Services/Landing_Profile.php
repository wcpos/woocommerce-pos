<?php
/**
 * Landing page profile service.
 *
 * Gathers store metrics and configuration for the landing page React app,
 * enabling personalised A/B testing via PostHog and customer profiling
 * via the updates server.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

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
	 * Get all landing page data (profile + service config).
	 *
	 * @return array
	 */
	public function get_landing_data(): array {
		return array(
			'schema_version' => 1,
			'profile'        => $this->get_profile(),
			'posthog'        => $this->get_posthog_config(),
			'updates_server' => $this->get_updates_server_config(),
		);
	}

	/**
	 * Get the store profile.
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
				'locale'         => get_locale(),
				'wc_version'     => WC()->version,
				'plugin_version' => PLUGIN_VERSION,
				'pro_active'     => class_exists( '\WCPOS\WooCommercePOSPro\WooCommercePOSPro' ),
				'php_version'    => PHP_VERSION,
				'site_uuid'      => get_option( 'woocommerce_pos_uuid', '' ),
				'user_uuid'      => get_user_meta( $user->ID, '_woocommerce_pos_uuid', true ),
				'user_role'      => ! empty( $user->roles ) ? $user->roles[0] : '',
				'wc_currency'    => get_woocommerce_currency(),
				'wc_country'     => WC()->countries->get_base_country(),
			)
		);
	}

	/**
	 * Get PostHog configuration.
	 *
	 * Credentials are stored in wp_options and overridable via filters.
	 * Ships empty — must be configured. The React app skips PostHog
	 * initialization if api_key is empty.
	 *
	 * @return array
	 */
	public function get_posthog_config(): array {
		/**
		 * Filters the PostHog API host URL.
		 *
		 * @since 1.9.0
		 *
		 * @param string $api_host The PostHog API host URL.
		 */
		$api_host = apply_filters(
			'woocommerce_pos_posthog_api_host',
			get_option( 'woocommerce_pos_posthog_api_host', '' )
		);

		/**
		 * Filters the PostHog project API key.
		 *
		 * @since 1.9.0
		 *
		 * @param string $api_key The PostHog project API key.
		 */
		$api_key = apply_filters(
			'woocommerce_pos_posthog_api_key',
			get_option( 'woocommerce_pos_posthog_api_key', '' )
		);

		return array(
			'api_host' => $api_host,
			'api_key'  => $api_key,
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
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
					WHERE type = 'shop_order'
					AND created_via = %s
					AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')",
					'woocommerce-pos'
				)
			);
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
