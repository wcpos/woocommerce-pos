<?php
/**
 * Meta-heavy scaling coverage for the v2 read surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

/**
 * Pins servability and query scaling for meta-heavy catalog records.
 */
class Test_Catalog_Proxy_Meta_Scaling extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the v2 routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the feature flag written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Insert many post meta rows in one query.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $count   Number of rows.
	 * @param string $prefix  Meta key prefix.
	 */
	private function add_bulk_post_meta( int $post_id, int $count, string $prefix ): void {
		global $wpdb;

		$values = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s)',
				$post_id,
				$prefix . '_' . $i,
				'value_' . $i
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Each value tuple is prepared above; direct batch insertion keeps scaling fixtures fast.
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );

		clean_post_cache( $post_id );
		wp_cache_flush();
	}

	/**
	 * Insert many order meta rows in one query for the active datastore.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $count    Number of rows.
	 * @param string $prefix   Meta key prefix.
	 */
	private function add_bulk_order_meta( int $order_id, int $count, string $prefix ): void {
		global $wpdb;

		$hpos_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		$table        = $hpos_enabled ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_column    = $hpos_enabled ? 'order_id' : 'post_id';
		$values       = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s)',
				$order_id,
				$prefix . '_' . $i,
				'value_' . $i
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column are internal allowlisted values; each value tuple is prepared above.
		$wpdb->query( "INSERT INTO {$table} ({$id_column}, meta_key, meta_value) VALUES " . implode( ', ', $values ) );

		clean_post_cache( $order_id );
		wp_cache_flush();
	}

	/**
	 * Return REST meta_data keyed by meta key.
	 *
	 * @param array $payload REST payload.
	 */
	private function meta_data( array $payload ): array {
		return array_column( $payload['meta_data'], 'value', 'key' );
	}

	/**
	 * A product with 300 meta rows remains intact through the products proxy.
	 */
	public function test_product_with_300_meta_entries_survives_catalog_proxy(): void {
		$product = ProductHelper::create_simple_product();
		$this->add_bulk_post_meta( $product->get_id(), 300, '_wcpos_scale_product' );

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $product->get_id() ) );
		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertGreaterThanOrEqual( 300, \count( $rows[0]['meta_data'] ) );
		$this->assertSame( 'value_299', $this->meta_data( $rows[0] )['_wcpos_scale_product_299'] );
	}

	/**
	 * An order with 200 meta rows remains intact through both v2 order reads.
	 */
	public function test_order_with_200_meta_entries_survives_proxy_and_pull(): void {
		$order = OrderHelper::create_order();
		$this->add_bulk_order_meta( $order->get_id(), 200, '_wcpos_scale_order' );
		Pos_Uuid::ensure_uuid( $order );

		$proxy_request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$proxy_request->set_param( 'include', array( $order->get_id() ) );
		$proxy_response = $this->server->dispatch( $proxy_request );
		$proxy_rows     = $proxy_response->get_data();

		$this->assertSame( 200, $proxy_response->get_status() );
		$this->assertCount( 1, $proxy_rows );
		$this->assertGreaterThanOrEqual( 200, \count( $proxy_rows[0]['meta_data'] ) );
		$this->assertSame( 'value_199', $this->meta_data( $proxy_rows[0] )['_wcpos_scale_order_199'] );

		$this->assertTrue( ( new Sync_Index() )->record_order_change( $order->get_id(), 'test:meta-scaling', false ) );

		$pull_request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$pull_request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);
		$pull_response = $this->server->dispatch( $pull_request );
		$pull_data     = $pull_response->get_data();

		$this->assertSame( 200, $pull_response->get_status() );
		$this->assertCount( 1, $pull_data['documents'], (string) wp_json_encode( $pull_data ) );
		$pull_payload = $pull_data['documents'][0]['payload'];
		$this->assertGreaterThanOrEqual( 200, \count( $pull_payload['meta_data'] ) );
		$this->assertSame( 'value_199', $this->meta_data( $pull_payload )['_wcpos_scale_order_199'] );
	}

	/**
	 * A one-megabyte meta value round-trips through the products proxy.
	 */
	public function test_product_with_one_megabyte_meta_value_survives_catalog_proxy(): void {
		$product     = ProductHelper::create_simple_product();
		$large_value = str_repeat( '0123456789abcdef', 65536 );
		update_post_meta( $product->get_id(), '_wcpos_scale_large_value', $large_value );

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $product->get_id() ) );
		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertSame( $large_value, $this->meta_data( $rows[0] )['_wcpos_scale_large_value'] );
	}

	/**
	 * Dispatch a warmed products list request and count its queries.
	 *
	 * @param array $include Product IDs to include.
	 *
	 * @return array{0: \WP_REST_Response, 1: int} Response and query count.
	 */
	private function measure_warmed_product_list( array $include ): array {
		$warmup = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$warmup->set_param( 'include', $include );
		$warmup->set_param( 'per_page', 10 );
		$this->server->dispatch( $warmup );

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', $include );
		$request->set_param( 'per_page', 10 );
		$queries_before = get_num_queries();
		$response       = $this->server->dispatch( $request );

		return array( $response, get_num_queries() - $queries_before );
	}

	/**
	 * Listing ten meta-heavy products avoids query-per-meta scaling.
	 */
	public function test_product_list_query_count_avoids_meta_n_plus_one_scaling(): void {
		$product_ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$product       = ProductHelper::create_simple_product();
			$product_ids[] = $product->get_id();
			$this->add_bulk_post_meta( $product->get_id(), 100, '_wcpos_scale_list_' . $i );
		}

		list( , $single_count )        = $this->measure_warmed_product_list( array( $product_ids[0] ) );
		list( $response, $list_count ) = $this->measure_warmed_product_list( $product_ids );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $response->get_data() );
		// Primary guard: the nine extra meta-heavy products may only add a
		// bounded per-product increment — a query-per-meta (or steep
		// per-product) regression blows past it. Measured 2026-07-29: warmed
		// single-product and ten-product requests both cost 8 queries (the
		// proxy batches meta), so 3 per product is pure headroom.
		$this->assertLessThanOrEqual(
			$single_count + ( 9 * 3 ),
			$list_count,
			"Ten-product list used {$list_count} queries vs {$single_count} for one product."
		);
		// Secondary absolute ceiling, ~3x the measured 71-query cold baseline.
		$this->assertLessThanOrEqual( 225, $list_count );
	}
}
