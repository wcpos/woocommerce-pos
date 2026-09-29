<?php
/**
 * HPOS coverage for the order post-meta tripwire.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Run the guard self-test with orders in the HPOS tables and sync off. Here an order id
 * resolves to a `shop_order_placehold` post, which the guard must still recognise as an
 * order — that placeholder is exactly what a stray get_post_meta() reads.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Order_Postmeta_Guard_HPOS extends Test_Order_Postmeta_Guard {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS without syncing to the posts tables, before creating orders.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	/**
	 * Restore posts storage.
	 */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/**
	 * Guard for the inherited tests: orders here really are placeholder posts.
	 */
	public function test_hpos_order_id_is_a_placeholder_post(): void {
		// Arrange.
		$order = wc_create_order();

		// Act.
		$type = get_post_type( $order->get_id() );

		// Assert.
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assertSame( 'shop_order_placehold', $type );
	}
}
