<?php
/**
 * Order journal coalescing, re-run against the HPOS order datastore.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Every inherited test again, with orders in `wc_orders`.
 *
 * The datastore pairing is not decoration here. #1725 shipped a lookup that was
 * only ever tested on the datastore where it was not broken, and the redundant
 * serializer passes this suite pins were measured at the same count — four per
 * create — on CPT and HPOS alike. A guard that only ran on one of them would be
 * making the same mistake in the other direction.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Order_Journal_Coalescing_HPOS extends Test_Order_Journal_Coalescing {
	use HPOSToggleTrait;

	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/**
	 * The datastore toggle actually took.
	 *
	 * Without this, a `setup_cot()` that silently stopped working would leave the
	 * whole class re-running the CPT parent's tests a second time and reporting a
	 * green HPOS pair that never touched `wc_orders` — which is exactly the shape
	 * of the coverage gap #1725 shipped.
	 */
	public function test_the_pair_is_actually_running_under_hpos(): void {
		$this->assertTrue(
			OrderUtil::custom_orders_table_usage_is_enabled(),
			'this class must exercise the custom orders table, not repeat the CPT run'
		);
	}
}
