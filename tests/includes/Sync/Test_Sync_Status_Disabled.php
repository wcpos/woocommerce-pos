<?php
/**
 * Tests for the disabled sync status REST API endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
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
		$paths = array(
			'/wcpos/v1/sync/status',
			'/wcpos/v1/sync/products',
			'/wcpos/v1/sync/changes/sequence-log',
			'/wcpos/v1/sync/digests',
			'/wcpos/v1/sync/integrity/scan',
			'/wcpos/v1/sync/variations',
			'/wcpos/v1/sync/resolve/barcode',
		);

		foreach ( $paths as $path ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $path ) );
			$this->assertEquals( 404, $response->get_status(), $path . ' was registered while sync was disabled.' );
		}
	}

	/**
	 * The disabled feature does not register any sync write observers.
	 */
	public function test_flag_disabled_registers_no_observation_hooks(): void {
		global $wp_filter;

		$observer_classes = array( Change_Log::class, Integrity_Digest::class, Sync_Index::class );
		foreach ( array( 'woocommerce_new_product', 'user_register', 'created_term', 'woocommerce_new_order' ) as $hook_name ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$is_sync_observer = is_array( $callback['function'] )
						&& is_object( $callback['function'][0] )
						&& in_array( get_class( $callback['function'][0] ), $observer_classes, true );
					$this->assertFalse( $is_sync_observer, $hook_name . ' contains a sync observer while disabled.' );
				}
			}
		}
	}

	/**
	 * An enabled sync API must not register write observers until the schema is
	 * both latched and healthy.
	 */
	public function test_enabled_sync_with_unlatched_schema_registers_no_observation_hooks(): void {
		global $wp_filter;

		update_option( Api::OPTION_ENABLED, true );
		delete_option( Api::SCHEMA_OPTION );

		$observer_classes = array( Change_Log::class, Integrity_Digest::class, Sync_Index::class );

		// The latch is the gate (latch-after-verify): flag on but schema never
		// verified means no observers. A table lost AFTER latching is fail-open
		// territory (covered by the digest fail-open test), not a hook gate.
		new Init();

		foreach ( array( 'woocommerce_new_product', 'user_register', 'created_term', 'woocommerce_new_order' ) as $hook_name ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$is_sync_observer = is_array( $callback['function'] )
						&& is_object( $callback['function'][0] )
						&& in_array( get_class( $callback['function'][0] ), $observer_classes, true );
					$this->assertFalse( $is_sync_observer, $hook_name . ' contains a sync observer before the schema is ready.' );
				}
			}
		}
	}
}
