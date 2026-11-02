<?php
/**
 * Completion history tests under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Re-runs every completion-history case with the custom orders table enabled (the parent class
 * runs under CPT storage only).
 */
class Test_Completion_Stamp_HPOS extends Test_Completion_Stamp {
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
