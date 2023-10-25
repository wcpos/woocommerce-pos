<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Product_Variations_Controller;
use WP_REST_Request;
use WP_User;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Variations_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Product_Variations_Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	
	public function setup(): void {
		parent::setUp();

		$this->endpoint = new Product_Variations_Controller();
		$this->user     = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		new Api();
		wp_set_current_user( $this->user );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function get_wp_rest_request( $method = 'GET', $path = '/wcpos/v1/products' ) {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( $method );
		$request->set_route( $path );

		return $request;
	}

	public function test_namespace_property(): void {
		$reflection         = new ReflectionClass($this->endpoint);
		$namespace_property = $reflection->getProperty('namespace');
		$namespace_property->setAccessible(true);
		
		$this->assertEquals('wcpos/v1', $namespace_property->getValue($this->endpoint));
	}

	public function test_rest_base(): void {
		$reflection         = new ReflectionClass($this->endpoint);
		$rest_base_property = $reflection->getProperty('rest_base');
		$rest_base_property->setAccessible(true);
		
		$this->assertEquals('products/(?P<product_id>[\d]+)/variations', $rest_base_property->getValue($this->endpoint));
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/batch', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/generate', $routes );
	}
}
