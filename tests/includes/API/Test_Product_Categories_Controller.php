<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Product_Categories_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Categories_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Product_Categories_Controller();
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

		$this->assertEquals( 'products/categories', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/products/categories', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/categories/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/categories/batch', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
		return array(
			'id',
			'name',
			'slug',
			'parent',
			'description',
			'display',
			'image',
			'menu_order',
			'count',
			// woocommerce pos
			'uuid',
		);
	}

	public function test_product_category_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$category        = ProductHelper::create_product_category( 'Music' );
		$request         = $this->wp_rest_get_request( '/wcpos/v1/products/categories/' . $category['term_id'] );
		$response        = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_product_category_api_get_all_ids(): void {
		$cat1        = ProductHelper::create_product_category( 'Music' );
		$cat2        = ProductHelper::create_product_category( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 3, \count( $data ) ); // there is one cat id install in test setup
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertContains( $cat1['term_id'], $ids );
		$this->assertContains( $cat2['term_id'], $ids );
	}

	/**
	 * Each category needs a UUID.
	 */
	public function test_product_category_response_contains_uuid_meta_data(): void {
		$category        = ProductHelper::create_product_category( 'Music' );
		$request         = $this->wp_rest_get_request( '/wcpos/v1/products/categories/' . $category['term_id'] );
		$response        = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( Uuid::isValid( $data['uuid'] ), 'The UUID value is not valid.' );
	}

	/**
	 *
	 */
	public function test_product_category_includes() {
		$cat1        = ProductHelper::create_product_category( 'Music' );
		$cat2        = ProductHelper::create_product_category( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );
		$request->set_param( 'include', $cat1['term_id'] );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $cat1['term_id'], $data[0]['id'] );
	}

	/**
	 * NOTE: There is one category installed in the test setup.
	 */
	public function test_product_category_excludes() {
		$cat1        = ProductHelper::create_product_category( 'Music' );
		$cat2        = ProductHelper::create_product_category( 'Clothes' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );
		$request->set_param( 'exclude', $cat1['term_id'] );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 2, \count( $data ) );
		$ids = wp_list_pluck( $data, 'id' );

		$this->assertNotContains( $cat1['term_id'], $ids );
		$this->assertContains( $cat2['term_id'], $ids );
	}

	/**
	 *
	 */
	public function test_product_category_search_with_includes() {
		$cat1        = ProductHelper::create_product_category( 'Music1' );
		$cat2        = ProductHelper::create_product_category( 'Music2' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );
		$request->set_param( 'include', $cat1['term_id'] );
		$request->set_param( 'search', 'Music' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $cat1['term_id'], $data[0]['id'] );
	}

	/**
	 * NOTE: There is one category installed in the test setup.
	 */
	public function test_product_category_search_with_excludes() {
		$cat1        = ProductHelper::create_product_category( 'Music1' );
		$cat2        = ProductHelper::create_product_category( 'Music2' );
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );
		$request->set_param( 'exclude', $cat1['term_id'] );
		$request->set_param( 'search', 'Music' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 1, \count( $data ) );

		$this->assertEquals( $cat2['term_id'], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_unique_product_category_uuid() {
		$uuid = UUID::uuid4()->toString();
		$cat1 = ProductHelper::create_product_category( 'Music1' );
		add_term_meta( $cat1['term_id'], '_woocommerce_pos_uuid', $uuid );

		$cat2 = ProductHelper::create_product_category( 'Music2' );
		add_term_meta( $cat2['term_id'], '_woocommerce_pos_uuid', $uuid );
		$request   = $this->wp_rest_get_request( '/wcpos/v1/products/categories' );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );

		// pluck uuids
		$uuids = wp_list_pluck( $data, 'uuid' );

		$this->assertEquals( 3, \count( $uuids ) );
		$this->assertContains( $uuid, $uuids );
		$this->assertEquals( 3, \count( array_unique( $uuids ) ) );
	}
}
