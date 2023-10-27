<?php
/**
 * Customer helper.
 */

namespace Automattic\WooCommerce\RestApi\UnitTests\Helpers;

\defined( 'ABSPATH' ) || exit;

use WC_Customer;

/**
 * Class CustomerHelper.
 *
 * This helper class should ONLY be used for unit tests!.
 */
class CustomerHelper {
	/**
	 * Create a mock customer for testing purposes.
	 *
	 * @return WC_Customer
	 */
	public static function create_mock_customer() {
		$customer_data = array(
			'id'                  => 0,
			'date_modified'       => null,
			'country'             => 'US',
			'state'               => 'CA',
			'postcode'            => '94110',
			'city'                => 'San Francisco',
			'address'             => '123 South Street',
			'address_2'           => 'Apt 1',
			'shipping_country'    => 'US',
			'shipping_state'      => 'CA',
			'shipping_postcode'   => '94110',
			'shipping_city'       => 'San Francisco',
			'shipping_address'    => '123 South Street',
			'shipping_address_2'  => 'Apt 1',
			'is_vat_exempt'       => false,
			'calculated_shipping' => false,
		);

		self::set_customer_details( $customer_data );

		return new WC_Customer( 0, true );
	}

	/**
	 * Creates a customer in the tests DB.
	 *
	 * @param array $args Associative array of customer properties.
	 */
	public static function create_customer($args = array()) {
		$random_suffix = wp_generate_password( 8, false );

		$defaults = array(
			'username'           => 'testcustomer_' . $random_suffix,
			'password'           => $random_suffix,
			'email'              => 'test' . $random_suffix . '@woo.local',
			'first_name'         => 'Justin',
			'billing_country'    => 'US',
			'billing_state'      => 'CA',
			'billing_postcode'   => '94110',
			'billing_city'       => 'San Francisco',
			'billing_address'    => '123 South Street',
			'billing_address_2'  => 'Apt 1',
			'shipping_country'   => 'US',
			'shipping_state'     => 'CA',
			'shipping_postcode'  => '94110',
			'shipping_city'      => 'San Francisco',
			'shipping_address'   => '123 South Street',
			'shipping_address_2' => 'Apt 1',
		);

		$args = wp_parse_args($args, $defaults);

		$customer = new WC_Customer();
		foreach ($args as $key => $value) {
			if (method_exists($customer, "set_{$key}")) {
				\call_user_func(array($customer, "set_{$key}"), $value);
			}
		}

		$customer->save();

		return $customer;
	}


	/**
	 * Get the expected output for the store's base location settings.
	 *
	 * @return array
	 */
	public static function get_expected_store_location() {
		return array( 'US', 'CA', '', '' );
	}

	/**
	 * Get the customer's shipping and billing info from the session.
	 *
	 * @return array
	 */
	public static function get_customer_details() {
		return WC()->session->get( 'customer' );
	}

	/**
	 * Get the user's chosen shipping method.
	 *
	 * @return array
	 */
	public static function get_chosen_shipping_methods() {
		return WC()->session->get( 'chosen_shipping_methods' );
	}

	/**
	 * Get the "Tax Based On" WooCommerce option.
	 *
	 * @return string base or billing
	 */
	public static function get_tax_based_on() {
		return get_option( 'woocommerce_tax_based_on' );
	}

	/**
	 * Set the the current customer's billing details in the session.
	 *
	 * @param string $default_shipping_method Shipping Method slug
	 * @param mixed  $customer_details
	 */
	public static function set_customer_details( $customer_details ): void {
		WC()->session->set( 'customer', array_map( 'strval', $customer_details ) );
	}

	/**
	 * Set the user's chosen shipping method.
	 *
	 * @param string $chosen_shipping_method  Shipping Method slug
	 * @param mixed  $chosen_shipping_methods
	 */
	public static function set_chosen_shipping_methods( $chosen_shipping_methods ): void {
		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	/**
	 * Set the "Tax Based On" WooCommerce option.
	 *
	 * @param string $default_shipping_method Shipping Method slug
	 */
	public static function set_tax_based_on( $default_shipping_method ): void {
		update_option( 'woocommerce_tax_based_on', $default_shipping_method );
	}
}
