<?php
/**
 * The online checkout journal shape under HPOS, where the regression behind
 * #1841 was measured. Inherits every case from the posts-store class.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Online_Checkout_Journal_Shape_HPOS extends Test_Online_Checkout_Journal_Shape {
	use HPOSToggleTrait;

	/**
	 * Switch order storage to the custom tables for every inherited case.
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
}
