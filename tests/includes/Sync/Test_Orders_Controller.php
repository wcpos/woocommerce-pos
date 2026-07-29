<?php
/**
 * Tests for the orders custom-pull controller and the orders proxy lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Orders_Controller;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WP_REST_Request;

/**
 * Order read-lane behavior tests.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Orders_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Query
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Document
 */
class Test_Orders_Controller extends Sync_REST_Store_Test_Case {
	/** @var array<int, string> Deterministic per-order uuids the stub filter injects. */
	private $order_uuids = array();

	/**
	 * Supply the 2c precondition — a serialized order that carries
	 * _woocommerce_pos_uuid — via a deterministic stub. Production wires
	 * Pos_Uuid::stamp_serialized_record here; that stamper's ORDER collision
	 * detector runs an HPOS-only meta_query (orders are HPOS in production),
	 * which the CPT test datastore reports as doing_it_wrong. The stub models
	 * only the stamper's OUTCOME (uuid mirrored into the payload) so the pull
	 * re-key is exercised on the default datastore; the real stamper is covered
	 * by the identity suite under HPOS.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'woocommerce_pos_sync_serialized_order', array( $this, 'stamp_test_uuid' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_sync_serialized_order', array( $this, 'stamp_test_uuid' ), 10 );
		remove_filter( 'woocommerce_pos_sync_proxy_response', array( Meta_Normalizer::class, 'normalize' ), 5 );
		remove_filter( 'woocommerce_pos_sync_serialized_product', array( Meta_Normalizer::class, 'normalize' ), 5 );
		remove_filter( 'woocommerce_pos_sync_serialized_order', array( Meta_Normalizer::class, 'normalize' ), 5 );
		delete_option( Sync_Index::BACKFILL_OPTION );
		parent::tearDown();
	}

	/**
	 * Mirror a stable per-order uuid into the serialized payload.
	 *
	 * @param mixed      $payload Serialized order payload.
	 * @param mixed      $object  The WC order.
	 * @param null|mixed $request The serialization request.
	 */
	public function stamp_test_uuid( $payload, $object, $request = null ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}
		$order_id = is_object( $object ) && method_exists( $object, 'get_id' ) ? (int) $object->get_id() : (int) ( $payload['id'] ?? 0 );
		if ( ! isset( $this->order_uuids[ $order_id ] ) ) {
			$this->order_uuids[ $order_id ] = Pos_Uuid::generate_uuid();
		}

		return Pos_Uuid::ensure_in_payload( $payload, $this->order_uuids[ $order_id ] );
	}

	/**
	 * Build a request with query parameters.
	 *
	 * @param array $params Query parameters.
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params( $params );

		return $request;
	}

	/**
	 * A pull document is keyed by the order's uuid (the ADR 0021 re-key), never
	 * the numeric order id, and carries the wooOrderId + journal epoch/head.
	 */
	public function test_pull_document_is_keyed_by_uuid_and_carries_epoch_and_head(): void {
		$order = OrderHelper::create_order();

		( new Sync_Index() )->record_order_change( $order->get_id(), 'hook:update', false );

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
				)
			)
		);
		$data = $response->get_data();

		$this->assertCount( 1, $data['documents'] );
		$document = $data['documents'][0];
		// THE re-key: the document id is the uuid, not woo-order:<id>.
		$this->assertSame( $this->order_uuids[ $order->get_id() ], $document['id'] );
		$this->assertNotSame( '', $document['id'] );
		$this->assertSame( $order->get_id(), $document['wooOrderId'] );
		$this->assertSame( 'custom-pull', $document['sync']['source'] );
		$this->assertFalse( $document['local']['dirty'] );

		// F8 journal metadata surfaces on every batch.
		$this->assertIsString( $data['epoch'] );
		$this->assertNotSame( '', $data['epoch'] );
		$this->assertGreaterThanOrEqual( 1, $data['head'] );
		$this->assertSame( $order->get_id(), $data['checkpoint']['orderId'] );
	}

	/**
	 * Pulled orders carry the customer-facing checkout payment URL.
	 */
	public function test_pull_document_carries_payment_link(): void {
		$order = OrderHelper::create_order();
		( new Sync_Index() )->record_order_change( $order->get_id(), 'hook:update', false );

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit'          => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id'       => 0,
					'sequence'       => 0,
				)
			)
		);
		$payload = $response->get_data()['documents'][0]['payload'];

		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $payload['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $payload['links']['receipt'][0]['href'] );
	}

	/**
	 * Structured order and line-item meta is typed before the pull revision is computed.
	 */
	public function test_pull_emits_typed_meta_and_revision_of_the_normalized_payload(): void {
		Meta_Normalizer::register_hooks();

		$order = OrderHelper::create_order();
		$items = $order->get_items();
		$item  = reset( $items );
		$item->update_meta_data( '_woocommerce_pos_data', '{"register":"front","flags":["sale"]}' );
		$item->save_meta_data();
		$order->update_meta_data( '_wcpos_tax_ids', '["ES123","EU456"]' );
		$order->update_meta_data( 'php_array_fixture', array( 'already' => 'typed' ) );
		$order->save_meta_data();

		( new Sync_Index() )->record_order_change( $order->get_id(), 'hook:update', false );

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
				)
			)
		);
		$document   = $response->get_data()['documents'][0];
		$payload    = $document['payload'];
		$order_meta = array_column( $payload['meta_data'], 'value', 'key' );
		$line_meta  = array_column( $payload['line_items'][0]['meta_data'], 'value', 'key' );

		// Decoded JSON-object strings arrive as stdClass (shape-preserving decode); assert the wire JSON.
		$this->assertSame( '{"register":"front","flags":["sale"]}', wp_json_encode( $line_meta['_woocommerce_pos_data'] ) );
		$this->assertSame( array( 'ES123', 'EU456' ), $order_meta['_wcpos_tax_ids'] );
		$this->assertSame( array( 'already' => 'typed' ), $order_meta['php_array_fixture'] );
		$this->assertIsString( $order_meta[ Pos_Uuid::META_KEY ] );
		$this->assertSame( Order_Serializer::canonical_revision( $payload ), $document['sync']['revision'] );

		$historical = $payload;
		foreach ( $historical['meta_data'] as $index => $entry ) {
			// Untouched entries are still live WC_Meta_Data objects — read the key shape-tolerantly.
			$key = $entry instanceof \WC_Meta_Data ? $entry->key : $entry['key'];
			if ( '_wcpos_tax_ids' === $key ) {
				$historical['meta_data'][ $index ]['value'] = '["ES123","EU456"]';
			}
		}
		foreach ( $historical['line_items'][0]['meta_data'] as $index => $entry ) {
			if ( '_woocommerce_pos_data' === $entry['key'] ) {
				$historical['line_items'][0]['meta_data'][ $index ]['value'] = '{"register":"front","flags":["sale"]}';
			}
		}
		$this->assertNotSame( Order_Serializer::canonical_revision( $historical ), $document['sync']['revision'] );
	}

	/**
	 * A deleted index row advances the checkpoint but never leaks a tombstone
	 * unless the client opted in.
	 */
	public function test_deleted_row_advances_checkpoint_without_a_tombstone_when_not_opted_in(): void {
		$order = OrderHelper::create_order();
		$index = new Sync_Index();
		$index->record_order_change( $order->get_id(), 'hook:delete', true );
		$head = $index->head_sequence();

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( array(), $data['documents'] );
		$this->assertSame( array(), $data['deletes'] ); // no tombstone channel without opt-in
		$this->assertSame( $head, $data['checkpoint']['sequence'] ); // but the checkpoint advances past it
		$this->assertSame( $order->get_id(), $data['checkpoint']['orderId'] );
	}

	/**
	 * With include_deletes, a deleted order surfaces on the separate delete
	 * channel as its wooOrderId, never as a document.
	 */
	public function test_deleted_row_emits_a_tombstone_when_include_deletes_is_set(): void {
		$order = OrderHelper::create_order();
		( new Sync_Index() )->record_order_change( $order->get_id(), 'hook:delete', true );

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
					'include_deletes' => 1,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( array(), $data['documents'] );
		$this->assertSame( array( $order->get_id() ), $data['deletes'] );
	}

	/**
	 * An update then a delete for the same order in one page coalesce to a
	 * single tombstone at the latest sequence.
	 */
	public function test_update_then_delete_in_one_page_coalesces_to_a_tombstone(): void {
		$order = OrderHelper::create_order();
		$index = new Sync_Index();
		$index->record_order_change( $order->get_id(), 'hook:update', false );
		$index->record_order_change( $order->get_id(), 'hook:delete', true );
		$head = $index->head_sequence();

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 100,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
					'include_deletes' => 1,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( array(), $data['documents'] ); // the superseded update is not emitted
		$this->assertSame( array( $order->get_id() ), $data['deletes'] );
		$this->assertSame( $head, $data['checkpoint']['sequence'] );
	}

	/**
	 * A probe-row delete suppresses the page-end update for the same order.
	 */
	public function test_probe_delete_coalesces_page_end_update_without_emitting_a_stale_document(): void {
		$order = OrderHelper::create_order();
		$index = new Sync_Index();
		$index->record_order_change( $order->get_id(), 'hook:update', false );
		$page_sequence = $index->head_sequence();
		$index->record_order_change( $order->get_id(), 'hook:delete', true );

		$response = ( new Orders_Controller() )->pull_orders(
			$this->request(
				array(
					'limit' => 1,
					'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
					'order_id' => 0,
					'sequence' => 0,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( array(), $data['documents'] );
		$this->assertSame( array(), $data['deletes'] );
		$this->assertSame( $page_sequence, $data['checkpoint']['sequence'] );
		$this->assertTrue( $data['hasMore'] );
	}

	/**
	 * The modified-date fallback uses the order id as the tie-breaker when more
	 * than the old bounded overscan can share one modified second.
	 */
	public function test_fallback_pull_pages_every_order_sharing_the_checkpoint_second_exactly_once(): void {
		global $wpdb;

		$limit               = 2;
		$order_count         = ( 3 * $limit ) + 5;
		$shared_modified_gmt = '2020-01-02 03:04:05';
		$expected_order_ids  = array();

		for ( $i = 0; $i < $order_count; $i++ ) {
			$order                = OrderHelper::create_order();
			$expected_order_ids[] = $order->get_id();
			$updated = $wpdb->update(
				$wpdb->posts,
				array(
					'post_modified' => $shared_modified_gmt,
					'post_modified_gmt' => $shared_modified_gmt,
				),
				array( 'ID' => $order->get_id() ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$this->assertSame( 1, $updated );
			clean_post_cache( $order->get_id() );
		}

		$index = new Sync_Index();
		$wpdb->query( 'DELETE FROM ' . $index->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.

		$received_order_ids = array();
		$page_count         = 0;
		$checkpoint = array(
			'updated_at_gmt' => gmdate( 'c', strtotime( $shared_modified_gmt ) ),
			'order_id' => 0,
			'sequence' => 0,
		);

		do {
			$page_count++;
			$this->assertLessThanOrEqual( $order_count, $page_count );
			$response = ( new Orders_Controller() )->pull_orders(
				$this->request(
					array_merge(
						$checkpoint,
						array( 'limit' => $limit )
					)
				)
			);
			$data = $response->get_data();

			$received_order_ids = array_merge( $received_order_ids, array_column( $data['documents'], 'wooOrderId' ) );
			$checkpoint = array(
				'updated_at_gmt' => $data['checkpoint']['updatedAtGmt'],
				'order_id' => $data['checkpoint']['orderId'],
				'sequence' => $data['checkpoint']['sequence'],
			);

			$this->assertSame( count( $received_order_ids ) < $order_count, $data['hasMore'] );
		} while ( $data['hasMore'] );

		$this->assertCount( $order_count, array_unique( $received_order_ids ) );
		$this->assertSame( $expected_order_ids, $received_order_ids );
	}

	/**
	 * The index backfill runs one bounded chunk.
	 */
	public function test_index_backfill_runs_one_bounded_chunk(): void {
		OrderHelper::create_order();
		OrderHelper::create_order();
		OrderHelper::create_order();

		$response = ( new Orders_Controller() )->index_backfill( $this->request( array( 'limit' => 1 ) ) );
		$data     = $response->get_data();

		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 1, $data['processedThisRun'] );
		$this->assertSame( 1, $data['processed'] );
		$this->assertSame( 2, $data['nextPage'] );
	}

	/**
	 * The orders proxy lane (un-skipped in 2c) serves the wc/v3 orders list with
	 * the uuid stamped in per-record meta.
	 */
	public function test_orders_proxy_serves_the_order_with_a_stamped_uuid(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		$order = OrderHelper::create_order();
		$uuid  = Pos_Uuid::ensure_uuid( $order );

		$data = ( new Catalog_Proxy_Controller() )->proxy(
			$this->request( array( 'include' => (string) $order->get_id() ) ),
			'/wc/v3/orders',
			'orders'
		)->get_data();

		$ids = array_column( (array) $data, 'id' );
		$this->assertContains( $order->get_id(), $ids );

		$served = null;
		foreach ( (array) $data as $row ) {
			if ( isset( $row['id'] ) && $order->get_id() === $row['id'] ) {
				$served = $row;
			}
		}
		$this->assertNotNull( $served );
		$this->assertSame( $uuid, Pos_Uuid::read_valid_uuid_from_meta( $served['meta_data'] ?? array() ) );
		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $served['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $served['links']['receipt'][0]['href'] );
	}

	/**
	 * The POS links survive the cashier lane: the proxy serves both checkout-route
	 * hrefs (payment + receipt) when the request runs as a cashier, not an admin.
	 */
	public function test_orders_proxy_serves_pos_links_as_cashier(): void {
		$order   = OrderHelper::create_order();
		$cashier = self::factory()->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier );

		$data = ( new Catalog_Proxy_Controller() )->proxy(
			$this->request( array( 'include' => (string) $order->get_id() ) ),
			'/wc/v3/orders',
			'orders'
		)->get_data();

		$served = null;
		foreach ( (array) $data as $row ) {
			if ( isset( $row['id'] ) && $order->get_id() === $row['id'] ) {
				$served = $row;
			}
		}
		$this->assertNotNull( $served );
		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $served['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $served['links']['receipt'][0]['href'] );
	}

	/**
	 * The POS checkout-route payment link (V1 parity), as add_payment_link() builds it.
	 */
	private function wcpos_expected_payment_link( \WC_Order $order ): string {
		return add_query_arg(
			array(
				'pay_for_order' => true,
				'key'           => $order->get_order_key(),
			),
			wcpos_checkout_url( 'order-pay/' . $order->get_id() )
		);
	}

	/**
	 * The POS receipt link (V1 parity), as add_receipt_link() builds it.
	 */
	private function wcpos_expected_receipt_link( \WC_Order $order ): string {
		return add_query_arg(
			array( 'key' => $order->get_order_key() ),
			wcpos_checkout_url( 'wcpos-receipt/' . $order->get_id() )
		);
	}
}
