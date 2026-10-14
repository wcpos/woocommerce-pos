<?php
/**
 * HPOS coverage for the receipt snapshot cleanup.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Run the receipt snapshot cleanup tests with orders in the HPOS tables and sync off.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Snapshot_Cleanup_HPOS extends Test_Receipt_Snapshot_Cleanup {
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
}
