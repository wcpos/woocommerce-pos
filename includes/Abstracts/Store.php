<?php
/**
 * Abstract Store class.
 *
 * @package WooCommerce\POS\Abstracts
 */

namespace WCPOS\WooCommercePOS\Abstracts;

// use WC_Admin_Settings; // this messes up tests.
use function wc_format_country_state_string;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Store class.
 *
 * Handles the store data, and provides CRUD methods.
 */
class Store extends \WC_Data {
	/**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
	protected $object_type = 'store';

	/**
	 * Stores product data.
	 *
	 * @var array
	 */
	protected $data = array(
		'name' => '',
		'locale' => '',
		'store_address' => '',
		'store_address_2' => '',
		'store_city' => '',
		'store_postcode' => '',
		'store_state' => '',
		'store_country' => '',
		'default_country' => '',
		'default_customer_address' => '',
		'calc_taxes' => '',
		'enable_coupons' => '',
		'calc_discounts_sequentially' => '',
		'currency' => '',
		'currency_pos' => '',
		'price_thousand_sep' => '',
		'price_decimal_sep' => '',
		'price_num_decimals' => '',
		'prices_include_tax' => '',
		'tax_based_on' => '',
		'tax_address' => array(
			'country' => '',
			'state' => '',
			'postcode' => '',
			'city' => '',
		),
		'shipping_tax_class' => '',
		'tax_round_at_subtotal' => '',
		'tax_display_shop' => '',
		'tax_display_cart' => '',
		'price_display_suffix' => '',
		'tax_total_display' => '',
		'default_customer' => 0,
		'default_customer_is_cashier' => false,
	);

	/**
	 * Construct the default POS store.
	 */
	public function __construct() {
		parent::__construct();
		$this->set_wordpress_settings();
		$this->set_woocommerce_general_settings();
		$this->set_woocommerce_tax_settings();
		$this->set_woocommerce_pos_settings();
	}

	/**
	 *
	 */
	public function set_wordpress_settings() {
		$this->set_prop( 'name', \get_bloginfo( 'name' ) );
		$this->set_prop( 'locale', \get_locale() );
	}

	/**
	 *
	 */
	public function set_woocommerce_general_settings() {
		$default_country = \WC_Admin_Settings::get_option( 'woocommerce_default_country' );
		$country_state_array = wc_format_country_state_string( $default_country );

		$this->set_prop( 'store_address', \WC_Admin_Settings::get_option( 'woocommerce_store_address' ) );
		$this->set_prop( 'store_address_2', \WC_Admin_Settings::get_option( 'woocommerce_store_address_2' ) );
		$this->set_prop( 'store_city', \WC_Admin_Settings::get_option( 'woocommerce_store_city' ) );
		$this->set_prop( 'store_state', $country_state_array['state'] );
		$this->set_prop( 'store_country', $country_state_array['country'] );
		$this->set_prop( 'default_country', $default_country );
		$this->set_prop( 'store_postcode', \WC_Admin_Settings::get_option( 'woocommerce_store_postcode' ) );
		$this->set_prop( 'default_customer_address', \WC_Admin_Settings::get_option( 'woocommerce_default_customer_address' ) );
		$this->set_prop( 'calc_taxes', \WC_Admin_Settings::get_option( 'woocommerce_calc_taxes' ) );
		$this->set_prop( 'enable_coupons', \WC_Admin_Settings::get_option( 'woocommerce_enable_coupons' ) );
		$this->set_prop( 'calc_discounts_sequentially', \WC_Admin_Settings::get_option( 'woocommerce_calc_discounts_sequentially' ) );
		$this->set_prop( 'currency', \WC_Admin_Settings::get_option( 'woocommerce_currency' ) );
		$this->set_prop( 'currency_pos', \WC_Admin_Settings::get_option( 'woocommerce_currency_pos' ) );
		$this->set_prop( 'price_thousand_sep', \WC_Admin_Settings::get_option( 'woocommerce_price_thousand_sep' ) );
		$this->set_prop( 'price_decimal_sep', \WC_Admin_Settings::get_option( 'woocommerce_price_decimal_sep' ) );
		$this->set_prop( 'price_num_decimals', \WC_Admin_Settings::get_option( 'woocommerce_price_num_decimals' ) );
	}

	/**
	 *
	 */
	public function set_woocommerce_tax_settings() {
		$this->set_prop( 'prices_include_tax', \WC_Admin_Settings::get_option( 'woocommerce_prices_include_tax' ) );
		$this->set_prop( 'tax_based_on', 'base' ); // default should be base, perhaps have a setting for this?
		$this->set_prop( 'shipping_tax_class', \WC_Admin_Settings::get_option( 'woocommerce_shipping_tax_class' ) );
		$this->set_prop( 'tax_round_at_subtotal', \WC_Admin_Settings::get_option( 'woocommerce_tax_round_at_subtotal' ) );
		$this->set_prop( 'tax_display_shop', \WC_Admin_Settings::get_option( 'woocommerce_tax_display_shop' ) );
		$this->set_prop( 'tax_display_cart', \WC_Admin_Settings::get_option( 'woocommerce_tax_display_cart' ) );
		$this->set_prop( 'price_display_suffix', \WC_Admin_Settings::get_option( 'woocommerce_price_display_suffix' ) );
		$this->set_prop( 'tax_total_display', \WC_Admin_Settings::get_option( 'woocommerce_tax_total_display' ) );

		// tax address is same as WooCommerce address by default.
		$country_state_array = wc_format_country_state_string( \WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );

		$this->set_prop(
			'tax_address',
			array(
				'country' => $country_state_array['country'],
				'state' => $country_state_array['state'],
				'postcode' => \WC_Admin_Settings::get_option( 'woocommerce_store_postcode' ),
				'city' => \WC_Admin_Settings::get_option( 'woocommerce_store_city' ),
			)
		);
	}

	/**
	 *
	 */
	public function set_woocommerce_pos_settings() {
		$this->set_prop( 'default_customer', woocommerce_pos_get_settings( 'general', 'default_customer' ) );
		$this->set_prop( 'default_customer_is_cashier', woocommerce_pos_get_settings( 'general', 'default_customer_is_cashier' ) );
	}

	/**
	 * Get Store name.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_name( $context = 'view' ) {
		return $this->get_prop( 'name', $context );
	}

	/**
	 * Get Store locale.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_locale( $context = 'view' ) {
		return $this->get_prop( 'locale', $context );
	}

	/**
	 * Get Store address.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_address( $context = 'view' ) {
		return $this->get_prop( 'store_address', $context );
	}

	/**
	 * Get Store address 2.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_address_2( $context = 'view' ) {
		return $this->get_prop( 'store_address_2', $context );
	}

	/**
	 * Get Store city.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_city( $context = 'view' ) {
		return $this->get_prop( 'store_city', $context );
	}

	/**
	 * Get Store postcode.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_postcode( $context = 'view' ) {
		return $this->get_prop( 'store_postcode', $context );
	}

	/**
	 * Get Store country, eg: US:AL.
	 * This is of the form COUNTRYCODE:STATECODE to be consistent with the WooCommerce settings.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_default_country( $context = 'view' ) {
		return $this->get_prop( 'default_country', $context );
	}

	/**
	 * Get Store state code.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_state( $context = 'view' ) {
		return $this->get_prop( 'store_state', $context );
	}

	/**
	 * Get Store country code.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_store_country( $context = 'view' ) {
		return $this->get_prop( 'store_country', $context );
	}

	/**
	 * Get Store customer address.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_default_customer_address( $context = 'view' ) {
		return $this->get_prop( 'default_customer_address', $context );
	}

	/**
	 * Get Store calculate taxes.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_calc_taxes( $context = 'view' ) {
		return $this->get_prop( 'calc_taxes', $context );
	}

	/**
	 * Get Store enable coupons.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_enable_coupons( $context = 'view' ) {
		return $this->get_prop( 'enable_coupons', $context );
	}

	/**
	 * Get Store calculate discounts sequentially.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_calc_discounts_sequentially( $context = 'view' ) {
		return $this->get_prop( 'calc_discounts_sequentially', $context );
	}

	/**
	 * Get Store currency.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_currency( $context = 'view' ) {
		return $this->get_prop( 'currency', $context );
	}

	/**
	 * Get Store currency position.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_currency_position( $context = 'view' ) {
		return $this->get_prop( 'currency_pos', $context );
	}

	/**
	 * Get Store price thousand separator.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_price_thousand_separator( $context = 'view' ) {
		return $this->get_prop( 'price_thousand_sep', $context );
	}

	/**
	 * Get Store price decimal separator.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_price_decimal_separator( $context = 'view' ) {
		return $this->get_prop( 'price_decimal_sep', $context );
	}

	/**
	 * Get Store price number of decimals.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return int
	 */
	public function get_price_number_of_decimals( $context = 'view' ) {
		return $this->get_prop( 'price_num_decimals', $context );
	}

	/**
	 * Get Store prices include tax.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_prices_include_tax( $context = 'view' ) {
		return $this->get_prop( 'prices_include_tax', $context );
	}

	/**
	 * Get Store tax based on.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_tax_based_on( $context = 'view' ) {
		return $this->get_prop( 'tax_based_on', $context );
	}

	/**
	 * Get Store tax address.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return array
	 */
	public function get_tax_address( $context = 'view' ) {
		return $this->get_prop( 'tax_address', $context );
	}

	/**
	 * Get Store shipping tax class.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_shipping_tax_class( $context = 'view' ) {
		return $this->get_prop( 'shipping_tax_class', $context );
	}

	/**
	 * Get Store tax round at subtotal.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_tax_round_at_subtotal( $context = 'view' ) {
		return $this->get_prop( 'tax_round_at_subtotal', $context );
	}

	/**
	 * Get Store tax display shop.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_tax_display_shop( $context = 'view' ) {
		return $this->get_prop( 'tax_display_shop', $context );
	}

	/**
	 * Get Store tax display cart.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_tax_display_cart( $context = 'view' ) {
		return $this->get_prop( 'tax_display_cart', $context );
	}

	/**
	 * Get Store price display suffix.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_price_display_suffix( $context = 'view' ) {
		return $this->get_prop( 'price_display_suffix', $context );
	}

	/**
	 * Get Store tax total display.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string
	 */
	public function get_tax_total_display( $context = 'view' ) {
		return $this->get_prop( 'tax_total_display', $context );
	}

		/**
		 * Get Store default customer.
		 *
		 * @param  string $context What the value is for. Valid values are view and edit.
		 * @return int
		 */
	public function get_default_customer( $context = 'view' ) {
		return $this->get_prop( 'default_customer', $context );
	}

	/**
	 * Get Store default customer is cashier.
	 *
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return bool
	 */
	public function get_default_customer_is_cashier( $context = 'view' ) {
		return $this->get_prop( 'default_customer_is_cashier', $context );
	}
}
