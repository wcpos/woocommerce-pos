<?php
/**
 * Tests for integrity digest self-healing.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * Empty stored product digests recover without escalating every product.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Integrity_Self_Heal extends Sync_REST_Store_Test_Case {
	/**
	 * Enable sync routes and isolate cron state.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
	}

	/**
	 * Remove non-transactional option, transient, and cron state.
	 */
	public function tearDown(): void {
		delete_option( Api::OPTION_ENABLED );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		parent::tearDown();
	}

	/**
	 * Create a live product while leaving the product-space digest table empty.
	 */
	private function create_product_with_empty_stored_digests(): int {
		global $wpdb;

		$product = ProductHelper::create_simple_product();
		$digest  = new Integrity_Digest();
		$wpdb->query(
			'DELETE FROM ' . $digest->table_name() . ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL
		);

		return $product->get_id();
	}

	/**
	 * Dispatch an aggregate integrity scan covering one bucket.
	 *
	 * @param int $product_id Product ID used to select the bucket.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch_aggregate_scan( int $product_id ): array {
		$bucket_size = 1000;
		$bucket      = (int) floor( $product_id / $bucket_size );
		$after_id    = $bucket > 0 ? ( $bucket * $bucket_size ) - 1 : 0;
		$request     = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size'   => $bucket_size,
				'after_id'      => $after_id,
				'limit_buckets' => 1,
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Empty stored digests stand clients down and schedule one rebuild.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/1373
	 */
	public function test_empty_stored_table_returns_no_aggregate_mismatches_and_schedules_rebuild(): void {
		$product_id = $this->create_product_with_empty_stored_digests();

		$data = $this->dispatch_aggregate_scan( $product_id );

		$this->assertSame( array(), $data['changes'] );
		$this->assertTrue( $data['complete'] );
		$this->assertTrue( $data['meta']['rebuilding'] );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	/**
	 * The transient guard prevents another scan from stacking an event.
	 */
	public function test_second_scan_while_lock_is_held_does_not_schedule_another_rebuild(): void {
		$product_id = $this->create_product_with_empty_stored_digests();
		$this->dispatch_aggregate_scan( $product_id );
		$timestamp = wp_next_scheduled( Integrity_Digest::REBUILD_HOOK );
		$this->assertNotFalse( $timestamp );
		wp_unschedule_event( $timestamp, Integrity_Digest::REBUILD_HOOK );
		$this->assertNotFalse( get_transient( Integrity_Digest::REBUILD_LOCK ) );

		$this->dispatch_aggregate_scan( $product_id );

		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	/**
	 * The scheduled callback rebuilds digests and restores real verdicts.
	 */
	public function test_scheduled_callback_rebuilds_and_next_scan_returns_real_verdicts(): void {
		global $wpdb;

		$product_id = $this->create_product_with_empty_stored_digests();
		$this->dispatch_aggregate_scan( $product_id );

		do_action( Integrity_Digest::REBUILD_HOOK );

		$stored_total = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . ( new Integrity_Digest() )->table_name()
			. ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL
		);
		$this->assertGreaterThan( 0, $stored_total );
		$this->assertFalse( get_transient( Integrity_Digest::REBUILD_LOCK ) );

		$data   = $this->dispatch_aggregate_scan( $product_id );
		$bucket = (int) floor( $product_id / 1000 );
		$rows   = array_values( array_filter( $data['changes'], static fn ( array $row ): bool => $bucket === $row['bucket'] ) );

		$this->assertCount( 1, $rows );
		$this->assertTrue( $rows[0]['match'] );
		$this->assertGreaterThan( 0, $rows[0]['current_count'] );
		$this->assertArrayNotHasKey( 'rebuilding', $data['meta'] );
	}

	/**
	 * A healthy aggregate response keeps its existing wire shape and values.
	 */
	public function test_healthy_stored_table_preserves_aggregate_response(): void {
		$product    = ProductHelper::create_simple_product();
		$product_id = $product->get_id();
		$digest     = new Integrity_Digest();
		$digest->upsert_digest( $product_id );
		$stored_digest = $digest->read_digests( array( $product_id ) )[ $product_id ];
		$bucket        = (int) floor( $product_id / 1000 );

		$data = $this->dispatch_aggregate_scan( $product_id );
		unset( $data['meta']['duration_ms'] );

		$this->assertSame(
			array(
				'candidate'  => 'hash-checksum',
				'collection' => 'products',
				'checkpoint' => array(
					'bucket_size' => 1000,
					'after_id' => ( ( $bucket + 1 ) * 1000 ) - 1,
				),
				'changes'    => array(
					array(
						'bucket'         => $bucket,
						'range'          => array(
							'start' => $bucket * 1000,
							'end' => ( $bucket + 1 ) * 1000,
						),
						'stored_count'   => 1,
						'current_count'  => 1,
						'stored_digest'  => $stored_digest,
						'current_digest' => $stored_digest,
						'match'          => true,
					),
				),
				'complete'   => true,
				'meta'       => array(
					'supported' => true,
					'note'      => 'stored hook-time digests vs current raw-row digests, BIT_XOR(64-bit MD5-derived) per bucket; mismatch = content changed without hooks (or stored side not yet backfilled).',
				),
			),
			$data
		);
	}

	/**
	 * Drill-down also stands down while the empty table is rebuilding.
	 */
	public function test_empty_stored_table_returns_no_drill_down_mismatches_while_rebuilding(): void {
		$product_id = $this->create_product_with_empty_stored_digests();
		$request    = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size' => 1000,
				'bucket'      => (int) floor( $product_id / 1000 ),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( array(), $data['changes'] );
		$this->assertTrue( $data['complete'] );
		$this->assertTrue( $data['meta']['rebuilding'] );
	}

	/**
	 * Current-side digests must not clamp at 2^63: MySQL coerces the CONV()
	 * string column of a derived table as SIGNED inside BIT_XOR, clamping any
	 * row digest >= 2^63 to PHP_INT_MAX while the stored BIGINT UNSIGNED side
	 * keeps the true value — permanent phantom mismatches for ~half of all
	 * rows. 24 products make an all-buckets-match run astronomically unlikely
	 * (2^-24) without the CAST(... AS UNSIGNED) fix.
	 */
	public function test_scan_current_side_does_not_clamp_unsigned_digests(): void {
		$digest = new Integrity_Digest();
		$ids    = array();
		for ( $i = 0; $i < 24; $i++ ) {
			$product = ProductHelper::create_simple_product();
			$ids[]   = $product->get_id();
			$digest->upsert_digest( $product->get_id() );
		}

		$data = $this->dispatch_aggregate_scan( $ids[0] );

		foreach ( $data['changes'] as $row ) {
			$this->assertNotSame( '9223372036854775807', $row['current_digest'], 'current side clamped at 2^63' );
			$this->assertTrue( $row['match'], wp_json_encode( $row ) );
		}
	}
}
