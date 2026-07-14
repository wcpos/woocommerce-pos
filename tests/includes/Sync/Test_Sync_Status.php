<?php
/**
 * Tests for the sync status REST API endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Activator;
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
		$this->drop_sync_tables();
		delete_option( Api::SCHEMA_OPTION );

		parent::setUp();
	}

	/**
	 * Remove the sync feature flag after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Post-rollback hygiene: setUp committed state BEFORE the transaction
		// started (flag on, tables dropped), so cleanup must run AFTER the
		// rollback or it gets undone. Restore tables for later classes.
		delete_option( Api::OPTION_ENABLED );
		delete_option( Api::SCHEMA_OPTION );
		( new Activator() )->install_sync_schema();
		delete_option( Api::SCHEMA_OPTION );
	}

	/**
	 * Drop every sync table.
	 */
	private function drop_sync_tables(): void {
		global $wpdb;

		foreach ( Health::required_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table names.
		}
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
	 * An installed store reports healthy with the latched schema version.
	 */
	public function test_sync_status_after_install_returns_healthy_status(): void {
		( new Activator() )->install_sync_schema();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/sync/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame(
			array(
				'healthy'        => true,
				'missing_tables' => array(),
				'schema_version' => Api::SCHEMA_VERSION,
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

		$this->assertStringContainsString( $wpdb->esc_like( $table ), $wpdb->last_query );
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
