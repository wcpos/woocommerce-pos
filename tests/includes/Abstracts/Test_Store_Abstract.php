<?php

namespace WCPOS\WooCommercePOS\Tests\Abstracts;

use WCPOS\WooCommercePOS\Abstracts\Store;
use WP_UnitTestCase;

/**
 * Test double that mirrors Pro's ability to seed Store properties directly.
 */
class Store_With_Test_Tax_Ids extends Store {
	/**
	 * Seed structured tax IDs using the Store data property.
	 *
	 * @param array<int,array<string,string>> $tax_ids Tax IDs to seed.
	 */
	public function set_test_tax_ids( array $tax_ids ): void {
		$this->set_prop( 'tax_ids', $tax_ids );
	}
}

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Store_Abstract extends WP_UnitTestCase {
	private $store;
	private $original_default_country;
	private $original_general_settings;
	private $original_pos_store_name;
	private $original_pos_store_phone;
	private $original_pos_store_email;
	private $original_pos_refund_returns_policy;

	public function setUp(): void {
		parent::setUp();
		$this->original_default_country         = get_option( 'woocommerce_default_country', null );
		$this->original_general_settings        = get_option( 'woocommerce_pos_settings_general', null );
		$this->original_pos_store_name          = get_option( 'woocommerce_pos_store_name', null );
		$this->original_pos_store_phone         = get_option( 'woocommerce_pos_store_phone', null );
		$this->original_pos_store_email         = get_option( 'woocommerce_pos_store_email', null );
		$this->original_pos_refund_returns_policy = get_option( 'woocommerce_pos_refund_returns_policy', null );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( 'woocommerce_pos_store_name' );
		delete_option( 'woocommerce_pos_store_phone' );
		delete_option( 'woocommerce_pos_store_email' );
		delete_option( 'woocommerce_pos_refund_returns_policy' );
		$this->store = new Store();
	}

	public function tearDown(): void {
		update_option( 'woocommerce_default_country', $this->original_default_country );
		if ( null === $this->original_general_settings ) {
			delete_option( 'woocommerce_pos_settings_general' );
		} else {
			update_option( 'woocommerce_pos_settings_general', $this->original_general_settings );
		}
		if ( null === $this->original_pos_store_name ) {
			delete_option( 'woocommerce_pos_store_name' );
		} else {
			update_option( 'woocommerce_pos_store_name', $this->original_pos_store_name );
		}
		if ( null === $this->original_pos_store_phone ) {
			delete_option( 'woocommerce_pos_store_phone' );
		} else {
			update_option( 'woocommerce_pos_store_phone', $this->original_pos_store_phone );
		}
		if ( null === $this->original_pos_store_email ) {
			delete_option( 'woocommerce_pos_store_email' );
		} else {
			update_option( 'woocommerce_pos_store_email', $this->original_pos_store_email );
		}
		if ( null === $this->original_pos_refund_returns_policy ) {
			delete_option( 'woocommerce_pos_refund_returns_policy' );
		} else {
			update_option( 'woocommerce_pos_refund_returns_policy', $this->original_pos_refund_returns_policy );
		}
		unset( $this->store );
		parent::tearDown();
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
			// 'default_country',
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
			// Pro plugin fields (also present in free with default values)
			'url',
			'phone',
			'email',
			'opening_hours',
			'opening_hours_notes',
			'personal_notes',
			'policies_and_conditions',
			'footer_imprint',
			'tax_ids',
		);
	}

	public function test_store_api_get_all_fields(): void {
		$expected_data_props = $this->get_expected_data_props();
		$data                = $this->store->get_data();
		$props               = array_keys( $data );
		$this->assertEmpty( array_diff( $expected_data_props, $props ), 'These fields were expected but not present in Store: ' . print_r( array_diff( $expected_data_props, $props ), true ) );
		$this->assertEmpty( array_diff( $props, $expected_data_props ), 'These fields were not expected in Store: ' . print_r( array_diff( $props, $expected_data_props ), true ) );
	}

	public function test_get_store_id(): void {
		$store_id = $this->store->get_id();
		$this->assertIsInt( $store_id );
		$this->assertEquals( 0, $store_id );
	}

	public function test_get_store_name_falls_back_to_blog_name_when_unset(): void {
		// Test uses the real get_bloginfo function from WordPress test environment.
		$store_name = $this->store->get_name();
		$this->assertIsString( $store_name );
		$this->assertEquals( 'Test Blog', $store_name );
	}

	public function test_get_store_name_falls_back_to_wc_pos_option_when_setting_blank(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		$store = new Store();
		$this->assertEquals( 'WC POS Store', $store->get_name() );
	}

	public function test_get_store_name_uses_wcpos_setting_when_present(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_name' => 'My WCPOS Store' )
		);
		$store = new Store();
		$this->assertEquals( 'My WCPOS Store', $store->get_name() );
	}

	public function test_get_phone_falls_back_to_wc_pos_option(): void {
		update_option( 'woocommerce_pos_store_phone', '+1 555 0100' );
		$store = new Store();
		$this->assertEquals( '+1 555 0100', $store->get_phone() );
	}

	public function test_get_phone_uses_wcpos_setting_when_present(): void {
		update_option( 'woocommerce_pos_store_phone', '+1 555 0100' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_phone' => '+44 20 7946 0958' )
		);
		$store = new Store();
		$this->assertEquals( '+44 20 7946 0958', $store->get_phone() );
	}

	public function test_get_email_falls_back_to_wc_pos_option(): void {
		update_option( 'woocommerce_pos_store_email', 'pos@example.com' );
		$store = new Store();
		$this->assertEquals( 'pos@example.com', $store->get_email() );
	}

	public function test_get_email_uses_wcpos_setting_when_present(): void {
		update_option( 'woocommerce_pos_store_email', 'pos@example.com' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_email' => 'shop@example.com' )
		);
		$store = new Store();
		$this->assertEquals( 'shop@example.com', $store->get_email() );
	}

	public function test_get_policies_and_conditions_falls_back_to_wc_pos_option(): void {
		update_option( 'woocommerce_pos_refund_returns_policy', 'No refunds.' );
		$store = new Store();
		$this->assertEquals( 'No refunds.', $store->get_policies_and_conditions() );
	}

	public function test_get_policies_and_conditions_uses_wcpos_setting_when_present(): void {
		update_option( 'woocommerce_pos_refund_returns_policy', 'No refunds.' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'policies_and_conditions' => 'Returns within 30 days.' )
		);
		$store = new Store();
		$this->assertEquals( 'Returns within 30 days.', $store->get_policies_and_conditions() );
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
		$this->assertIsInt( $decimals );
		$this->assertEquals( 2, $decimals ); // Default value
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

	public function test_get_tax_ids_returns_empty_when_settings_blank(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		$store = new Store();
		$this->assertSame( array(), $store->get_tax_ids() );
		$this->assertSame( '', $store->get_tax_id() );
	}

	public function test_get_tax_ids_uses_general_store_tax_ids(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array(
						'type'    => 'eu_vat',
						'value'   => 'DE123456789',
						'country' => 'DE',
					),
					array(
						'type'    => 'de_steuernummer',
						'value'   => '12/345/67890',
						'country' => 'DE',
						'label'   => 'Steuernummer',
					),
					array(
						'type'  => 'de_hrb',
						'value' => 'HRB 12345',
					),
				),
			)
		);

		$store   = new Store();
		$tax_ids = $store->get_tax_ids();

		$this->assertSame(
			array(
				array(
					'type'    => 'eu_vat',
					'value'   => 'DE123456789',
					'country' => 'DE',
				),
				array(
					'type'    => 'de_steuernummer',
					'value'   => '12/345/67890',
					'country' => 'DE',
					'label'   => 'Steuernummer',
				),
				array(
					'type'  => 'de_hrb',
					'value' => 'HRB 12345',
				),
			),
			$tax_ids
		);
		$this->assertSame( 'DE123456789', $store->get_tax_id() );
	}

	public function test_get_tax_ids_drops_malformed_entries(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array( 'type' => 'eu_vat' ), // missing value
					array( 'value' => 'orphan' ), // missing type
					array(
						'type'  => 'de_steuernummer',
						'value' => '12/345/67890',
					),
				),
			)
		);

		$store = new Store();
		$this->assertSame(
			array(
				array(
					'type'  => 'de_steuernummer',
					'value' => '12/345/67890',
				),
			),
			$store->get_tax_ids()
		);
	}

	public function test_get_tax_id_uses_first_entry_for_non_zero_keys(): void {
		$store = new Store_With_Test_Tax_Ids();
		$store->set_test_tax_ids(
			array(
				7 => array(
					'type'  => 'eu_vat',
					'value' => 'DE123456789',
				),
			)
		);

		$this->assertSame( 'DE123456789', $store->get_tax_id() );
	}
}
