<?php
/**
 * HPOS tests for sync record identity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_UnitTestCase;

/**
 * HPOS UUID ownership tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid
 */
class Test_Pos_Uuid_HPOS extends WP_UnitTestCase {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS for each test in this class.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
	}

	/**
	 * Restore legacy order storage after each test.
	 */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
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
