<?php
/**
 * Tests for the woocommerce_pos_rest_api_v2_controllers filter.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Extension-style v2 settings replacement adding a route — the Pro pattern
 * (Pro replaces the v1 'settings' entry and adds a license route; its v2
 * twin must be carried through the v2 controllers filter).
 */
class V2_Filtered_Settings_Test_Double extends \WCPOS\WooCommercePOS\API\V2\Settings {
	/**
	 * Register the parent routes plus an extension route.
	 */
	public function register_routes(): void {
		parent::register_routes();
		register_rest_route(
			$this->namespace,
			'/settings/license/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( array( 'licensed' => true ), 200 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}

/**
 * The v2 controllers map is filterable: replacements register their routes
 * under wcpos/v2.
 */
class Test_V2_Controllers_Filter extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Replace the v2 settings entry before REST registration.
	 */
	public function setUp(): void {
		add_filter( 'woocommerce_pos_rest_api_v2_controllers', array( $this, 'replace_v2_settings' ) );
		parent::setUp();
	}

	/**
	 * Remove the filter.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_rest_api_v2_controllers', array( $this, 'replace_v2_settings' ) );
		parent::tearDown();
	}

	/**
	 * Swap the settings entry for the extension double.
	 *
	 * @param array $controllers V2 controller class names.
	 *
	 * @return array
	 */
	public function replace_v2_settings( array $controllers ): array {
		$controllers['settings'] = V2_Filtered_Settings_Test_Double::class;

		return $controllers;
	}

	/**
	 * A filtered v2 controller's extension route answers under wcpos/v2.
	 */
	public function test_filtered_v2_controller_routes_register_under_v2(): void {
		$routes = $this->server->get_routes( 'wcpos/v2' );
		$this->assertArrayHasKey( '/wcpos/v2/settings/license/status', $routes );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/settings/license/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['licensed'] );

		// The v1 surface is untouched by the v2 filter.
		$this->assertArrayNotHasKey( '/wcpos/v1/settings/license/status', $this->server->get_routes( 'wcpos/v1' ) );
	}
}
