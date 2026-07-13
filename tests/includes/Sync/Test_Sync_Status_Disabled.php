<?php
/**
 * Tests for the disabled sync status REST API endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Sync status tests with the feature flag disabled.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 */
class Test_Sync_Status_Disabled extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Ensure the sync feature flag is disabled before routes are registered.
	 */
	public function setUp(): void {
		delete_option( Api::OPTION_ENABLED );

		parent::setUp();
	}

	/**
	 * The sync status route is unavailable by default.
	 */
	public function test_sync_status_with_flag_disabled_returns_404(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/sync/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}
}
