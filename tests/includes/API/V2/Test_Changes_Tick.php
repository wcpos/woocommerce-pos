<?php
/**
 * Tests for the combined change-signal tick endpoint (#1405).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Request;

/**
 * GET /wcpos/v2/changes/tick through real v2 REST dispatch: slim payload,
 * validator parity with sequence-log, conditional-request (ETag/304)
 * semantics, and permission pins.
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
	 * Tick exposes the graduated flattened response shape.
	 */
	public function test_tick_config_fingerprint_matches_standalone_endpoint(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );

		$tick       = $this->server->dispatch( $this->tick_request() )->get_data();
		$standalone = $this->server->dispatch(
			$this->wp_rest_get_request( '/wcpos/v2/changes/config-fingerprint' )
		)->get_data();

		$this->assertSame(
			array( 'checkpoint', 'changes', 'complete', 'config_fingerprint', 'meta' ),
			array_keys( $tick )
		);
		$this->assertArrayNotHasKey( 'candidate', $tick );
		$this->assertArrayNotHasKey( 'sequence_log', $tick );
		$this->assertSame( array( 'supported' => true ), $tick['meta'] );
		$this->assertSame( $standalone, $tick['config_fingerprint'] );
	}

	/**
	 * Tick stays slim while sequence-log continues to serve full pages.
	 */
	public function test_tick_returns_slim_body_without_slimming_sequence_log(): void {
		$log = new Sync_Journal();
		for ( $id = 1; $id <= 101; $id++ ) {
			$log->record( 'product', $id, false, '', 'test', false );
		}

		$tick     = $this->server->dispatch( $this->tick_request() )->get_data();
		$request  = $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' );
		$sequence = $this->server->dispatch( $request )->get_data();

		$this->assertSame( array(), $tick['changes'] );
		$this->assertTrue( $tick['complete'] );
		$this->assertSame( 0, $tick['checkpoint']['since'] );
		$this->assertSame( $log->head_sequence(), $tick['checkpoint']['head'] );
		$this->assertArrayHasKey( 'config_fingerprint', $tick );
		$this->assertLessThan( 1024, strlen( (string) wp_json_encode( $tick ) ) );
		$this->assertCount( 100, $sequence['changes'] );
		$this->assertFalse( $sequence['complete'] );
	}

	/**
	 * Tick inherits the sequence-log retention horizon.
	 */
	public function test_tick_with_pruned_history_returns_sequence_log_watermark_horizon(): void {
		// Arrange.
		$log = new Sync_Journal();
		$log->record( 'product', 11, false, '', 'test', false );
		$log->advance_prune_watermark( 5 );

		// Act.
		$tick = $this->server->dispatch( $this->tick_request() )->get_data();
		delete_option( Sync_Journal::PRUNE_WATERMARK_OPTION );

		// Assert: the graduated tick lifts checkpoint to the top level.
		$this->assertEquals( 5, $tick['checkpoint']['horizon'] );
	}

	/**
	 * A matching ETag at the current head returns an empty 304.
	 */
	public function test_tick_matching_etag_at_head_returns_not_modified(): void {
		$first = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		$etag  = $first->get_headers()['ETag'];
		$head  = $first->get_data()['checkpoint']['head'];

		$request = $this->tick_request( array( 'since' => $head ) );
		$request->set_header( 'If-None-Match', $etag );
		$not_modified = $this->server->dispatch( $request );

		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );
		$this->assertSame( $etag, $not_modified->get_headers()['ETag'] );
		$this->assertSame( 'no-store', $not_modified->get_headers()['Cache-Control'] );
		// Telemetry must not decorate the empty 304 (Response_Telemetry skips
		// 304/4xx): no timing header, no injected body metrics.
		$this->assertArrayNotHasKey( 'Server-Timing', $not_modified->get_headers() );
	}

	/**
	 * A no-cursor tick preserves today's empty-log 304 behavior.
	 */
	public function test_tick_matching_etag_without_since_at_empty_head_returns_not_modified(): void {
		$first = $this->server->dispatch( $this->tick_request() );

		$request = $this->tick_request();
		$request->set_header( 'If-None-Match', $first->get_headers()['ETag'] );
		$not_modified = $this->server->dispatch( $request );

		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );
		$this->assertSame( $first->get_headers()['ETag'], $not_modified->get_headers()['ETag'] );
	}

	/**
	 * Tick emits the sequence-log validator: same server state, same ETag.
	 * Cached validators rely on this compatibility invariant.
	 */
	public function test_tick_etag_matches_sequence_log_etag_for_same_state(): void {
		( new Sync_Journal() )->record( 'product', 123, false, '', 'test', false );

		$tick    = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' );
		$request->set_query_params( array( 'since' => 0 ) );
		$sequence = $this->server->dispatch( $request );

		$this->assertSame( $sequence->get_headers()['ETag'], $tick->get_headers()['ETag'] );
	}

	/**
	 * Both polling routes expose the same journal generation marker.
	 */
	public function test_tick_and_sequence_log_share_epoch_and_install_changes_it(): void {
		$journal = new Sync_Journal();
		$tick    = $this->server->dispatch( $this->tick_request() )->get_data();
		$sequence = $this->server->dispatch(
			$this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' )
		)->get_data();

		$this->assertSame( $sequence['checkpoint']['epoch'], $tick['checkpoint']['epoch'] );
		$first_epoch = $tick['checkpoint']['epoch'];

		// Only a RECREATED table starts a new generation — install() on a
		// surviving table must keep the epoch (no needless all-client resync).
		global $wpdb;
		$journal->install();
		$this->assertSame( $first_epoch, $this->server->dispatch( $this->tick_request() )->get_data()['checkpoint']['epoch'] );
		// Real recreate: lift the WP suite's TEMPORARY-table query rewrites,
		// which otherwise leave the SHOW TABLES-probed real table in place.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( 'DROP TABLE ' . $journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
			$journal->install();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
		$next_tick = $this->server->dispatch( $this->tick_request() )->get_data();
		$next_sequence = $this->server->dispatch(
			$this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' )
		)->get_data();

		$this->assertSame( $next_sequence['checkpoint']['epoch'], $next_tick['checkpoint']['epoch'] );
		$this->assertNotSame( $first_epoch, $next_tick['checkpoint']['epoch'] );
	}

	/**
	 * A new change-log row invalidates a tick ETag.
	 */
	public function test_tick_etag_changes_when_sequence_head_moves(): void {
		$before = $this->server->dispatch( $this->tick_request( array( 'since' => 0 ) ) );
		( new Sync_Journal() )->record( 'product', 123, false, '', 'test', false );

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
	 * A matching ETag cannot turn a no-cursor tick into a 304 above head zero.
	 */
	public function test_tick_matching_etag_without_since_above_empty_head_returns_response(): void {
		( new Sync_Journal() )->record( 'product', 123, false, '', 'test', false );
		$current = $this->server->dispatch( $this->tick_request() );

		$request = $this->tick_request();
		$request->set_header( 'If-None-Match', $current->get_headers()['ETag'] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['changes'] );
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
