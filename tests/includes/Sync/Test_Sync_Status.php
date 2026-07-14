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
	 * The orders read lanes (increment 2c) are registered and health-gated: with
	 * no tables installed they 503 rather than 404, proving both the flag
	 * registration and the shared install-health gate apply.
	 */
	public function test_sync_orders_lanes_are_registered_and_health_gated(): void {
		foreach ( array( '/wcpos/v1/sync/orders', '/wcpos/v1/sync/orders/pull' ) as $path ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $path ) );

			$this->assertEquals( 503, $response->get_status(), $path . ' was not registered or not health-gated.' );
			$this->assertEquals( 'wcpos_sync_unavailable', $response->get_data()['code'] );
		}
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

	/**
	 * Every sync read endpoint refuses to serve while the store is unhealthy.
	 */
	public function test_sync_read_endpoints_with_missing_tables_return_503(): void {
		$paths = array(
			'/wcpos/v1/sync/products',
			'/wcpos/v1/sync/orders',
			'/wcpos/v1/sync/orders/pull',
			'/wcpos/v1/sync/changes/sequence-log',
			'/wcpos/v1/sync/changes/revision-hash',
			'/wcpos/v1/sync/changes/range-checksum',
			'/wcpos/v1/sync/changes/config-fingerprint',
			'/wcpos/v1/sync/digests',
			'/wcpos/v1/sync/integrity/scan',
			'/wcpos/v1/sync/integrity/bucket',
			'/wcpos/v1/sync/variations',
			'/wcpos/v1/sync/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			if ( '/wcpos/v1/sync/digests' === $path || '/wcpos/v1/sync/variations' === $path ) {
				$request->set_query_params( array( 'include' => '1' ) );
			}
			if ( '/wcpos/v1/sync/resolve/barcode' === $path ) {
				$request->set_query_params( array( 'code' => 'missing' ) );
			}
			$response = $this->server->dispatch( $request );
			$this->assertEquals( 503, $response->get_status(), $path . ' did not apply the sync health gate. Body: ' . wp_json_encode( $response->get_data() ) );
			$this->assertEquals( 'wcpos_sync_unavailable', $response->get_data()['code'] );
		}
	}

	/**
	 * Capability checks run before the health gate on every read endpoint.
	 */
	public function test_sync_read_endpoints_without_manage_woocommerce_are_not_authorized(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$paths = array(
			'/wcpos/v1/sync/products',
			'/wcpos/v1/sync/orders',
			'/wcpos/v1/sync/orders/pull',
			'/wcpos/v1/sync/changes/sequence-log',
			'/wcpos/v1/sync/digests',
			'/wcpos/v1/sync/integrity/scan',
			'/wcpos/v1/sync/variations',
			'/wcpos/v1/sync/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			$request->set_param( 'include', '1' );
			$request->set_param( 'code', 'missing' );
			$response = $this->server->dispatch( $request );
			$this->assertContains( $response->get_status(), array( 401, 403 ), $path . ' bypassed the capability gate.' );
			$this->assertNotEquals( 503, $response->get_status(), $path . ' exposed health before authorization.' );
		}
	}
}
