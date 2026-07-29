<?php
/**
 * HPOS coverage for the v2 order pull surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Sync_Index;

/**
 * Storage-sensitive order pull probes using HPOS.
 */
class Test_Orders_Controller_HPOS extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable the v2 sync routes and HPOS before creating orders.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage and the sync feature flag.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
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

		( new Sync_Index() )->record_order_change( $order->get_id(), 'test:hpos-pull', false );

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

		$document = $data['documents'][0];
		$this->assertSame( $uuid, $document['id'] );
		$this->assertSame( $order->get_id(), $document['wooOrderId'] );
		$this->assertSame( $order->get_id(), $document['payload']['id'] );
		$this->assertSame( 'processing', $document['payload']['status'] );
		$this->assertSame( 'HPOS Pull', $document['payload']['billing']['first_name'] );
		$this->assertSame( Order_Serializer::canonical_revision( $document['payload'] ), $document['sync']['revision'] );
	}
}
