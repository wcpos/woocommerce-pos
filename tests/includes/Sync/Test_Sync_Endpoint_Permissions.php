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
	 * Coupon post type inherited from an earlier test, when present.
	 *
	 * @var \WP_Post_Type|null
	 */
	private $coupon_post_type;

	/**
	 * Enable the sync routes before REST initialization and pin the disabled-
	 * coupons state that exposes WooCommerce's unregistered-post-type check.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		update_option( 'woocommerce_enable_coupons', 'no' );
		$this->coupon_post_type = get_post_type_object( 'shop_coupon' );
		if ( null !== $this->coupon_post_type ) {
			unregister_post_type( 'shop_coupon' );
		}
	}

	/**
	 * Restore inherited coupon registration and remove the feature flag.
	 */
	public function tearDown(): void {
		parent::tearDown();
		if ( null !== $this->coupon_post_type ) {
			register_post_type( 'shop_coupon', get_object_vars( $this->coupon_post_type ) );
		}
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Cashiers can use every client read endpoint.
	 */
	public function test_cashier_can_access_every_sync_read_endpoint(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$cashier    = get_user_by( 'id', $cashier_id );

		$this->assertSame( 'no', get_option( 'woocommerce_enable_coupons' ) );
		$this->assertFalse( post_type_exists( 'shop_coupon' ) );
		$this->assertTrue( $cashier->has_cap( 'access_woocommerce_pos' ) );
		$this->assertTrue( $cashier->has_cap( 'read_private_shop_coupons' ) );
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

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/coupons' ) );
		$this->assertSame( 403, $response->get_status(), 'The coupon permission relaxation must not bleed into wc/v3.' );
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
	 * Manage-only operators can use the documented out-of-band operations tier.
	 */
	public function test_manage_only_operator_can_access_sync_admin_operations(): void {
		$operator_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$operator    = get_user_by( 'id', $operator_id );
		$operator->add_cap( 'manage_woocommerce' );

		$this->assertTrue( $operator->has_cap( 'manage_woocommerce' ) );
		$this->assertFalse( $operator->has_cap( 'access_woocommerce_pos' ) );
		wp_set_current_user( $operator_id );

		foreach ( $this->admin_requests() as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), $request->get_route() );
		}

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) );
		$this->assertSame( 403, $response->get_status() );
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
			'/wcpos/v2/status',
			'/wcpos/v2/products',
			'/wcpos/v2/variations',
			'/wcpos/v2/customers',
			'/wcpos/v2/orders',
			'/wcpos/v2/orders/pull',
			'/wcpos/v2/coupons',
			'/wcpos/v2/taxes',
			'/wcpos/v2/products/categories',
			'/wcpos/v2/products/brands',
			'/wcpos/v2/products/tags',
			'/wcpos/v2/changes/sequence-log',
			'/wcpos/v2/changes/revision-hash',
			'/wcpos/v2/changes/range-checksum',
			'/wcpos/v2/changes/config-fingerprint',
			'/wcpos/v2/digests',
			'/wcpos/v2/integrity/scan',
			'/wcpos/v2/integrity/bucket',
			'/wcpos/v2/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			if ( '/wcpos/v2/variations' === $path || '/wcpos/v2/digests' === $path ) {
				$request->set_query_params( array( 'include' => '1' ) );
			} elseif ( '/wcpos/v2/resolve/barcode' === $path ) {
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
				'/wcpos/v2/uuid/backfill',
				'/wcpos/v2/orders/index/backfill',
				'/wcpos/v2/integrity/rebuild',
			) as $path
		) {
			$requests[] = $this->wp_rest_post_request( $path );
		}

		return $requests;
	}
}
