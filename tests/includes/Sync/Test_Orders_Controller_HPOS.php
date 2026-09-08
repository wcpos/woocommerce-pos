<?php
/**
 * HPOS coverage for the v2 order pull surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * Storage-sensitive order pull probes using HPOS.
 */
class Test_Orders_Controller_HPOS extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable the v2 sync routes and HPOS before creating orders.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage and sync schema state.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_sync_order_pull_payloads', array( Integrity_Digest::class, 'stamp_digests' ), 10 );
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::SCHEMA_OPTION );
	}

	/**
	 * The real Init wiring stamps the stored order digest onto pull payloads.
	 */
	public function test_hpos_pull_stamps_the_stored_digest_through_init_wiring(): void {
		update_option( Api::SCHEMA_OPTION, Api::SCHEMA_VERSION, false );
		new Init();

		$orders    = array( OrderHelper::create_order(), OrderHelper::create_order() );
		$order_ids = array();
		$digest    = new Integrity_Digest();
		$index     = new Sync_Journal();
		$serializer = new Order_Serializer();
		foreach ( $orders as $order ) {
			$order_ids[] = $order->get_id();
			// Settle identity first: post-#1746 the save path no longer serializes,
			// so the FIRST serialization mints the order/item uuids — a write that
			// bumps date_updated_gmt and re-upserts the stored digest via the Init
			// wiring. Capture $stored from the steady state, as a real pull cadence
			// would, or the stamp legitimately outruns this pre-pull snapshot.
			$serializer->serialize_order( $order->get_id(), new \WP_REST_Request() );
			$digest->upsert_order_digest( $order->get_id() );
			$index->record_order_change( $order->get_id(), 'test:digest-pull', false );
		}
		$stored         = ( new Digest_Index() )->read_digests( 'orders', $order_ids );
		$digest_queries = 0;
		$digest_table   = $digest->table_name();
		$count_digests  = static function ( string $query ) use ( &$digest_queries, $digest_table ): string {
			if ( false !== strpos( $query, "SELECT object_id, digest FROM {$digest_table}" ) && false !== strpos( $query, "object_type = 'order'" ) ) {
				++$digest_queries;
			}
			return $query;
		};
		add_filter( 'query', $count_digests );

		$request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);
		try {
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'query', $count_digests );
		}
		$documents = $response->get_data()['documents'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $documents );
		$this->assertSame( 1, $digest_queries, 'The pull page should preload all stored order digests in one query.' );
		foreach ( $documents as $document ) {
			$payload = $document['payload'];
			$bare    = $payload;
			unset( $bare['_rxdb_digest'] );

			$this->assertIsString( $payload['_rxdb_digest'] );
			$this->assertMatchesRegularExpression( '/^\d+$/', $payload['_rxdb_digest'] );
			$this->assertSame( $stored[ $payload['id'] ], $payload['_rxdb_digest'] );
			$this->assertSame( Order_Serializer::canonical_revision( $bare ), $document['sync']['revision'] );
			$this->assertSame( Order_Serializer::canonical_revision( $bare ), Order_Serializer::canonical_revision( $payload ) );
		}
	}

	/**
	 * First pull of an UNSTAMPED order must serve a durable revision.
	 *
	 * Serializing an order with no WCPOS uuid MINTS it (the Init-wired
	 * serialized-order filter persists identity), and that save advances the
	 * stored date_updated_gmt after the payload captured the pre-mint date. The
	 * pull re-serializes from the settled order, so the served revision equals a
	 * fresh post-pull recompute — the CAS side's view — instead of being stale
	 * the moment it leaves (a false 409 on the client's next push). The frozen
	 * past date makes the pre/post-mint divergence deterministic.
	 */
	public function test_hpos_first_pull_of_an_unstamped_order_serves_a_durable_revision(): void {
		global $wpdb;
		update_option( Api::SCHEMA_OPTION, Api::SCHEMA_VERSION, false );
		new Init();
		$order = OrderHelper::create_order();
		$wpdb->update( "{$wpdb->prefix}wc_orders", array( 'date_updated_gmt' => '2020-01-01 00:00:00' ), array( 'id' => $order->get_id() ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();
		( new Sync_Journal() )->record_order_change( $order->get_id(), 'test:first-pull', false );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( Pos_Uuid::META_KEY ), 'Fixture must start unstamped.' );

		$request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$documents = $response->get_data()['documents'];
		$this->assertCount( 1, $documents );

		$fresh = ( new Order_Serializer() )->serialize_order( $order->get_id(), new \WP_REST_Request() );
		$this->assertSame(
			Order_Serializer::canonical_revision( $fresh ),
			$documents[0]['sync']['revision'],
			'The served revision must match a post-pull recompute — the write path re-reads fresh.'
		);
	}

	/**
	 * The real pull route serializes the HPOS record and keys it by its UUID.
	 */
	public function test_hpos_pull_serializes_order_from_orders_table(): void {
		$order = OrderHelper::create_order();
		$order->set_status( 'processing' );
		$order->set_billing_first_name( 'HPOS Pull' );
		$order->save();
		$uuid = Pos_Uuid::ensure_uuid( $order );

		( new Sync_Journal() )->record_order_change( $order->get_id(), 'test:hpos-pull', false );

		$request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['documents'] );
		$this->assertSame( 0, $data['horizon'] );

		$document = $data['documents'][0];
		$this->assertSame( $uuid, $document['id'] );
		$this->assertArrayNotHasKey( 'wooOrderId', $document );
		$this->assertSame( $order->get_id(), $document['payload']['id'] );
		$this->assertSame( 'processing', $document['payload']['status'] );
		$this->assertSame( 'HPOS Pull', $document['payload']['billing']['first_name'] );
		$this->assertSame( Order_Serializer::canonical_revision( $document['payload'] ), $document['sync']['revision'] );
	}
}
