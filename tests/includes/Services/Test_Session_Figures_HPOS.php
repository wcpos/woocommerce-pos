<?php
/**
 * Session snapshot coverage with HPOS and compatibility sync disabled.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

require_once __DIR__ . '/Test_Session_Figures.php';

/** Run the session snapshot contracts against order-table storage. */
class Test_Session_Figures_HPOS extends Test_Session_Figures {
	use HPOSToggleTrait;

	/** Enable HPOS before creating fixture orders. */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	/** Restore the original storage configuration. */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}
}
