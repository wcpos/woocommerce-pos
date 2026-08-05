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
 * @covers \WCPOS\WooCommercePOS\API\V2\Status_Controller
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
	 * The enabled sync surface lives only at wcpos/v2.
	 */
	public function test_enabled_sync_status_uses_v2_without_legacy_sync_alias(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$current  = $this->server->dispatch( $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/status' ) );
		$legacy   = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/sync/status' ) );
		$old_data = $legacy->get_data();

		$this->assertSame( 200, $current->get_status() );
		$this->assertSame( 404, $legacy->get_status() );
		$this->assertSame( 'rest_no_route', $old_data['code'] );
	}

	/**
	 * Sync response telemetry is confined to v2 and server load is available on
	 * responses that do not opt into timing metrics.
	 */
	public function test_sync_status_has_server_load_without_changing_v1_responses(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$v2_response         = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) );
		$v1_response         = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/auth/test' ) );
		$v2_service_response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/auth/test' ) );
		$v2_headers          = $v2_response->get_headers();
		$v1_headers          = $v1_response->get_headers();
		$v2_service_headers = $v2_service_response->get_headers();
		$server_load        = json_decode( $v2_headers['X-Server-Load'], true );

		$this->assertSame( 200, $v2_response->get_status() );
		$this->assertIsArray( $server_load );
		$this->assertCount( 3, $server_load );
		$this->assertArrayNotHasKey( 'Server-Timing', $v2_headers );
		$this->assertArrayNotHasKey( 'meta', $v2_response->get_data() );

		$this->assertSame(
			array(
				'status'  => 'error',
				'message' => 'No authorization token detected',
			),
			$v1_response->get_data()
		);
		$this->assertArrayNotHasKey( 'X-Server-Load', $v1_headers );
		$this->assertArrayNotHasKey( 'Server-Timing', $v1_headers );
		$this->assertSame( $v1_response->get_data(), $v2_service_response->get_data() );
		$this->assertArrayNotHasKey( 'X-Server-Load', $v2_service_headers );
		$this->assertArrayNotHasKey( 'Server-Timing', $v2_service_headers );
	}

	/**
	 * Orders pull and every change-candidate response expose cheap body metrics
	 * and a Server-Timing mirror of the body duration.
	 */
	public function test_orders_pull_and_change_candidates_expose_response_metrics(): void {
		( new Activator() )->install_sync_schema();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$paths = array(
			// The client's pull protocol parses response.metrics at the TOP level.
			'/wcpos/v2/orders/pull'                    => array( 'metrics' ),
			'/wcpos/v2/changes/sequence-log'           => array( 'meta' ),
			'/wcpos/v2/changes/revision-hash'          => array( 'meta' ),
			'/wcpos/v2/changes/range-checksum'         => array( 'meta' ),
			'/wcpos/v2/changes/config-fingerprint'     => array( 'meta' ),
		);

		foreach ( $paths as $path => $metrics_path ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $path ) );
			$data     = $response->get_data();
			$headers  = $response->get_headers();
			$metrics  = $data;
			foreach ( $metrics_path as $key ) {
				$this->assertArrayHasKey( $key, $metrics, $path );
				$metrics = $metrics[ $key ];
			}

			$this->assertSame( 200, $response->get_status(), $path );
			$this->assertIsFloat( $metrics['duration_ms'], $path );
			$this->assertGreaterThanOrEqual( 0, $metrics['duration_ms'], $path );
			$this->assertIsInt( $metrics['memory_peak_bytes'], $path );
			$this->assertGreaterThan( 0, $metrics['memory_peak_bytes'], $path );
			$this->assertSame( 'wcpos;dur=' . $metrics['duration_ms'], $headers['Server-Timing'], $path );
			$this->assertCount( 3, json_decode( $headers['X-Server-Load'], true ), $path );
		}
	}

	/**
	 * Authorized POS users can inspect the missing sync tables.
	 */
	public function test_sync_status_with_flag_enabled_and_cashier_returns_unhealthy_status(): void {
		global $wpdb;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$request  = $this->wp_rest_get_request( '/wcpos/v2/status' );
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
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$request  = $this->wp_rest_get_request( '/wcpos/v2/status' );
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
		foreach ( array( '/wcpos/v2/orders', '/wcpos/v2/orders/pull' ) as $path ) {
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
	 * Users without POS access cannot inspect sync status.
	 */
	public function test_sync_status_without_access_woocommerce_pos_is_not_authorized(): void {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v2/status' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertNotEquals( 200, $response->get_status() );
		$this->assertCount( 3, json_decode( $response->get_headers()['X-Server-Load'], true ) );
	}

	/**
	 * Every sync read endpoint refuses to serve while the store is unhealthy.
	 */
	public function test_sync_read_endpoints_with_missing_tables_return_503(): void {
		$paths = array(
			'/wcpos/v2/products',
			'/wcpos/v2/products/categories',
			'/wcpos/v2/products/brands',
			'/wcpos/v2/products/tags',
			'/wcpos/v2/orders',
			'/wcpos/v2/orders/pull',
			'/wcpos/v2/customers',
			'/wcpos/v2/coupons',
			'/wcpos/v2/taxes',
			'/wcpos/v2/changes/sequence-log',
			'/wcpos/v2/changes/revision-hash',
			'/wcpos/v2/changes/range-checksum',
			'/wcpos/v2/changes/config-fingerprint',
			'/wcpos/v2/digests',
			'/wcpos/v2/integrity/scan',
			'/wcpos/v2/integrity/bucket',
			'/wcpos/v2/variations',
			'/wcpos/v2/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			if ( '/wcpos/v2/digests' === $path || '/wcpos/v2/variations' === $path ) {
				$request->set_query_params( array( 'include' => '1' ) );
			}
			if ( '/wcpos/v2/resolve/barcode' === $path ) {
				$request->set_query_params( array( 'code' => 'missing' ) );
			}
			$response = $this->server->dispatch( $request );
			$this->assertEquals( 503, $response->get_status(), $path . ' did not apply the sync health gate. Body: ' . wp_json_encode( $response->get_data() ) );
			$this->assertEquals( 'wcpos_sync_unavailable', $response->get_data()['code'] );
			$this->assertCount( 3, json_decode( $response->get_headers()['X-Server-Load'], true ), $path );
		}
	}

	/**
	 * Every supported push collection applies the shared install-health gate.
	 */
	public function test_sync_push_endpoints_with_missing_tables_return_503(): void {
		foreach ( array( 'products', 'orders', 'customers', 'categories', 'brands', 'variations', 'coupons' ) as $collection ) {
			$path     = '/wcpos/v2/push/' . $collection;
			$request  = $this->wp_rest_post_request( $path );
			$response = $this->server->dispatch( $request );

			$this->assertEquals( 503, $response->get_status(), $path . ' did not apply the sync health gate.' );
			$this->assertEquals( 'wcpos_sync_unavailable', $response->get_data()['code'] );
			// Error bodies keep their golden shapes — contextual header only.
			$this->assertArrayNotHasKey( 'meta', $response->get_data(), $path );
			$this->assertCount( 3, json_decode( $response->get_headers()['X-Server-Load'], true ), $path );
			$this->assertArrayNotHasKey( 'Server-Timing', $response->get_headers(), $path );
		}
	}

	/**
	 * Operations endpoints cannot run against or cure an unhealthy sync store.
	 */
	public function test_sync_ops_endpoints_with_missing_tables_return_503(): void {
		foreach ( array( '/wcpos/v2/uuid/backfill', '/wcpos/v2/orders/index/backfill', '/wcpos/v2/integrity/rebuild' ) as $path ) {
			$response = $this->server->dispatch( $this->wp_rest_post_request( $path ) );

			$this->assertSame( 503, $response->get_status(), $path );
			$this->assertSame( 'wcpos_sync_unavailable', $response->get_data()['code'], $path );
			$this->assertCount( 3, json_decode( $response->get_headers()['X-Server-Load'], true ), $path );
		}
	}

	/**
	 * Capability checks run before the health gate on every read endpoint.
	 */
	public function test_sync_read_endpoints_without_access_woocommerce_pos_are_not_authorized(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$paths = array(
			'/wcpos/v2/products',
			'/wcpos/v2/orders',
			'/wcpos/v2/orders/pull',
			'/wcpos/v2/changes/sequence-log',
			'/wcpos/v2/digests',
			'/wcpos/v2/integrity/scan',
			'/wcpos/v2/variations',
			'/wcpos/v2/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$request = $this->wp_rest_get_request( $path );
			$request->set_param( 'include', '1' );
			$request->set_param( 'code', 'missing' );
			$response = $this->server->dispatch( $request );
			$this->assertContains( $response->get_status(), array( 401, 403 ), $path . ' bypassed the capability gate.' );
			$this->assertNotEquals( 503, $response->get_status(), $path . ' exposed health before authorization.' );
			$this->assertCount( 3, json_decode( $response->get_headers()['X-Server-Load'], true ), $path );
		}
	}

	/**
	 * Capability checks run before health disclosure on every push collection.
	 */
	public function test_sync_push_endpoints_without_access_woocommerce_pos_are_not_authorized(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		foreach ( array( 'products', 'orders', 'customers', 'categories', 'brands', 'variations', 'coupons' ) as $collection ) {
			$path     = '/wcpos/v2/push/' . $collection;
			$response = $this->server->dispatch( $this->wp_rest_post_request( $path ) );

			$this->assertContains( $response->get_status(), array( 401, 403 ), $path . ' bypassed the capability gate.' );
			$this->assertNotEquals( 503, $response->get_status(), $path . ' exposed health before authorization.' );
		}
	}
}
