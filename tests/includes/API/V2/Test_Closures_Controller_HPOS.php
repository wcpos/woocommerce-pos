<?php
/**
 * Closure REST tests with HPOS and compatibility sync disabled.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/** Exercise the same closure and rollback contracts on HPOS. */
class Test_Closures_Controller_HPOS extends Test_Closures_Controller {
	use HPOSToggleTrait;

	/** Enable HPOS before creating orders. */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/** Restore posts storage. */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}
}
