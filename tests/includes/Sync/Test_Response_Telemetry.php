<?php
/**
 * Response telemetry: body metrics only where the client parses them;
 * deterministic change bodies and golden write-contract shapes stay untouched.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Response_Telemetry;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Response;

/**
 * Response telemetry behavior tests.
 *
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

	/**
	 * Orders pull retains the body metrics parsed by the client.
	 */
	public function test_orders_pull_carries_top_level_metrics_and_headers(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/orders/pull' );
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

	/**
	 * Changes receive telemetry headers without response body mutation.
	 */
	public function test_changes_route_adds_telemetry_headers_without_modifying_body(): void {
		$request = $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/changes/sequence-log' );
		$body    = array(
			'checkpoint' => array(
				'since' => 0,
				'head'  => 0,
			),
			'changes'    => array(),
			'complete'   => true,
			'meta'       => array( 'supported' => true ),
		);
		Response_Telemetry::start_request( null, null, $request );

		$response = Response_Telemetry::decorate_callback_response( new WP_REST_Response( $body ), null, $request );

		$this->assertSame( $body, $response->get_data() );
		$this->assertStringStartsWith( 'wcpos;dur=', $response->get_headers()['Server-Timing'] );
		$this->assertGreaterThan( 0, (int) $response->get_headers()['X-WCPOS-Memory-Peak'] );
	}

	/**
	 * Repeated reads without writes keep strong validators honest.
	 */
	public function test_sequence_log_without_store_writes_returns_identical_etag_and_body(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$first  = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' ) );
		$second = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' ) );

		$this->assertSame( $first->get_headers()['ETag'], $second->get_headers()['ETag'] );
		$this->assertSame( wp_json_encode( $first->get_data() ), wp_json_encode( $second->get_data() ) );
		// Byte-equality alone cannot catch a reintroduced memory field (2MB
		// granularity makes back-to-back reads identical); pin meta exactly.
		$this->assertSame( array( 'supported' => true ), $first->get_data()['meta'] );
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

			$response = $this->server->dispatch( $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/status' ) );

			remove_filter( 'rest_pre_dispatch', $pre_dispatch, 10 );
			$this->assertArrayHasKey( 'healthy', $response->get_data() );
			$this->assertArrayHasKey( 'X-Server-Load', $response->get_headers() );
		}
	}
}
