<?php
/**
 * Tests for unconditional sync REST API registration.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Sync API registration tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 */
class Test_Sync_Api_Always_Registered extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Sync routes exist without an enabling option.
	 */
	public function test_sync_routes_are_registered_by_default(): void {
		wp_set_current_user( 0 );

		$paths = array(
			'/wcpos/v2/changes/config-fingerprint',
			'/wcpos/v2/status',
		);

		foreach ( $paths as $path ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $path ) );
			$data     = $response->get_data();

			$this->assertNotSame( 404, $response->get_status(), $path );
			$this->assertNotSame( 'rest_no_route', $data['code'] ?? null, $path );
		}
	}
}
