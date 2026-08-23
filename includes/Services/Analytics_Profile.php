<?php
/**
 * Analytics site profile.
 *
 * Builds the property set attached to the PostHog `site` group. This is the
 * ONLY place that decides what environment data leaves the install, and it is
 * deliberately an allowlist: every property is named and shaped here, so no
 * field can reach PostHog by being added to some other service's payload.
 *
 * Two rules the shape follows, both from the telemetry spec (#793):
 *
 * - Counts are reported as BANDS, never raw. "this store has 47 products" is
 *   a store metric; "this store is in the 11-100 band" answers every product
 *   question we actually ask, without carrying a fingerprint.
 * - Payment gateways are reported as a COUNT, never as names. Which gateways a
 *   merchant runs is their business.
 *
 * Never add the site URL, admin URL, admin email, IP address, or any
 * per-order value here. Landing_Profile intentionally carries `site_domain`
 * and `admin_domain` for the updates server; those must not be copied in.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Analytics_Profile service class.
 */
class Analytics_Profile {
	/**
	 * Upper bound of each count band, in ascending order, mapped to its label.
	 *
	 * A count is reported with the label of the first band it fits under.
	 * Anything above the largest bound falls through to OVERFLOW_BAND.
	 */
	const COUNT_BANDS = array(
		'0'        => 0,
		'1-10'     => 10,
		'11-100'   => 100,
		'101-1000' => 1000,
	);

	/**
	 * Label used for counts above the largest band.
	 *
	 * @var string
	 */
	const OVERFLOW_BAND = '1000+';

	/**
	 * Classes that indicate a multi-currency plugin is running.
	 *
	 * WooCommerce core has no multi-currency concept, so presence has to be
	 * inferred from the well-known implementations. This is a best-effort
	 * signal for segmentation, not a contract — extend it via the
	 * `woocommerce_pos_analytics_multi_currency` filter rather than assuming
	 * the list is complete.
	 *
	 * @var string[]
	 */
	const MULTI_CURRENCY_CLASSES = array(
		'WCML_Multi_Currency',                       // WPML WooCommerce Multilingual.
		'WC_Aelia_CurrencySwitcher',                 // Aelia Currency Switcher.
		'WOOMULTI_CURRENCY_F',                       // CURCY / WooCommerce Multi Currency.
		'Alg_WC_Currency_Switcher',                  // Currency Switcher for WooCommerce.
	);

	/**
	 * Build the `site` group properties.
	 *
	 * Safe to call without a logged-in user — every value is derived from
	 * site state, so the scheduled refresh can use it from cron.
	 *
	 * @return array<string, mixed>
	 */
	public function get_group_properties(): array {
		$metrics = ( new Landing_Profile() )->get_metrics();

		$properties = array(
			// Platform.
			'php_version'        => PHP_VERSION,
			'wp_version'         => get_bloginfo( 'version' ),
			'wc_version'         => $this->get_wc_version(),
			'mysql_version'      => $this->get_mysql_version(),
			'wcpos_version'      => PLUGIN_VERSION,
			'wcpos_edition'      => class_exists( '\WCPOS\WooCommercePOSPro\WooCommercePOSPro' ) ? 'pro' : 'free',

			// Locale and market.
			'wc_country'         => $this->get_base_country(),
			'wc_currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'locale'             => get_locale(),
			'timezone'           => wp_timezone_string(),

			// Environment shape.
			'multisite'          => is_multisite(),
			'hpos_enabled'       => $this->is_hpos_enabled(),
			'tax_enabled'        => function_exists( 'wc_tax_enabled' ) ? wc_tax_enabled() : false,
			'multi_currency'     => $this->has_multi_currency(),

			// Catalogue and trading volume — banded, never raw. These are the
			// numbers that would fingerprint a store.
			'product_count_band' => self::band( (int) ( $metrics['product_count'] ?? 0 ) ),
			'order_count_band'   => self::band( (int) ( $metrics['order_count'] ?? 0 ) ),

			// Small-cardinality operational counts, reported exactly. Banding a
			// staff count into "1-10 / 11-100" would erase the only interesting
			// thing about it — the difference between a one-person shop and a
			// six-till store — and neither number identifies a store.
			'days_since_install' => (int) ( $metrics['days_since_install'] ?? 0 ),
			'pos_user_count'     => (int) ( $metrics['pos_user_count'] ?? 0 ),
			'gateway_count'      => \count( (array) ( $metrics['active_gateways'] ?? array() ) ),
		);

		/**
		 * Filters the property set attached to the PostHog `site` group.
		 *
		 * Returned values are sent verbatim. Do not add identifying data —
		 * see the class docblock for what this surface deliberately omits.
		 *
		 * @since 1.10.0
		 *
		 * @param array<string, mixed> $properties The group properties.
		 */
		return apply_filters( 'woocommerce_pos_analytics_group_properties', $properties );
	}

	/**
	 * Build the feature-adoption snapshot sent with `admin_landing_viewed`.
	 *
	 * One snapshot per landing view answers "what share of stores enable X"
	 * without an event per toggle. Booleans and a fixed enum only — the same
	 * allowlist discipline as the group properties, and for the same reason.
	 *
	 * `barcode_field` is deliberately reduced to default-or-custom: the raw
	 * value is a meta key the merchant chose and can name anything, so it is
	 * free-text from a store we have no business reading.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_summary(): array {
		// Read through the typed accessors rather than the raw option. They are
		// what the plugin itself acts on, so the snapshot reports the behaviour
		// the merchant actually gets. It matters: `force_ssl` can still hold the
		// legacy string "false" on upgraded stores, which is why its accessor —
		// alone among the booleans — normalizes with wp_validate_boolean(). A
		// raw (bool) cast on that value yields true and reports the opposite of
		// what the store does.
		$settings = Settings::instance();
		$defaults = ( new Settings\General_Section() )->defaults();

		$summary = array(
			'pos_only_products'           => $settings->pos_only_products_enabled(),
			'decimal_qty'                 => $settings->decimal_qty_enabled(),
			'force_ssl'                   => $settings->force_ssl_enabled(),
			'generate_username'           => $settings->generate_username_enabled(),
			'default_customer_is_cashier' => $settings->default_customer_is_cashier(),
			'restore_stock_on_delete'     => $settings->restore_stock_on_delete_enabled(),
			'storefront_receipt_enabled'  => wp_validate_boolean( $settings->get_settings( 'general', 'storefront_receipt_enabled' ) ),
			'barcode_field'               => $defaults['barcode_field'] === $settings->barcode_field() ? 'default' : 'custom',
			'tracking_consent'            => $settings->tracking_consent(),
			'enabled_gateway_count'       => \count( (array) ( ( new Landing_Profile() )->get_metrics()['active_gateways'] ?? array() ) ),
		);

		/**
		 * Filters the feature-adoption snapshot sent with the landing view.
		 *
		 * @since 1.10.0
		 *
		 * @param array<string, mixed> $summary The settings summary.
		 */
		return apply_filters( 'woocommerce_pos_analytics_settings_summary', $summary );
	}

	/**
	 * Map a raw count onto its reporting band.
	 *
	 * @param int $count The raw count.
	 *
	 * @return string The band label.
	 */
	public static function band( int $count ): string {
		foreach ( self::COUNT_BANDS as $label => $upper_bound ) {
			if ( $count <= $upper_bound ) {
				return $label;
			}
		}

		return self::OVERFLOW_BAND;
	}

	/**
	 * Get the WooCommerce version, or an empty string when WC is unavailable.
	 *
	 * @return string
	 */
	private function get_wc_version(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		return (string) WC()->version;
	}

	/**
	 * Get the database server version.
	 *
	 * @return string
	 */
	private function get_mysql_version(): string {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'db_version' ) ) {
			return '';
		}

		return (string) $wpdb->db_version();
	}

	/**
	 * Get the WooCommerce base country.
	 *
	 * @return string
	 */
	private function get_base_country(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		return (string) WC()->countries->get_base_country();
	}

	/**
	 * Whether WooCommerce High-Performance Order Storage is in use.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled(): bool {
		if ( ! class_exists( OrderUtil::class ) ) {
			return false;
		}

		return OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The class names checked to detect a multi-currency plugin.
	 *
	 * Exists as a method rather than reading the constant inline so the list is
	 * typed as plain strings. Read straight from the constant, static analysis
	 * knows the exact literals, sees that none of these third-party classes
	 * exist anywhere in this codebase, and reports the runtime check as
	 * impossible — which it is not: it is answered on the merchant's site.
	 *
	 * @return string[]
	 */
	private function multi_currency_classes(): array {
		return self::MULTI_CURRENCY_CLASSES;
	}

	/**
	 * Whether a known multi-currency plugin is active.
	 *
	 * @return bool
	 */
	private function has_multi_currency(): bool {
		$detected = false;

		foreach ( $this->multi_currency_classes() as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$detected = true;
				break;
			}
		}

		/**
		 * Filters whether the site is treated as running multi-currency.
		 *
		 * @since 1.10.0
		 *
		 * @param bool $detected Whether a known multi-currency plugin was found.
		 */
		return (bool) apply_filters( 'woocommerce_pos_analytics_multi_currency', $detected );
	}
}
