<?php
/**
 * Test Extensions Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Extensions;

/**
 * Extensions Controller test case.
 */
class Test_Extensions_Controller extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Extensions();
		delete_transient( 'wcpos_extensions_catalog' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_transient( 'wcpos_extensions_catalog' );
		parent::tearDown();
	}

	/**
	 * Test that the routes are registered.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/extensions', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/extensions/seen', $routes );
	}

	/**
	 * Test the namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );
		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test GET extensions returns 200 with catalog data.
	 */
	public function test_get_extensions_returns_200(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/extensions' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
		$this->assertEquals( 'wcpos-stripe-terminal', $data[0]['slug'] );
		$this->assertArrayHasKey( 'status', $data[0] );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test GET extensions returns total headers.
	 */
	public function test_get_extensions_returns_total_header(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/extensions' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( '2', $response->get_headers()['X-WP-Total'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test GET extensions returns empty array on fetch failure.
	 */
	public function test_get_extensions_returns_empty_on_failure(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/extensions' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertEmpty( $data );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test unauthorized access is denied.
	 */
	public function test_get_extensions_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/extensions' );
		$response = $this->server->dispatch( $request );

		// Baseline gate returns 403 for unauthenticated users (no access_woocommerce_pos).
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test POST mark_seen stores catalog slugs in user meta.
	 */
	public function test_mark_seen_stores_slugs_in_user_meta(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$request  = $this->wp_rest_post_request( '/wcpos/v1/extensions/seen' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$seen = get_user_meta( get_current_user_id(), '_wcpos_seen_extension_slugs', true );
		$this->assertIsArray( $seen );
		$this->assertContains( 'wcpos-stripe-terminal', $seen );
		$this->assertContains( 'wcpos-bookings', $seen );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test POST mark_seen requires auth.
	 */
	public function test_mark_seen_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_post_request( '/wcpos/v1/extensions/seen' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Mock a successful catalog HTTP response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return array|false
	 */
	public function mock_catalog_response( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					array(
						'slug'           => 'wcpos-stripe-terminal',
						'name'           => 'Stripe Terminal',
						'description'    => 'Accept in-person card payments.',
						'version'        => '1.2.0',
						'author'         => 'wcpos',
						'category'       => 'payments',
						'tags'           => array( 'stripe', 'terminal' ),
						'requires_wp'    => '6.0',
						'requires_wc'    => '8.0',
						'requires_wcpos' => '1.7',
						'requires_pro'   => true,
						'icon'           => '',
						'homepage'       => '',
						'download_url'   => 'https://example.com/stripe-terminal.zip',
						'latest_version' => '1.2.0',
						'released_at'    => '2026-01-15T10:00:00Z',
					),
					array(
						'slug'           => 'wcpos-bookings',
						'name'           => 'Bookings',
						'description'    => 'WooCommerce Bookings integration.',
						'version'        => '1.0.0',
						'author'         => 'wcpos',
						'category'       => 'integrations',
						'tags'           => array( 'bookings' ),
						'requires_wp'    => '6.0',
						'requires_wc'    => '8.0',
						'requires_wcpos' => '1.7',
						'requires_pro'   => true,
						'icon'           => '',
						'homepage'       => '',
						'download_url'   => 'https://example.com/bookings.zip',
						'latest_version' => '1.0.0',
						'released_at'    => '2026-01-10T10:00:00Z',
					),
				)
			),
		);
	}

	/**
	 * Mock a failed catalog HTTP response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return \WP_Error|false
	 */
	public function mock_catalog_failure( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return new \WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}
