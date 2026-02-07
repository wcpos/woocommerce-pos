<?php
/**
 * Test Extensions Service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Extensions;
use WP_UnitTestCase;

/**
 * Extensions Service test case.
 */
class Test_Extensions_Service extends WP_UnitTestCase {

	/**
	 * Extensions service instance.
	 *
	 * @var Extensions
	 */
	private $service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = Extensions::instance();
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
	 * Test that the service returns an array.
	 */
	public function test_get_catalog_returns_array(): void {
		// Mock the remote fetch to return known data.
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$catalog = $this->service->get_catalog();

		$this->assertIsArray( $catalog );
		$this->assertCount( 2, $catalog );
		$this->assertEquals( 'wcpos-stripe-terminal', $catalog[0]['slug'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test that the catalog is cached in a transient.
	 */
	public function test_get_catalog_uses_transient_cache(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		// First call — fetches and caches.
		$this->service->get_catalog();

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );

		// Second call — should use cache, not HTTP.
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$catalog = $this->service->get_catalog();
		$this->assertIsArray( $catalog );
		$this->assertCount( 2, $catalog );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test that HTTP failure returns empty array.
	 */
	public function test_get_catalog_returns_empty_on_failure(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$catalog = $this->service->get_catalog();
		$this->assertIsArray( $catalog );
		$this->assertEmpty( $catalog );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test get_extensions enriches catalog with local status.
	 */
	public function test_get_extensions_includes_status(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertIsArray( $extensions );
		foreach ( $extensions as $ext ) {
			$this->assertArrayHasKey( 'status', $ext );
			$this->assertContains( $ext['status'], array( 'not_installed', 'inactive', 'active', 'update_available' ) );
		}

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Mock a successful catalog HTTP response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return array
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
						'icon'           => 'https://raw.githubusercontent.com/wcpos/wcpos-stripe-terminal/main/assets/icon-128x128.png',
						'homepage'       => 'https://wcpos.com/extensions/stripe-terminal',
						'download_url'   => 'https://github.com/wcpos/wcpos-stripe-terminal/releases/download/v1.2.0/wcpos-stripe-terminal.zip',
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
						'download_url'   => 'https://github.com/wcpos/wcpos-bookings/releases/download/v1.0.0/wcpos-bookings.zip',
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
	 * @return \WP_Error
	 */
	public function mock_catalog_failure( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return new \WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}
