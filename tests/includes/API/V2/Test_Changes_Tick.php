<?php
/**
 * Tests for the combined change-signal tick endpoint (#1405).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Request;

/**
 * GET /wcpos/v2/changes/tick through real v2 REST dispatch: payload parity
 * with the two source endpoints, conditional-request (ETag/304) semantics,
 * and permission pins.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 */
class Test_Changes_Tick extends Sync_REST_Store_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/**
	 * Remove options written outside the per-test transaction.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Build a tick request with query parameters.
	 *
	 * @param array $params Query parameters.
	 */
	private function tick_request( array $params = array() ): WP_REST_Request {
		$request = $this->wp_rest_get_request( '/wcpos/v2/changes/tick' );
		$request->set_query_params( $params );

		return $request;
	}

	/**
	 * The tick's fingerprint member is the standalone endpoint's response data.
	 */
	public function test_tick_config_fingerprint_matches_standalone_endpoint(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );

		$tick       = $this->server->dispatch( $this->tick_request() )->get_data();
		$standalone = $this->server->dispatch(
			$this->wp_rest_get_request( '/wcpos/v2/changes/config-fingerprint' )
		)->get_data();

		// Per-request telemetry (timing, memory) is intentionally volatile and is
		// stamped onto the tick's own top-level meta instead; the remaining
		// response data must match.
		$this->assertArrayHasKey( 'memory_peak_bytes', $tick['meta'] );
		unset(
			$tick['config_fingerprint']['meta']['duration_ms'],
			$standalone['meta']['duration_ms'],
			$standalone['meta']['memory_peak_bytes']
		);
		$this->assertSame( $standalone, $tick['config_fingerprint'] );
	}

	/**
	 * The tick's sequence-log member is the standalone endpoint's response data.
	 */
	public function test_tick_sequence_log_matches_standalone_endpoint(): void {
		( new Change_Log() )->record( 'product', 123, 'update', 'test', false );
		$params = array(
			'since' => 0,
			'limit' => 10,
		);

		$tick     = $this->server->dispatch( $this->tick_request( $params ) )->get_data();
		$request  = $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' );
		$request->set_query_params( $params );
		$sequence = $this->server->dispatch( $request )->get_data();

		unset(
			$tick['sequence_log']['meta']['duration_ms'],
			$tick['sequence_log']['config_fingerprint']['meta']['duration_ms'],
			$sequence['meta']['duration_ms'],
			$sequence['meta']['memory_peak_bytes'],
			$sequence['config_fingerprint']['meta']['duration_ms']
		);
		$this->assertSame( 123, $tick['sequence_log']['changes'][0]['id'] );
		$this->assertSame( $sequence, $tick['sequence_log'] );
	}

	/**
	 * A matching ETag at the current head returns an empty 304.
	 */
	public function test_tick_matching_etag_at_head_returns_not_modified(): void {
		$first = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		$etag  = $first->get_headers()['ETag'];
		$head  = $first->get_data()['sequence_log']['checkpoint']['head'];

		$request = $this->tick_request( array( 'since' => $head ) );
		$request->set_header( 'If-None-Match', $etag );
		$not_modified = $this->server->dispatch( $request );

		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );
		$this->assertSame( $etag, $not_modified->get_headers()['ETag'] );
		$this->assertSame( 'no-store', $not_modified->get_headers()['Cache-Control'] );
	}

	/**
	 * A new change-log row invalidates a tick ETag.
	 */
	public function test_tick_etag_changes_when_sequence_head_moves(): void {
		$before = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		( new Change_Log() )->record( 'product', 123, 'update', 'test', false );

		$request = $this->tick_request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $before->get_headers()['ETag'] );
		$after = $this->server->dispatch( $request );

		$this->assertSame( 200, $after->get_status() );
		$this->assertNotSame( $before->get_headers()['ETag'], $after->get_headers()['ETag'] );
	}

	/**
	 * A representation config change invalidates a tick ETag.
	 */
	public function test_tick_etag_changes_when_config_changes(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$before = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );

		$request = $this->tick_request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $before->get_headers()['ETag'] );
		$after = $this->server->dispatch( $request );

		$this->assertSame( 200, $after->get_status() );
		$this->assertNotSame( $before->get_headers()['ETag'], $after->get_headers()['ETag'] );
	}

	/**
	 * A matching ETag cannot hide rows behind a lagging cursor.
	 */
	public function test_tick_matching_etag_with_since_behind_head_returns_page(): void {
		( new Change_Log() )->record( 'product', 123, 'update', 'test', false );
		$latest  = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		$head    = $latest->get_data()['sequence_log']['checkpoint']['head'];
		$current = $this->server->dispatch( $this->tick_request( array( 'since' => $head ) ) );

		$request = $this->tick_request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $current->get_headers()['ETag'] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123, $response->get_data()['sequence_log']['changes'][0]['id'] );
	}

	/**
	 * The fingerprint member always reports every collection, even when the
	 * stream itself is narrowed (products serves variation rows too).
	 */
	public function test_tick_fingerprint_ignores_collection_narrowing(): void {
		$tick = $this->server->dispatch( $this->tick_request( array( 'collection' => 'products' ) ) )->get_data();

		$this->assertSame(
			array_values( Config_Fingerprint::collections() ),
			array_keys( $tick['config_fingerprint']['fingerprints'] )
		);
	}

	/**
	 * Logged-out callers get the namespace gate's 401.
	 */
	public function test_tick_unauthenticated_request_returns_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->tick_request() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}

	/**
	 * Logged-in users without the POS capability are forbidden.
	 */
	public function test_tick_user_without_pos_capability_returns_403(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( $this->tick_request() );

		$this->assertSame( 403, $response->get_status() );
	}
}
