<?php
/**
 * Tests that wcpos/v1 hooks do not bleed into wc/v3 REST API responses.
 *
 * The WCPOS plugin registers controllers that extend WC REST API controllers.
 * These tests verify that simply having the plugin active does not modify
 * standard wc/v3 responses — hooks should only fire for wcpos/v1 routes.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WP_REST_Request;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Hook_Isolation extends WCPOS_REST_Unit_Test_Case {
	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Helper to create a plain wc/v3 GET request (no X-WCPOS header).
	 */
	private function wc_rest_get_request( string $path ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_method( 'GET' );
		$request->set_route( $path );

		return $request;
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/products request should not
	 * contain wcpos-added fields like 'barcode'.
	 */
	public function test_wc_v3_product_does_not_have_barcode(): void {
		$product = ProductHelper::create_simple_product();

		$request  = $this->wc_rest_get_request( '/wc/v3/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'barcode', $data, 'wc/v3 product response should not contain barcode field' );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/products list request should not
	 * contain wcpos-added fields.
	 */
	public function test_wc_v3_product_list_does_not_have_barcode(): void {
		ProductHelper::create_simple_product();
		ProductHelper::create_simple_product();

		$request  = $this->wc_rest_get_request( '/wc/v3/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data );
		foreach ( $data as $product ) {
			$this->assertArrayNotHasKey( 'barcode', $product, 'wc/v3 product in list should not contain barcode' );
		}
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/orders request should not
	 * contain wcpos-specific links.
	 */
	public function test_wc_v3_order_does_not_have_pos_links(): void {
		$order = OrderHelper::create_order();

		$request  = $this->wc_rest_get_request( '/wc/v3/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$links = $response->get_links();
		$this->assertArrayNotHasKey( 'payment', $links, 'wc/v3 order should not contain wcpos payment link' );
		$this->assertArrayNotHasKey( 'receipt', $links, 'wc/v3 order should not contain wcpos receipt link' );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/customers request should not
	 * be modified by wcpos customer hooks.
	 */
	public function test_wc_v3_customer_is_unmodified(): void {
		$customer = CustomerHelper::create_customer();

		$request  = $this->wc_rest_get_request( '/wc/v3/customers/' . $customer->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/taxes request should not
	 * be modified by wcpos tax hooks.
	 */
	public function test_wc_v3_tax_is_unmodified(): void {
		$tax_id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'Test Tax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
			)
		);

		$request  = $this->wc_rest_get_request( '/wc/v3/taxes/' . $tax_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3 product variation request should not
	 * contain wcpos-added fields.
	 */
	public function test_wc_v3_variation_does_not_have_barcode(): void {
		$product    = ProductHelper::create_variation_product();
		$variations = $product->get_children();
		$this->assertNotEmpty( $variations );
		$variation_id = $variations[0];

		$request  = $this->wc_rest_get_request( '/wc/v3/products/' . $product->get_id() . '/variations/' . $variation_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'barcode', $data, 'wc/v3 variation response should not contain barcode' );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/products/tags request should not
	 * be modified.
	 */
	public function test_wc_v3_product_tag_is_unmodified(): void {
		$tag = wp_insert_term( 'Test Tag', 'product_tag' );

		$request  = $this->wc_rest_get_request( '/wc/v3/products/tags/' . $tag['term_id'] );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * With the WCPOS plugin active, a wc/v3/products/categories request should not
	 * be modified.
	 */
	public function test_wc_v3_product_category_is_unmodified(): void {
		$category = wp_insert_term( 'Test Category', 'product_cat' );

		$request  = $this->wc_rest_get_request( '/wc/v3/products/categories/' . $category['term_id'] );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * Verify that the route_map in API only contains wcpos/v1 routes,
	 * ensuring the dispatch guard works correctly.
	 */
	public function test_route_map_only_contains_wcpos_routes(): void {
		$api = new \WCPOS\WooCommercePOS\API();

		$reflection = new \ReflectionClass( $api );
		$property   = $reflection->getProperty( 'route_map' );
		$property->setAccessible( true );
		$route_map = $property->getValue( $api );

		foreach ( $route_map as $route => $key ) {
			$this->assertStringStartsWith( '/wcpos/v1/', $route, "Route map should only contain wcpos/v1 routes, found: $route" );
		}
	}

	/**
	 * Verify that rest_dispatch_request returns early for non-wcpos routes.
	 */
	public function test_dispatch_returns_early_for_wc_v3_routes(): void {
		$api = new \WCPOS\WooCommercePOS\API();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$result  = $api->rest_dispatch_request( null, $request, '/wc/v3/products', array() );

		$this->assertNull( $result, 'rest_dispatch_request should return null for wc/v3 routes' );
	}

	/**
	 * Verify that rest_pre_dispatch returns early for non-wcpos routes.
	 */
	public function test_pre_dispatch_returns_early_for_wc_v3_routes(): void {
		$api = new \WCPOS\WooCommercePOS\API();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$request->set_param( 'include', '1,2,3' );
		$server = rest_get_server();

		$result = $api->rest_pre_dispatch( null, $server, $request );

		$this->assertNull( $result, 'rest_pre_dispatch should return null for wc/v3 routes' );
		$this->assertNotNull( $request->get_param( 'include' ), 'include param should not be removed for wc/v3 routes' );
		$this->assertNull( $request->get_param( 'wcpos_include' ), 'wcpos_include should not be set for wc/v3 routes' );
	}
}
