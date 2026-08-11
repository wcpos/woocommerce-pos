<?php
/**
 * Tests for collection-aware aggregate integrity scans.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;

/**
 * REST-dispatched aggregate scan coverage.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index
 */
class Test_Integrity_Scan extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable sync routes and isolate visibility/cron state.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
	}

	/**
	 * Remove non-transactional state.
	 */
	public function tearDown(): void {
		delete_option( Api::OPTION_ENABLED );
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		parent::tearDown();
	}

	/**
	 * Dispatch one aggregate scan window.
	 *
	 * @param string      $collection    Id-space to scan.
	 * @param int         $bucket_size   Ids per bucket.
	 * @param int         $after_id      Pagination checkpoint.
	 * @param int         $limit_buckets Buckets per window.
	 * @param string|null $status        Optional servable-status filter.
	 *
	 * @return \WP_REST_Response
	 */
	private function scan( string $collection, int $bucket_size, int $after_id, int $limit_buckets, ?string $status = null ) {
		$params = array(
			'collection'    => $collection,
			'bucket_size'   => $bucket_size,
			'after_id'      => $after_id,
			'limit_buckets' => $limit_buckets,
		);
		if ( null !== $status ) {
			$params['status'] = $status;
		}
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params( $params );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch the one bucket containing an id.
	 *
	 * @param string $collection Id-space to scan.
	 * @param int    $id         The id whose bucket to fetch.
	 *
	 * @return array<string, mixed>
	 */
	private function scan_id( string $collection, int $id ): array {
		$response = $this->scan( $collection, 1, $id - 1, 1 );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Customers aggregate over wp_users and echo their collection.
	 */
	public function test_customer_scan_echoes_collection_and_returns_both_aggregate_sides(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		( new Integrity_Digest() )->upsert_customer_digest( $user_id );

		$data = $this->scan_id( 'customers', $user_id );
		$row  = $data['changes'][0];

		$this->assertSame( 'customers', $data['collection'] );
		$this->assertSame( 1, $row['stored_count'] );
		$this->assertSame( 1, $row['current_count'] );
		$this->assertIsString( $row['stored_digest'] );
		$this->assertIsString( $row['current_digest'] );
		$this->assertSame( $row['stored_digest'], $row['current_digest'] );

		$publish = $this->scan( 'customers', 1, $user_id - 1, 1, 'publish' )->get_data();
		$this->assertSame( $data['changes'], $publish['changes'] );
	}

	/**
	 * Orders aggregate over their storage backend and expose SQL-bypass deletes.
	 */
	public function test_order_scan_reflects_orders_and_detects_a_direct_sql_delete(): void {
		global $wpdb;
		$order   = OrderHelper::create_order();
		$order_id = $order->get_id();
		$digest  = new Integrity_Digest();
		$digest->upsert_order_digest( $order_id );

		$before = $this->scan_id( 'orders', $order_id );
		$row    = $before['changes'][0];
		$this->assertSame( 'orders', $before['collection'] );
		$this->assertSame( 1, $row['stored_count'] );
		$this->assertSame( 1, $row['current_count'] );
		$this->assertIsString( $row['stored_digest'] );
		$this->assertIsString( $row['current_digest'] );
		$this->assertSame( $row['stored_digest'], $row['current_digest'] );

		$wpdb->delete( $wpdb->posts, array( 'ID' => $order_id ), array( '%d' ) );

		$after = $this->scan_id( 'orders', $order_id );
		$row   = $after['changes'][0];
		$this->assertSame( 1, $row['stored_count'] );
		$this->assertSame( 0, $row['current_count'] );
		$this->assertFalse( $row['match'] );
	}

	/**
	 * HPOS order scans expose a digest whose authoritative row was deleted directly.
	 */
	public function test_order_scan_detects_a_direct_hpos_sql_delete(): void {
		global $wpdb;
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );

		try {
			$order    = OrderHelper::create_order();
			$order_id = $order->get_id();
			( new Integrity_Digest() )->upsert_order_digest( $order_id );

			$before = $this->scan_id( 'orders', $order_id );
			$row    = $before['changes'][0];
			$this->assertSame( 1, $row['stored_count'] );
			$this->assertSame( 1, $row['current_count'] );
			$this->assertSame( $row['stored_digest'], $row['current_digest'] );

			$wpdb->delete( $wpdb->prefix . 'wc_orders', array( 'id' => $order_id ), array( '%d' ) );

			$after = $this->scan_id( 'orders', $order_id );
			$row   = $after['changes'][0];
			$this->assertSame( 1, $row['stored_count'] );
			$this->assertSame( 0, $row['current_count'] );
			$this->assertFalse( $row['match'] );
		} finally {
			$this->toggle_cot_feature_and_usage( false );
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}

	/**
	 * Unknown aggregate collections fail closed and name the request value.
	 */
	public function test_scan_rejects_an_unsupported_collection(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params( array( 'collection' => 'bogus_collection' ) );
		$result = ( new Integrity_Controller() )->scan( $request );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'bogus_collection', $result->get_error_message() );
	}

	/**
	 * The legacy scan drill-down remains products-only.
	 */
	public function test_scan_rejects_order_collection_drill_down(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'collection' => 'orders',
				'bucket'     => 0,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'orders', $response->get_data()['message'] );
	}

	/**
	 * Non-product scans never schedule the product-only empty-store rebuild.
	 */
	public function test_customer_scan_skips_the_product_empty_digest_rebuild(): void {
		global $wpdb;
		$product = ProductHelper::create_simple_product();
		$wpdb->query(
			'DELETE FROM ' . ( new Digest_Index() )->table_name() . ' WHERE object_type IN ' . Digest_Index::OBJECT_TYPES_SQL // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table and constant.
		);
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		( new Integrity_Digest() )->upsert_customer_digest( $user_id );

		$data = $this->scan_id( 'customers', $user_id );

		$this->assertNotEmpty( $product->get_id() );
		$this->assertArrayNotHasKey( 'rebuilding', $data['meta'] );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	/**
	 * Fold decimal digest strings with BIT_XOR without narrowing them through PHP integers.
	 *
	 * @param array<int, array<string, mixed>> $rows Bucket-listing rows.
	 */
	private function xor_digests( array $rows ): string {
		global $wpdb;
		$selects = array();
		foreach ( $rows as $row ) {
			$selects[] = $wpdb->prepare( 'SELECT CAST(%s AS UNSIGNED) AS digest', (string) $row['digest'] );
		}

		return (string) $wpdb->get_var( 'SELECT BIT_XOR(digest) FROM (' . implode( ' UNION ALL ', $selects ) . ') digest_rows' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every value was prepared above.
	}

	/**
	 * The status=publish filter makes scan membership identical to integrity/bucket.
	 */
	public function test_product_publish_scan_matches_bucket_listing_membership(): void {
		$published = ProductHelper::create_simple_product();
		$draft     = ProductHelper::create_simple_product();
		$draft->set_status( 'draft' );
		$draft->save();
		$hidden = ProductHelper::create_simple_product();
		$parent = ProductHelper::create_variation_product();
		$parent->set_status( 'draft' );
		$parent->save();
		$variation = (int) $parent->get_children()[0];

		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $hidden->get_id() ) ),
					),
				),
			)
		);

		$ids    = array( $published->get_id(), $draft->get_id(), $hidden->get_id(), $parent->get_id(), $variation );
		$digest = new Integrity_Digest();
		foreach ( $ids as $id ) {
			$digest->upsert_digest( $id );
		}

		$bucket_size = 1000;
		$first       = (int) floor( min( $ids ) / $bucket_size );
		$last        = (int) floor( max( $ids ) / $bucket_size );
		$after_id    = $first > 0 ? ( $first * $bucket_size ) - 1 : 0;
		$limit       = $last - $first + 1;
		$published_scan = $this->scan( 'products', $bucket_size, $after_id, $limit, 'publish' );
		$this->assertSame( 200, $published_scan->get_status(), wp_json_encode( $published_scan->get_data() ) );
		$published_rows = $published_scan->get_data()['changes'];

		$listed_ids = array();
		foreach ( $published_rows as $row ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/bucket' );
			$request->set_query_params(
				array(
					'collection'  => 'products',
					'bucket_size' => $bucket_size,
					'bucket'      => $row['bucket'],
					'status'      => 'publish',
				)
			);
			$listing = $this->server->dispatch( $request );
			$this->assertSame( 200, $listing->get_status(), wp_json_encode( $listing->get_data() ) );
			$bucket_rows = $listing->get_data()['ids'];
			$listed_ids  = array_merge( $listed_ids, array_column( $bucket_rows, 'id' ) );

			$this->assertSame( count( $bucket_rows ), $row['stored_count'] );
			$this->assertSame( count( $bucket_rows ), $row['current_count'] );
			$this->assertSame( $this->xor_digests( $bucket_rows ), $row['stored_digest'] );
			$this->assertSame( $this->xor_digests( $bucket_rows ), $row['current_digest'] );
		}

		$this->assertContains( $published->get_id(), $listed_ids );
		$this->assertNotContains( $draft->get_id(), $listed_ids );
		$this->assertNotContains( $hidden->get_id(), $listed_ids );
		$this->assertNotContains( $variation, $listed_ids );

		$legacy = $this->scan( 'products', $bucket_size, $after_id, $limit )->get_data()['changes'];
		$this->assertGreaterThan( array_sum( array_column( $published_rows, 'current_count' ) ), array_sum( array_column( $legacy, 'current_count' ) ) );
		$this->assertNotSame( array_column( $published_rows, 'current_digest', 'bucket' ), array_column( $legacy, 'current_digest', 'bucket' ) );
	}

	/**
	 * A publish scan must reach an orphaned digest past the last servable product.
	 */
	public function test_product_publish_scan_completion_includes_orphaned_digest_id(): void {
		global $wpdb;
		$published = ProductHelper::create_simple_product();
		$orphan    = ProductHelper::create_simple_product();
		$digest    = new Integrity_Digest();
		$digest->upsert_digest( $published->get_id() );
		$digest->upsert_digest( $orphan->get_id() );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $orphan->get_id() ), array( '%d' ) );

		$response = $this->scan( 'products', 1, $published->get_id() - 1, 1, 'publish' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $response->get_data()['complete'] );
	}
}
