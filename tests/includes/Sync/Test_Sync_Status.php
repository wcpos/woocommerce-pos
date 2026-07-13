<?php
/**
 * Tests for the sync status REST API endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Sync status tests with the feature flag enabled.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 * @covers \WCPOS\WooCommercePOS\Sync\Health
 * @covers \WCPOS\WooCommercePOS\Sync\Status_Controller
 */
class Test_Sync_Status extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable the sync feature flag before routes are registered.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );

		parent::setUp();
	}

	/**
	 * Remove the sync feature flag after each test.
	 */
	public function tearDown(): void {
		delete_option( Api::OPTION_ENABLED );

		parent::tearDown();
	}

	/**
	 * Administrators can inspect the missing sync tables.
	 */
	public function test_sync_status_with_flag_enabled_and_admin_returns_unhealthy_status(): void {
		global $wpdb;

		$request  = $this->wp_rest_get_request( '/wcpos/v1/sync/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame(
			array(
				'healthy'        => false,
				'missing_tables' => array(
					$wpdb->prefix . 'wcpos_sync_order_index',
					$wpdb->prefix . 'wcpos_sync_change_log',
					$wpdb->prefix . 'wcpos_sync_stored_digest',
					$wpdb->prefix . 'wcpos_sync_mutations',
				),
				'schema_version' => null,
			),
			$response->get_data()
		);
	}

	/**
	 * Table existence checks escape underscores in the LIKE pattern.
	 */
	public function test_table_exists_with_underscores_escapes_like_pattern(): void {
		global $wpdb;

		$table = $wpdb->prefix . Health::ORDER_INDEX_TABLE;

		Health::table_exists( $table );

		$this->assertSame( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ), $wpdb->last_query );
	}

	/**
	 * Users without WooCommerce management access cannot inspect sync status.
	 */
	public function test_sync_status_without_manage_woocommerce_is_not_authorized(): void {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		$user = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/sync/status' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertNotEquals( 200, $response->get_status() );
	}
}
