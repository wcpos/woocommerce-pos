<?php
/**
 * Response telemetry (lab#571): body metrics only where the client parses
 * them; golden write-contract shapes untouched; contextual headers everywhere.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * @group sync
 */
class Test_Response_Telemetry extends WCPOS_REST_Unit_Test_Case {
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
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	public function test_orders_pull_carries_top_level_metrics_and_headers(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'orders/pull' );
		$request->set_param( 'limit', 1 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// The client's pull protocol parses response.metrics at the TOP level.
		$this->assertArrayHasKey( 'metrics', $data );
		$this->assertIsNumeric( $data['metrics']['duration_ms'] );
		$this->assertIsInt( $data['metrics']['memory_peak_bytes'] );

		$headers = $response->get_headers();
		$this->assertCount( 3, json_decode( $headers['X-Server-Load'], true ) );
		$this->assertStringStartsWith( 'wcpos;dur=', $headers['Server-Timing'] );
	}

	public function test_change_candidates_gain_memory_alongside_duration(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'changes/sequence-log' );
		$request->set_param( 'collection', 'orders' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$meta = $response->get_data()['meta'];
		$this->assertArrayHasKey( 'duration_ms', $meta );
		$this->assertArrayHasKey( 'memory_peak_bytes', $meta );
	}

	/**
	 * Empty pre-dispatch values must continue through normal route dispatch.
	 */
	public function test_empty_pre_dispatch_values_continue_to_route_callback(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( false, 0, '0', array() ) as $result ) {
			$pre_dispatch = static function () use ( $result ) {
				return $result;
			};
			add_filter( 'rest_pre_dispatch', $pre_dispatch, 10 );

			$response = $this->server->dispatch( $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'status' ) );

			remove_filter( 'rest_pre_dispatch', $pre_dispatch, 10 );
			$this->assertArrayHasKey( 'healthy', $response->get_data() );
			$this->assertArrayHasKey( 'X-Server-Load', $response->get_headers() );
		}
	}
}
