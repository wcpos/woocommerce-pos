<?php
/**
 * HPOS coverage for the v2 order-tax dispatch matrix.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Re-run the order-tax dispatch contract with HPOS authoritative.
 */
class Test_Rest_Dispatch_Order_Taxes_HPOS extends Test_Rest_Dispatch_Order_Taxes {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS after the inherited REST setup.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage after each inherited test.
	 *
	 * No explicit fixture cleanup: `setup_cot()` is DDL-free, so the WP test-case
	 * transaction is intact and the inherited tax-rate fixtures roll back normally.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}
}
