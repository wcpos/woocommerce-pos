<?php
/**
 * The cart-restart pins under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Runs every Test_Rest_Dispatch_Cart_Restart case with the orders table
 * authoritative, so the date, paid-date and payment-ledger checks cover both
 * storage backends explicitly instead of whichever one the ambient test
 * database happens to use.
 *
 * @internal
 * @coversNothing
 */
class Test_Rest_Dispatch_Cart_Restart_HPOS extends Test_Rest_Dispatch_Cart_Restart {
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
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}
}
