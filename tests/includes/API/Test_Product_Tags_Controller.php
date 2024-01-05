<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Product_Tags_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Tags_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Product_Tags_Controller();
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

		$this->assertEquals( 'products/tags', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/products/tags', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/tags/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/tags/batch', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
		return array(
			'id',
			'name',
			'slug',
			'description',
			'count',
			// woocommerce pos
			'uuid',
		);
	}

	public function test_product_tag_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$tag             = ProductHelper::create_product_tag( 'Music' );
		$request         = $this->wp_rest_get_request( '/wcpos/v1/products/tags/' . $tag['term_id'] );
		$response        = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_product_tag_api_get_all_ids(): void {
		$tag1        = ProductHelper::create_product_tag( 'Music' );
		$tag2        = ProductHelper::create_product_tag( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/tags' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 2, \count( $data ) );
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $tag1['term_id'], $tag2['term_id'] ), $ids );
	}

	/**
	 * Each category needs a UUID.
	 */
	public function test_product_tag_response_contains_uuid_meta_data(): void {
		$tag             = ProductHelper::create_product_tag( 'Music' );
		$request         = $this->wp_rest_get_request( '/wcpos/v1/products/tags/' . $tag['term_id'] );
		$response        = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( Uuid::isValid( $data['uuid'] ), 'The UUID value is not valid.' );
	}

	/**
	 *
	 */
	public function test_product_tag_includes() {
		$tag1        = ProductHelper::create_product_tag( 'Music' );
		$tag2        = ProductHelper::create_product_tag( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/tags' );
		$request->set_param( 'include', $tag1['term_id'] );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $tag1['term_id'], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_product_tag_excludes() {
		$tag1        = ProductHelper::create_product_tag( 'Music' );
		$tag2        = ProductHelper::create_product_tag( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/tags' );
		$request->set_param( 'exclude', $tag1['term_id'] );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $tag2['term_id'], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_product_tag_search_with_includes() {
		$tag1        = ProductHelper::create_product_tag( 'Music1' );
		$tag2        = ProductHelper::create_product_tag( 'Music2' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/tags' );
		$request->set_param( 'include', $tag1['term_id'] );
		$request->set_param( 'search', 'Music' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $tag1['term_id'], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_product_tag_search_with_excludes() {
		$tag1        = ProductHelper::create_product_tag( 'Music1' );
		$tag2        = ProductHelper::create_product_tag( 'Music2' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/tags' );
		$request->set_param( 'exclude', $tag1['term_id'] );
		$request->set_param( 'search', 'Music' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $tag2['term_id'], $data[0]['id'] );
	}
}
