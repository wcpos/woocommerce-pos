<?php
/**
 * Tests for sync operations endpoints.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Customer;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_REST_Request;

/**
 * UUID backfill and integrity rebuild behavior on a healthy sync store.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Uuid_Backfill_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Controller
 */
class Test_Sync_Ops_Endpoints extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the sync routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the non-transactional feature flag.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Dispatch a UUID backfill request.
	 *
	 * @param string $collection Collection name.
	 * @param int    $since_id   Starting ID cursor.
	 * @param string $mode       Backfill mode.
	 *
	 * @return array<string, mixed>
	 */
	private function backfill( string $collection, int $since_id = 0, string $mode = 'missing' ): array {
		$request = $this->wp_rest_post_request( '/wcpos/v1/sync/uuid/backfill' );
		$request->set_query_params(
			array(
				'collection' => $collection,
				'limit'      => 20,
				'since_id'   => $since_id,
				'mode'       => $mode,
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Products and variations receive persistent UUIDs in either shared post stream.
	 */
	public function test_uuid_backfill_stamps_missing_product_and_variation_uuids(): void {
		$simple = ProductHelper::create_simple_product();
		delete_post_meta( $simple->get_id(), Api::UUID_META_KEY );

		$product_result = $this->backfill( 'products', $simple->get_id() - 1 );

		$this->assertSame(
			array( 'collection', 'mode', 'scanned', 'stamped', 'skipped', 'next_since_id', 'complete', 'supported' ),
			array_keys( $product_result )
		);
		$this->assertSame( 'products', $product_result['collection'] );
		$this->assertSame( 'missing', $product_result['mode'] );
		$this->assertSame( 1, $product_result['scanned'] );
		$this->assertSame( 1, $product_result['stamped'] );
		$this->assertSame( 0, $product_result['skipped'] );
		$this->assertSame( $simple->get_id(), $product_result['next_since_id'] );
		$this->assertTrue( $product_result['complete'] );
		$this->assertTrue( $product_result['supported'] );
		$this->assertTrue( Pos_Uuid::is_uuid( get_post_meta( $simple->get_id(), Api::UUID_META_KEY, true ) ) );

		$variable      = ProductHelper::create_variation_product();
		$variation_ids = array_map( 'intval', $variable->get_children() );
		$ids           = array_merge( array( $variable->get_id() ), $variation_ids );
		foreach ( $ids as $id ) {
			delete_post_meta( $id, Api::UUID_META_KEY );
		}

		$variation_result = $this->backfill( 'variations', $variable->get_id() - 1 );

		$this->assertSame( \count( $ids ), $variation_result['scanned'] );
		$this->assertSame( \count( $ids ), $variation_result['stamped'] );
		foreach ( $ids as $id ) {
			$this->assertTrue( Pos_Uuid::is_uuid( get_post_meta( $id, Api::UUID_META_KEY, true ) ) );
		}
	}

	/**
	 * Orders, customers, and product terms use their native meta stores.
	 */
	public function test_uuid_backfill_stamps_missing_order_customer_and_term_uuids(): void {
		$order = wc_create_order();
		$order->delete_meta_data( Api::UUID_META_KEY );
		$order->save_meta_data();

		$order_result = $this->backfill( 'orders', $order->get_id() - 1 );
		$order         = wc_get_order( $order->get_id() );

		$this->assertSame( 1, $order_result['stamped'] );
		$this->assertTrue( $order_result['supported'] );
		$this->assertTrue( Pos_Uuid::is_uuid( $order->get_meta( Api::UUID_META_KEY, true ) ) );

		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		delete_user_meta( $customer_id, Api::UUID_META_KEY );

		$customer_result = $this->backfill( 'customers', $customer_id - 1 );
		$customer        = new WC_Customer( $customer_id );

		$this->assertSame( 1, $customer_result['stamped'] );
		$this->assertTrue( Pos_Uuid::is_uuid( $customer->get_meta( Api::UUID_META_KEY, true ) ) );

		$term = wp_insert_term( 'Backfill category', 'product_cat' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		delete_term_meta( $term_id, Api::UUID_META_KEY );

		$term_result = $this->backfill( 'categories', $term_id - 1 );

		$this->assertSame( 1, $term_result['stamped'] );
		$this->assertTrue( Pos_Uuid::is_uuid( get_term_meta( $term_id, Api::UUID_META_KEY, true ) ) );
	}

	/**
	 * Collision mode preserves the lowest holder and regenerates later duplicates.
	 */
	public function test_uuid_backfill_collisions_repairs_duplicate_uuids(): void {
		$first     = ProductHelper::create_simple_product();
		$second    = ProductHelper::create_simple_product();
		$duplicate = wp_generate_uuid4();
		update_post_meta( $first->get_id(), Api::UUID_META_KEY, $duplicate );
		update_post_meta( $second->get_id(), Api::UUID_META_KEY, $duplicate );

		$result = $this->backfill( 'products', $first->get_id(), 'collisions' );

		$this->assertSame( 'collisions', $result['mode'] );
		$this->assertSame( 1, $result['scanned'] );
		$this->assertSame( 1, $result['stamped'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( $duplicate, get_post_meta( $first->get_id(), Api::UUID_META_KEY, true ) );
		$this->assertTrue( Pos_Uuid::is_uuid( get_post_meta( $second->get_id(), Api::UUID_META_KEY, true ) ) );
		$this->assertNotSame( $duplicate, get_post_meta( $second->get_id(), Api::UUID_META_KEY, true ) );
	}

	/**
	 * Both operations require WooCommerce management access.
	 */
	public function test_ops_endpoints_without_manage_woocommerce_return_403(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		foreach ( array( '/wcpos/v1/sync/uuid/backfill', '/wcpos/v1/sync/integrity/rebuild' ) as $path ) {
			$response = $this->server->dispatch( $this->wp_rest_post_request( $path ) );
			$this->assertSame( 403, $response->get_status(), $path );
			$this->assertNotSame( 503, $response->get_status(), $path );
		}
	}

	/**
	 * Rebuild makes the stored product digests agree with a raw current-state scan.
	 */
	public function test_integrity_rebuild_reconstructs_digests_to_match_from_scratch_scan(): void {
		global $wpdb;

		$product = ProductHelper::create_simple_product();
		$digest  = new Integrity_Digest();
		$table   = $digest->table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table name.

		$bucket_size = 1000;
		$bucket      = (int) floor( $product->get_id() / $bucket_size );
		$scan        = new WP_REST_Request( 'GET', '/' );
		$scan->set_query_params(
			array(
				'bucket'      => $bucket,
				'bucket_size' => $bucket_size,
			)
		);
		$before = ( new Integrity_Controller( $digest ) )->scan( $scan )->get_data();
		$this->assertContains( $product->get_id(), array_column( $before['changes'], 'id' ) );

		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v1/sync/integrity/rebuild' ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'hash-checksum', $response->get_data()['candidate'] );
		$this->assertSame( 'products', $response->get_data()['collection'] );
		$this->assertGreaterThanOrEqual( 1, $response->get_data()['stored_total'] );
		$this->assertTrue( $response->get_data()['meta']['supported'] );

		$after = ( new Integrity_Controller( $digest ) )->scan( $scan )->get_data();
		$this->assertNotContains( $product->get_id(), array_column( $after['changes'], 'id' ) );
	}
}
