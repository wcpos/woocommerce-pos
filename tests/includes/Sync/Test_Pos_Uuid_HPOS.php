<?php
/**
 * HPOS tests for sync record identity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;

/**
 * HPOS UUID ownership tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid
 */
class Test_Pos_Uuid_HPOS extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS for each test in this class.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore legacy order storage after each test.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}

	/**
	 * The v2 orders proxy mints and serves a stable UUID for an HPOS order.
	 */
	public function test_orders_proxy_stamps_valid_uuid_on_hpos_order(): void {
		$order = wc_create_order();
		$this->assertSame( '', $order->get_meta( Pos_Uuid::META_KEY ) );

		Proxy_Uuid_Stamper::register_proxy_stampers();
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_param( 'include', array( $order->get_id() ) );
		try {
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();
		} finally {
			Proxy_Uuid_Stamper::unregister_proxy_stampers();
		}

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 1, $data );
		$this->assertSame( $order->get_id(), (int) $data[0]['id'] );
		$uuid = Pos_Uuid::read_valid_uuid_from_meta( $data[0]['meta_data'] ?? array() );
		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
		$this->assertSame( $uuid, wc_get_order( $order->get_id() )->get_meta( Pos_Uuid::META_KEY ) );
		$this->assert_order_record_existence( $order->get_id(), true, true );
	}

	/**
	 * A trashed HPOS order retaining the UUID is not a live owner.
	 */
	public function test_get_order_ids_by_uuid_ignores_trashed_hpos_order_when_live_owner_exists(): void {
		global $wpdb;

		$uuid          = wp_generate_uuid4();
		$trashed_order = wc_create_order();
		$trashed_order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$trashed_order->save();
		$trashed_id = $trashed_order->get_id();
		$trashed_order->delete( false );

		$live_order = wc_create_order();
		$live_order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$live_order->save();

		$retained_uuid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s",
				$trashed_id,
				Pos_Uuid::META_KEY
			)
		);

		$this->assertSame( $uuid, $retained_uuid, 'The trashed order must retain its UUID for this regression.' );
		$this->assertSame( array( (string) $live_order->get_id() ), Pos_Uuid::get_order_ids_by_uuid( $uuid ) );
	}

	/**
	 * An HPOS refund sharing a UUID does not count as a second order owner.
	 */
	public function test_get_order_ids_by_uuid_ignores_hpos_refund_when_live_order_exists(): void {
		$uuid    = wp_generate_uuid4();
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$order->save();

		$refund = wc_create_refund(
			array(
				'amount'   => '10.00',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertNotWPError( $refund );
		$refund->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$refund->save();

		$this->assertSame( array( (string) $order->get_id() ), Pos_Uuid::get_order_ids_by_uuid( $uuid ) );
	}
}
