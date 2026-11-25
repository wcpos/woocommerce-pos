<?php
/**
 * The order change stream under HPOS, where order facts come from the custom order
 * tables. Inherits every case from the posts-store class.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Change_Facts
 */
class Test_Order_Change_Stream_HPOS extends Test_Order_Change_Stream {
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
