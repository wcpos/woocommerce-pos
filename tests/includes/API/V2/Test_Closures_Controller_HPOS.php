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

	/**
	 * Restore posts storage.
	 *
	 * The closure fixture COMMITs, so the orders this class wrote with compatibility sync off
	 * (and the `yes` HPOS flag) outlive the parent rollback; an earlier cleanup ran inside the
	 * transaction and was rolled back with it, leaving seven orders "out of sync" and making the
	 * next shard-mate's storage toggle throw (Test_Proxy_Resource_Behaviors, PHP Tests shard 1 on
	 * #2298). Tear the fixture down first, then drain the synchroniser and hand storage back in
	 * autocommit so the restore is durable.
	 */
	public function tearDown(): void {
		parent::tearDown();
		try {
			$this->enable_cot_sync();
			$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
			for ( $i = 0; $i < 10 && $sync->get_current_orders_pending_sync_count() > 0; $i++ ) {
				$sync->process_batch( $sync->get_next_batch_to_process( 1000 ) );
			}
		} finally {
			// The parent already restored the hook snapshot; drop the sync-option filter the
			// helpers installed after it so later non-HPOS tests read the real option.
			remove_all_filters( 'pre_option_' . \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION );
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}
}
