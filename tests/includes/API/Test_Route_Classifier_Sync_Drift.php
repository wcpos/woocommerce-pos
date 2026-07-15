<?php
/**
 * Drift guard for the sync admin operation classification.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Sync\Api as Sync_Api;

/**
 * Sync admin operation classification drift guard.
 */
class Test_Route_Classifier_Sync_Drift extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable sync before REST initialization.
	 */
	public function setUp(): void {
		update_option( Sync_Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the sync feature option.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Sync_Api::OPTION_ENABLED );
	}

	/**
	 * Every classified admin operation is registered by a sync controller.
	 */
	public function test_sync_admin_op_paths_match_registered_routes(): void {
		$routes = rest_get_server()->get_routes( Sync_Api::ROUTE_NAMESPACE );

		foreach ( Sync_Api::ADMIN_OP_PATHS as $path ) {
			$route = '/' . Sync_Api::ROUTE_NAMESPACE . '/' . Sync_Api::ROUTE_PREFIX . $path;
			$this->assertArrayHasKey( $route, $routes, $route );
		}
	}
}
