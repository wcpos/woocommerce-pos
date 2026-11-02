<?php
/**
 * Push-lane money-model pins under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Re-runs every money-model push pin with the custom orders table enabled, so the server-owned
 * key strip, the lock, the editable gate and the stock deltas are proven on both storage
 * backends (the parent class runs under CPT storage only).
 */
class Test_Rest_Dispatch_Money_Model_HPOS extends Test_Rest_Dispatch_Money_Model {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS after the v2 routes are registered.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/** The storage this class claims to exercise is the one in use. */
	public function test_hpos_storage_is_in_use(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
