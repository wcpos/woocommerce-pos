<?php

namespace WCPOS\WooCommercePOS\API;

use WC_Admin_Settings;

class Stores extends Abstracts\Controller {
	/**
	 * Stores constructor.
	 */
	public function __construct() {
	}


	public function register_routes(): void {
		register_rest_route($this->namespace, '/stores', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stores' ),
			'permission_callback' => '__return_true',
		));
	}

	/**
	 * @TODO
	 * @return
	 */
	public function get_stores() {
		$data = array(
			$this->get_store(),
		);

		/**
		 *
		 */
		return apply_filters( 'woocommerce_pos_stores', $data, $this );
	}



	public function get_store(): array {
		return array_merge(
			array(
				'id'     => 0,
				'name'   => get_option( 'blogname' ),
				'locale' => get_locale(),
			),
			array(
				/**
				 * Get POS Settings
				 */
				'default_customer' => woocommerce_pos_get_settings( 'general', 'default_customer' ),
				'default_customer_is_cashier' => woocommerce_pos_get_settings( 'general', 'default_customer_is_cashier' ),
				/**
				 * Get the General settings from WooCommerce
				 */
				'store_address' => WC_Admin_Settings::get_option( 'woocommerce_store_address' ),
				'store_address_2' => WC_Admin_Settings::get_option( 'woocommerce_store_address_2' ),
				'store_city' => WC_Admin_Settings::get_option( 'woocommerce_store_city' ),
				'default_country' => WC_Admin_Settings::get_option( 'woocommerce_default_country' ),
				'store_postcode' => WC_Admin_Settings::get_option( 'woocommerce_store_postcode' ),
				'default_customer_address' => WC_Admin_Settings::get_option( 'woocommerce_default_customer_address' ),
				'calc_taxes' => WC_Admin_Settings::get_option( 'woocommerce_calc_taxes' ),
				'enable_coupons' => WC_Admin_Settings::get_option( 'woocommerce_enable_coupons' ),
				'calc_discounts_sequentially' => WC_Admin_Settings::get_option( 'woocommerce_calc_discounts_sequentially' ),
				'currency' => WC_Admin_Settings::get_option( 'woocommerce_currency' ),
				'currency_pos' => WC_Admin_Settings::get_option( 'woocommerce_currency_pos' ),
				'price_thousand_sep' => WC_Admin_Settings::get_option( 'woocommerce_price_thousand_sep' ),
				'price_decimal_sep' => WC_Admin_Settings::get_option( 'woocommerce_price_decimal_sep' ),
				'price_num_decimals' => WC_Admin_Settings::get_option( 'woocommerce_price_num_decimals' ),
				/**
				 * Get the Tax settings from WooCommerce
				 */
				'prices_include_tax' => WC_Admin_Settings::get_option( 'woocommerce_prices_include_tax' ),
				'tax_based_on' => 'base', // default should be base, perhaps have a setting for this?
				'shipping_tax_class' => WC_Admin_Settings::get_option( 'woocommerce_shipping_tax_class' ),
				'tax_round_at_subtotal' => WC_Admin_Settings::get_option( 'woocommerce_tax_round_at_subtotal' ),
				'tax_display_shop' => WC_Admin_Settings::get_option( 'woocommerce_tax_display_shop' ),
				'tax_display_cart' => WC_Admin_Settings::get_option( 'woocommerce_tax_display_cart' ),
				'price_display_suffix' => WC_Admin_Settings::get_option( 'woocommerce_price_display_suffix' ),
				'tax_total_display' => WC_Admin_Settings::get_option( 'woocommerce_tax_total_display' ),
			)
		);
	}
}
