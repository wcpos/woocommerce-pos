<?php
/**
 * Tests for sync route classification while the sync feature is disabled.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Sync\Api as Sync_Api;

/**
 * Sync classification behavior while the feature is disabled.
 *
 * @covers \WCPOS\WooCommercePOS\API::rest_pre_dispatch
 */
class Test_Route_Classifier_Sync_Disabled extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Disable sync before REST initialization.
	 */
	public function setUp(): void {
		update_option( Sync_Api::OPTION_ENABLED, false );
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
	 * Sync admin-op exemptions remain classified independently of registration.
	 */
	public function test_manage_operator_reaches_dispatch_404_for_unregistered_sync_admin_op(): void {
		$operator_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$operator    = get_user_by( 'id', $operator_id );
		$operator->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $operator_id );

		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v1/sync/uuid/backfill' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
	}
}
