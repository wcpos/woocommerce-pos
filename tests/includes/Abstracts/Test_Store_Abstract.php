<?php

namespace WCPOS\WooCommercePOS\Tests\Abstracts;

use WCPOS\WooCommercePOS\Abstracts\Store;
use WP_UnitTestCase;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Store_Abstract extends WP_UnitTestCase {
	private $store;

	public function setUp(): void {
		parent::setUp();
		$this->store = new Store();
	}

	public function tearDown(): void {
		parent::tearDown();
		unset( $this->store );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_data_props() {
		return array(
			'id',
			'name',
			'locale',
			'default_customer',
			'default_customer_is_cashier',
			'store_address',
			'store_address_2',
			'store_city',
			'store_state',
			'store_country',
			'tax_address',
			'default_country',
			'store_postcode',
			'default_customer_address',
			'calc_taxes',
			'enable_coupons',
			'calc_discounts_sequentially',
			'currency',
			'currency_pos',
			'price_thousand_sep',
			'price_decimal_sep',
			'price_num_decimals',
			'prices_include_tax',
			'tax_based_on',
			'shipping_tax_class',
			'tax_round_at_subtotal',
			'tax_display_shop',
			'tax_display_cart',
			'price_display_suffix',
			'tax_total_display',
			'meta_data',
		);
	}

	public function test_product_api_get_all_fields(): void {
		$expected_data_props = $this->get_expected_data_props();
		$data = $this->store->get_data();
		$props = array_keys( $data );
		$this->assertEmpty( array_diff( $expected_data_props, $props ), 'These fields were expected but not present in Store: ' . print_r( array_diff( $expected_data_props, $props ), true ) );
		$this->assertEmpty( array_diff( $props, $expected_data_props ), 'These fields were not expected in Store: ' . print_r( array_diff( $props, $expected_data_props ), true ) );
	}

	public function test_get_store_id(): void {
		$store_id = $this->store->get_id();
		$this->assertIsInt( $store_id );
		$this->assertEquals( 0, $store_id );
	}

	public function test_get_store_name(): void {
		// @TODO - mocking functions doesn't work, I don't know why?
		FunctionsMockerHack::add_function_mocks(
			array(
				'get_bloginfo' => function ( $show = '' ) {
					echo "Mocked get_bloginfo called with parameter: $show\n";
					return 'Mocked Store Name';
				},
			)
		);

		$store_name = $this->store->get_name();
		$this->assertIsString( $store_name );
		$this->assertEquals( 'Test Blog', $store_name );
	}

	public function test_get_store_locale(): void {
		$store_locale = $this->store->get_locale();
		$this->assertIsString( $store_locale );
		$this->assertEquals( 'en_US', $store_locale );
	}

	public function test_get_store_address(): void {
		$store_address = $this->store->get_store_address();
		$this->assertIsString( $store_address );
		$this->assertEquals( '', $store_address ); // Assuming default is empty string
	}

	public function test_get_store_address_2(): void {
		$store_address_2 = $this->store->get_store_address_2();
		$this->assertIsString( $store_address_2 );
		$this->assertEquals( '', $store_address_2 ); // Assuming default is empty string
	}

	public function test_get_store_city(): void {
		$store_city = $this->store->get_store_city();
		$this->assertIsString( $store_city );
		$this->assertEquals( '', $store_city ); // Assuming default is empty string
	}

	public function test_get_default_country(): void {
		// Assuming you have a way to set these values or mock them
		$store_country = $this->store->get_default_country();
		$this->assertIsString( $store_country );
		$this->assertEquals( 'US:CA', $store_country );
	}

	public function test_get_store_postcode(): void {
		$store_postcode = $this->store->get_store_postcode();
		$this->assertIsString( $store_postcode );
		$this->assertEquals( '', $store_postcode ); // Assuming default is empty string
	}

	public function test_get_default_customer_address(): void {
		$store_customer_address = $this->store->get_default_customer_address();
		$this->assertIsString( $store_customer_address );
		$this->assertEquals( 'base', $store_customer_address ); // Assuming default is empty string
	}

	public function test_get_calc_taxes(): void {
		$calc_taxes = $this->store->get_calc_taxes();
		$this->assertIsString( $calc_taxes );
		$this->assertEquals( 'no', $calc_taxes ); // Default value
	}

	public function test_get_enable_coupons(): void {
		$enable_coupons = $this->store->get_enable_coupons();
		$this->assertIsString( $enable_coupons );
		$this->assertEquals( 'yes', $enable_coupons ); // Default value
	}

	public function test_get_calc_discounts_sequentially(): void {
		$calc_discounts_sequentially = $this->store->get_calc_discounts_sequentially();
		$this->assertIsString( $calc_discounts_sequentially );
		$this->assertEquals( 'no', $calc_discounts_sequentially ); // Default value
	}

	public function test_get_currency(): void {
		$currency = $this->store->get_currency();
		$this->assertIsString( $currency );
		$this->assertEquals( 'USD', $currency ); // Default value
	}

	public function test_get_currency_position(): void {
		$currency_pos = $this->store->get_currency_position();
		$this->assertIsString( $currency_pos );
		$this->assertEquals( 'left', $currency_pos ); // Default value
	}

	public function test_get_price_thousand_separator(): void {
		$separator = $this->store->get_price_thousand_separator();
		$this->assertIsString( $separator );
		$this->assertEquals( ',', $separator ); // Default value
	}

	public function test_get_price_decimal_separator(): void {
		$separator = $this->store->get_price_decimal_separator();
		$this->assertIsString( $separator );
		$this->assertEquals( '.', $separator ); // Default value
	}

	public function test_get_price_number_of_decimals(): void {
		$decimals = $this->store->get_price_number_of_decimals();
		$this->assertIsString( $decimals );
		$this->assertEquals( '2', $decimals ); // Default value
	}

	public function test_get_prices_include_tax(): void {
		$include_tax = $this->store->get_prices_include_tax();
		$this->assertIsString( $include_tax );
		$this->assertEquals( 'no', $include_tax ); // Default value
	}

	public function test_get_tax_based_on(): void {
		$tax_based_on = $this->store->get_tax_based_on();
		$this->assertIsString( $tax_based_on );
		$this->assertEquals( 'base', $tax_based_on ); // Default value
	}

	public function test_get_shipping_tax_class(): void {
		$tax_class = $this->store->get_shipping_tax_class();
		$this->assertIsString( $tax_class );
		$this->assertEquals( 'inherit', $tax_class ); // Default value
	}

	public function test_get_tax_round_at_subtotal(): void {
		$round_at_subtotal = $this->store->get_tax_round_at_subtotal();
		$this->assertIsString( $round_at_subtotal );
		$this->assertEquals( 'no', $round_at_subtotal ); // Default value
	}

	public function test_get_tax_display_shop(): void {
		$tax_display_shop = $this->store->get_tax_display_shop();
		$this->assertIsString( $tax_display_shop );
		$this->assertEquals( 'excl', $tax_display_shop ); // Default value
	}

	public function test_get_tax_display_cart(): void {
		$tax_display_cart = $this->store->get_tax_display_cart();
		$this->assertIsString( $tax_display_cart );
		$this->assertEquals( 'excl', $tax_display_cart ); // Default value
	}

	public function test_get_price_display_suffix(): void {
		$display_suffix = $this->store->get_price_display_suffix();
		$this->assertIsString( $display_suffix );
		$this->assertEquals( '', $display_suffix ); // Default value
	}

	public function test_get_tax_total_display(): void {
		$tax_total_display = $this->store->get_tax_total_display();
		$this->assertIsString( $tax_total_display );
		$this->assertEquals( 'itemized', $tax_total_display ); // Default value
	}

	public function test_get_default_customer(): void {
		$customer = $this->store->get_default_customer();
		$this->assertIsInt( $customer );
		$this->assertEquals( 0, $customer ); // Default value
	}

	public function test_get_default_customer_is_cashier(): void {
		$is_cashier = $this->store->get_default_customer_is_cashier();
		$this->assertIsBool( $is_cashier );
		$this->assertFalse( $is_cashier ); // Default value
	}
}
