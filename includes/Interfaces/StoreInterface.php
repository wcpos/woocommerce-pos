<?php
/**
 * Store Interface.
 *
 * Defines the API contract for Store classes in both free and Pro versions.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Interfaces;

/**
 * Store Interface.
 *
 * All Store implementations must provide these methods to ensure API consistency
 * between free and Pro versions of the plugin.
 */
interface StoreInterface {
	/**
	 * Get Store name.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_name( $context = 'view' );

	/**
	 * Get Store locale.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_locale( $context = 'view' );

	/**
	 * Get Store address line 1.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_address( $context = 'view' );

	/**
	 * Get Store address line 2.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_address_2( $context = 'view' );

	/**
	 * Get Store city.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_city( $context = 'view' );

	/**
	 * Get Store postcode.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_postcode( $context = 'view' );

	/**
	 * Get Store state code.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_state( $context = 'view' );

	/**
	 * Get Store country code.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_store_country( $context = 'view' );

	/**
	 * Get Store default customer address setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_default_customer_address( $context = 'view' );

	/**
	 * Get Store calculate taxes setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_calc_taxes( $context = 'view' );

	/**
	 * Get Store enable coupons setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_enable_coupons( $context = 'view' );

	/**
	 * Get Store calculate discounts sequentially setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_calc_discounts_sequentially( $context = 'view' );

	/**
	 * Get Store currency code.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_currency( $context = 'view' );

	/**
	 * Get Store currency position.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_currency_position( $context = 'view' );

	/**
	 * Get Store price thousand separator.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_price_thousand_separator( $context = 'view' );

	/**
	 * Get Store price decimal separator.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_price_decimal_separator( $context = 'view' );

	/**
	 * Get Store price number of decimals.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return int
	 */
	public function get_price_number_of_decimals( $context = 'view' );

	/**
	 * Get Store prices include tax setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_prices_include_tax( $context = 'view' );

	/**
	 * Get Store tax based on setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_tax_based_on( $context = 'view' );

	/**
	 * Get Store tax address.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return array
	 */
	public function get_tax_address( $context = 'view' );

	/**
	 * Get Store shipping tax class.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_shipping_tax_class( $context = 'view' );

	/**
	 * Get Store tax round at subtotal setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_tax_round_at_subtotal( $context = 'view' );

	/**
	 * Get Store tax display shop setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_tax_display_shop( $context = 'view' );

	/**
	 * Get Store tax display cart setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_tax_display_cart( $context = 'view' );

	/**
	 * Get Store price display suffix.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_price_display_suffix( $context = 'view' );

	/**
	 * Get Store tax total display setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_tax_total_display( $context = 'view' );

	/**
	 * Get Store default customer ID.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return int
	 */
	public function get_default_customer( $context = 'view' );

	/**
	 * Get Store default customer is cashier setting.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return bool
	 */
	public function get_default_customer_is_cashier( $context = 'view' );

	/**
	 * Get Store URL.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_url( $context = 'view' );

	/**
	 * Get Store phone number.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_phone( $context = 'view' );

	/**
	 * Get Store email address.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_email( $context = 'view' );

	/**
	 * Get Store opening hours.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_opening_hours( $context = 'view' );

	/**
	 * Get Store personal notes.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_personal_notes( $context = 'view' );

	/**
	 * Get Store policies and conditions.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_policies_and_conditions( $context = 'view' );

	/**
	 * Get Store footer imprint.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string
	 */
	public function get_footer_imprint( $context = 'view' );

	/**
	 * Get Store formatted address.
	 *
	 * @return string
	 */
	public function get_formatted_address();

	/**
	 * Get Store logo image source.
	 *
	 * @param string $size Image size. Default 'full'.
	 *
	 * @return array|false Array of image data, or false if no image.
	 */
	public function get_logo_image_src( $size = 'full' );
}
