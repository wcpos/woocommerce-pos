<?php
/**
 * Store Defaults resolver.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the effective values for the free single-store properties that the
 * user can override under WP Admin > POS > Settings > General.
 *
 * Resolution cascade (per field):
 *   1. WCPOS general setting (what the user typed in our settings screen)
 *   2. The matching WooCommerce core "Point of Sale" option, if present
 *      (woocommerce_pos_store_name etc., shipped with WC 10.5)
 *   3. A WordPress / WooCommerce fallback (get_bloginfo, admin_email, ...)
 *
 * Pro overrides Store::set_pro_property_defaults() and reads from the per-store
 * CPT, so it does not call this service.
 */
class Store_Defaults {
	/**
	 * Effective store name.
	 */
	public static function name(): string {
		$user = self::pos_setting( 'store_name' );
		return '' !== $user ? $user : self::name_fallback();
	}

	/**
	 * Effective store phone.
	 */
	public static function phone(): string {
		$user = self::pos_setting( 'store_phone' );
		return '' !== $user ? $user : self::phone_fallback();
	}

	/**
	 * Effective store email.
	 */
	public static function email(): string {
		$user = self::pos_setting( 'store_email' );
		return '' !== $user ? $user : self::email_fallback();
	}

	/**
	 * Effective refund & returns policy.
	 *
	 * Maps to the Store's policies_and_conditions property; templates render
	 * it as a "Refund & Returns Policy" line on receipts.
	 */
	public static function policies_and_conditions(): string {
		$user = self::pos_setting( 'policies_and_conditions' );
		return '' !== $user ? $user : self::policies_and_conditions_fallback();
	}

	/**
	 * Sanitized list of structured store tax IDs.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function tax_ids(): array {
		$general = woocommerce_pos_get_settings( 'general' );
		if ( ! \is_array( $general ) || ! isset( $general['store_tax_ids'] ) ) {
			return array();
		}
		return Settings::sanitize_store_tax_ids( $general['store_tax_ids'] );
	}

	/**
	 * Resolved fallback values used as placeholders in the React settings UI
	 * when the corresponding WCPOS setting is empty.
	 *
	 * @return array<string,string>
	 */
	public static function fallbacks(): array {
		return array(
			'store_name'              => self::name_fallback(),
			'store_phone'             => self::phone_fallback(),
			'store_email'             => self::email_fallback(),
			'policies_and_conditions' => self::policies_and_conditions_fallback(),
		);
	}

	/**
	 * Fallback for the store name, ignoring the user's own setting.
	 */
	public static function name_fallback(): string {
		$wc = self::wc_pos_option( 'woocommerce_pos_store_name' );
		return '' !== $wc ? $wc : (string) \get_bloginfo( 'name' );
	}

	/**
	 * Fallback for the store phone, ignoring the user's own setting.
	 */
	public static function phone_fallback(): string {
		return self::wc_pos_option( 'woocommerce_pos_store_phone' );
	}

	/**
	 * Fallback for the store email, ignoring the user's own setting.
	 */
	public static function email_fallback(): string {
		$wc = self::wc_pos_option( 'woocommerce_pos_store_email' );
		if ( '' !== $wc ) {
			return $wc;
		}
		$from = (string) \get_option( 'woocommerce_email_from_address', '' );
		if ( '' !== $from ) {
			return $from;
		}
		return (string) \get_option( 'admin_email', '' );
	}

	/**
	 * Fallback for the refund & returns policy, ignoring the user's own setting.
	 */
	public static function policies_and_conditions_fallback(): string {
		return self::wc_pos_option( 'woocommerce_pos_refund_returns_policy' );
	}

	/**
	 * Read a string value from the WCPOS general settings.
	 *
	 * @param string $key Setting key.
	 */
	private static function pos_setting( string $key ): string {
		$general = woocommerce_pos_get_settings( 'general' );
		if ( ! \is_array( $general ) || ! isset( $general[ $key ] ) || ! \is_string( $general[ $key ] ) ) {
			return '';
		}
		return trim( $general[ $key ] );
	}

	/**
	 * Read a trimmed string from a WordPress option.
	 *
	 * @param string $option_name WordPress option name.
	 */
	private static function wc_pos_option( string $option_name ): string {
		$value = \get_option( $option_name, '' );
		return \is_string( $value ) ? trim( $value ) : '';
	}
}
