<?php
/**
 * Test Logs Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Logs;

/**
 * Logs Controller test case.
 */
class Test_Logs_Controller extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Logs();
	}

	/**
	 * Test that the routes are registered.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/logs', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/logs/mark-read', $routes );
	}

	/**
	 * Test the namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );
		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test the rest_base property.
	 */
	public function test_rest_base_property(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );
		$this->assertEquals( 'logs', $rest_base );
	}

	/**
	 * Test GET logs requires auth.
	 */
	public function test_get_logs_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test POST mark-read requires auth.
	 */
	public function test_mark_read_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_post_request( '/wcpos/v1/logs/mark-read' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}
}
