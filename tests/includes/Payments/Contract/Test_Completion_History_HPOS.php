<?php
/**
 * Completion history read model tests under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

require_once __DIR__ . '/Test_Completion_History.php';

/**
 * Re-runs every read-model case with orders in the HPOS tables and compatibility sync off, so the
 * session lookup reads `wc_orders_meta`.
 */
class Test_Completion_History_HPOS extends Test_Completion_History {
	use HPOSToggleTrait;

	/** Enable HPOS without syncing to the posts tables, before creating orders. */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	/** Restore posts storage. */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/** The storage this class claims to exercise is the one in use. */
	public function test_hpos_storage_is_in_use(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
