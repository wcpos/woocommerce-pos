<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Stores;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Stores_API extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Stores();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	public function test_rest_base(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );

		$this->assertEquals( 'stores', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/stores', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
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
			'_links',
		);
	}

	public function test_stores_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/stores' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$stores = $response->get_data();
		$this->assertIsArray( $stores );
		$this->assertEquals( 1, \count( $stores ) );
		$data = $stores[0];

		$response_fields = array_keys( $data );
		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );
		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}
}
