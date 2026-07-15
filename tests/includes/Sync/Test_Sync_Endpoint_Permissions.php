<?php
/**
 * Tests for the sync endpoint permission tiers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WP_REST_Request;

/**
 * Sync endpoint permission matrix on a healthy sync store.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Endpoint_Permissions
 */
class Test_Sync_Endpoint_Permissions extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the sync routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the non-transactional feature flag.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Cashiers can use every client read endpoint.
	 */
	public function test_cashier_can_access_every_sync_read_endpoint(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$cashier    = get_user_by( 'id', $cashier_id );

		$this->assertTrue( $cashier->has_cap( 'access_woocommerce_pos' ) );
		$this->assertFalse( $cashier->has_cap( 'manage_woocommerce' ) );
		wp_set_current_user( $cashier_id );

		foreach ( $this->read_requests() as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame(
				200,
				$response->get_status(),
				$request->get_route() . ': ' . wp_json_encode( $response->get_data() )
			);
		}
	}

	/**
	 * Cashiers cannot run out-of-band admin operations.
	 */
	public function test_cashier_cannot_access_sync_admin_operations(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_id );

		foreach ( $this->admin_requests() as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame( 403, $response->get_status(), $request->get_route() );
		}
	}

	/**
	 * Administrators can use both the client and admin tiers.
	 */
	public function test_administrator_can_access_both_sync_permission_tiers(): void {
		foreach ( array_merge( $this->read_requests(), $this->admin_requests() ) as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame(
				200,
				$response->get_status(),
				$request->get_route() . ': ' . wp_json_encode( $response->get_data() )
			);
		}
	}

	/**
	 * The namespace dispatch gate preserves 401 responses for logged-out callers.
	 */
	public function test_unauthenticated_sync_requests_return_401(): void {
		wp_set_current_user( 0 );

		foreach ( array_merge( $this->read_requests(), $this->admin_requests() ) as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame( 401, $response->get_status(), $request->get_route() );
			$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'], $request->get_route() );
		}
	}

	/**
	 * Build requests for every client read route.
	 *
	 * @return WP_REST_Request[]
	 */
	private function read_requests(): array {
		$requests = array();
		$paths    = array(
			'/wcpos/v1/sync/status',
			'/wcpos/v1/sync/products',
			'/wcpos/v1/sync/variations',
			'/wcpos/v1/sync/customers',
			'/wcpos/v1/sync/orders',
			'/wcpos/v1/sync/orders/pull',
			'/wcpos/v1/sync/coupons',
			'/wcpos/v1/sync/taxes',
			'/wcpos/v1/sync/products/categories',
			'/wcpos/v1/sync/products/brands',
			'/wcpos/v1/sync/products/tags',
			'/wcpos/v1/sync/changes/sequence-log',
			'/wcpos/v1/sync/changes/revision-hash',
			'/wcpos/v1/sync/changes/range-checksum',
			'/wcpos/v1/sync/changes/config-fingerprint',
			'/wcpos/v1/sync/digests',
			'/wcpos/v1/sync/integrity/scan',
			'/wcpos/v1/sync/integrity/bucket',
			'/wcpos/v1/sync/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			if ( '/wcpos/v1/sync/variations' === $path || '/wcpos/v1/sync/digests' === $path ) {
				$request->set_query_params( array( 'include' => '1' ) );
			} elseif ( '/wcpos/v1/sync/resolve/barcode' === $path ) {
				$request->set_query_params( array( 'code' => 'missing' ) );
			}
			$requests[] = $request;
		}

		return $requests;
	}

	/**
	 * Build requests for every out-of-band admin operation.
	 *
	 * @return WP_REST_Request[]
	 */
	private function admin_requests(): array {
		$requests = array();
		foreach (
			array(
				'/wcpos/v1/sync/uuid/backfill',
				'/wcpos/v1/sync/orders/index/backfill',
				'/wcpos/v1/sync/integrity/rebuild',
			) as $path
		) {
			$requests[] = $this->wp_rest_post_request( $path );
		}

		return $requests;
	}
}
