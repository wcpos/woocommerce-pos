<?php
/**
 * REST-dispatch coverage for the order-item UUID fast path.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

/**
 * Order-item UUID fast-path tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Serializer
 */
class Test_Order_Item_Uuid_Fast_Path extends Sync_REST_Store_Test_Case {
	/**
	 * Enable v2 sync before REST routes are registered.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Restore the sync feature flag.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Valid in-memory item UUIDs bypass locking and metadata writes on v2 reads.
	 */
	public function test_v2_orders_proxy_valid_item_uuid_uses_fast_path(): void {
		$order   = OrderHelper::create_order();
		$items   = $order->get_items();
		$item    = reset( $items );
		$item_id = $item->get_id();
		$uuid    = wp_generate_uuid4();
		$item->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$item->save_meta_data();
		// Stamp the non-line items too — OrderHelper orders carry a shipping item,
		// and any unstamped item takes the legitimate mint path (lock included),
		// which this fully-stamped-order test must not count as a failure.
		foreach ( $order->get_items( array( 'shipping', 'fee' ) ) as $other_item ) {
			$other_item->update_meta_data( Pos_Uuid::META_KEY, wp_generate_uuid4() );
			$other_item->save_meta_data();
		}

		$stage_pending_meta = static function ( array $served_items ) use ( $item_id ): array {
			foreach ( $served_items as $served_item ) {
				if ( $item_id === $served_item->get_id() ) {
					$served_item->update_meta_data( '_wcpos_fast_path_probe', 'pending' );
				}
			}

			return $served_items;
		};
		$lock_queries = array();
		$capture_lock_queries = static function ( string $query ) use ( &$lock_queries ): string {
			if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
				$lock_queries[] = $query;
			}

			return $query;
		};
		add_filter( 'woocommerce_order_get_items', $stage_pending_meta, 9 );
		add_filter( 'query', $capture_lock_queries );

		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'include' => (string) $order->get_id() ) );
		try {
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'query', $capture_lock_queries );
			remove_filter( 'woocommerce_order_get_items', $stage_pending_meta, 9 );
		}

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( array(), $lock_queries );
		$this->assertSame( '', wc_get_order_item_meta( $item_id, '_wcpos_fast_path_probe', true ) );
		$this->assertSame( $uuid, Pos_Uuid::read_valid_uuid_from_meta( $data[0]['line_items'][0]['meta_data'] ?? array() ) );
	}
}
