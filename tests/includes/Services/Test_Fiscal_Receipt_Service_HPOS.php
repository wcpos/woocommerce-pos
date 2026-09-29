<?php
/**
 * HPOS coverage for the fiscal receipt service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Services\Fiscal_Receipt_Service;

/**
 * Run the fiscal receipt service tests with orders in the HPOS tables and sync off.
 * The submission status used to live in postmeta, which here is never read back.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Fiscal_Receipt_Service_HPOS extends Test_Fiscal_Receipt_Service {
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
	 * Guard for the inherited lifecycle test: the status lands on the order, not in postmeta.
	 */
	public function test_hpos_submission_status_is_order_meta_not_postmeta(): void {
		// Arrange.
		$order = OrderHelper::create_order();

		// Act.
		( new Fiscal_Receipt_Service() )->set_submission_status( $order->get_id(), 'sent' );

		// Assert.
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assertSame( '', get_post_meta( $order->get_id(), Fiscal_Receipt_Service::META_KEY_SUBMISSION_STATUS, true ) );
		$this->assertSame( 'sent', wc_get_order( $order->get_id() )->get_meta( Fiscal_Receipt_Service::META_KEY_SUBMISSION_STATUS ) );
	}
}
