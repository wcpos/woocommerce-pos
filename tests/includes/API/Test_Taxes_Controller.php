<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Taxes_Controller;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Taxes_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Taxes_Controller();
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

		$this->assertEquals( 'taxes', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/taxes', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/taxes/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/taxes/batch', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
		return array(
			'uuid',
			'id',
			'country',
			'state',
			'postcode',
			'city',
			'postcodes',
			'cities',
			'rate',
			'name',
			'priority',
			'compound',
			'shipping',
			'order',
			'class',
		);
	}

	public function test_taxes_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$tax_id   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'NY',
				'rate'    => '8.375',
				'name'    => 'NY Tax',
			)
		);
		$request    = $this->wp_rest_get_request( '/wcpos/v1/taxes/' . $tax_id );
		$response   = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_taxes_api_get_all_ids(): void {
		$gb_tax_ids = TaxHelper::create_sample_tax_rates_GB();
		$us_tax_ids = TaxHelper::create_sample_tax_rates_US();

		$request     = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 5, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array_merge( $gb_tax_ids, $us_tax_ids ), $ids );
	}

	/**
	 * The Tax endpoint is not accessible by cashiers by default.
	 */
	public function test_taxes_api_get_for_cashier() {
		$cashier_user_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_user_id );

		$gb_tax_ids = TaxHelper::create_sample_tax_rates_GB();
		$request     = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 3, \count( $data ) );

		wp_set_current_user( 0 );
	}

	/**
	 * The WC REST API does not support the include param.
	 * This test is to ensure that the include param is supported in the WCPOS API.
	 */
	public function test_include_and_exclude_param(): void {
		$tax_id1   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'NY',
				'rate'    => '8.375',
				'name'    => 'NY Tax',
			)
		);
		$tax_id2   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '7.25',
				'name'    => 'CA Tax',
			)
		);
		$tax_id3   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'FL',
				'rate'    => '6.00',
				'name'    => 'FL Tax',
			)
		);
		$tax_id4   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'TX',
				'rate'    => '6.25',
				'name'    => 'TX Tax',
			)
		);
		$tax_id5   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'WA',
				'rate'    => '6.50',
				'name'    => 'WA Tax',
			)
		);

		$request    = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$request->set_param( 'include', array( $tax_id2, $tax_id4 ) );
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $tax_id2, $tax_id4 ), $ids );

		$request    = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$request->set_param( 'exclude', array( $tax_id2, $tax_id4 ) );
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $tax_id1, $tax_id3, $tax_id5 ), $ids );
	}

	/**
	 *
	 */
	public function test_include_and_exclude_param_with_class(): void {
		$tax_id1   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'NY',
				'rate'    => '8.375',
				'name'    => 'NY Tax',
				'class'   => 'reduced-rate',
			)
		);
		$tax_id2   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '7.25',
				'name'    => 'CA Tax',
			)
		);
		$tax_id3   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'FL',
				'rate'    => '6.00',
				'name'    => 'FL Tax',
				'class'   => 'reduced-rate',
			)
		);
		$tax_id4   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'TX',
				'rate'    => '6.25',
				'name'    => 'TX Tax',
			)
		);
		$tax_id5   = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'WA',
				'rate'    => '6.50',
				'name'    => 'WA Tax',
				'class'   => 'reduced-rate',
			)
		);

		$request    = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$request->set_param( 'include', array( $tax_id1, $tax_id2 ) );
		$request->set_param( 'class', 'reduced-rate' );
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $tax_id1 ), $ids );

		$request    = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$request->set_param( 'exclude', array( $tax_id1, $tax_id2 ) );
		$request->set_param( 'class', 'reduced-rate' );
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $tax_id3, $tax_id5 ), $ids );
	}
}
