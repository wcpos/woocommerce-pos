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
	 * Orders written while compatibility sync was off are "out of sync"; the next test in the
	 * shard that flips the orders-storage option is refused by WooCommerce until they are
	 * synchronised (Test_Proxy_Resource_Behaviors, PHP Tests shard 1 on #2298), so drain the
	 * synchroniser before handing storage back.
	 */
	public function tearDown(): void {
		$this->enable_cot_sync();
		$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		for ( $i = 0; $i < 10 && $sync->get_current_orders_pending_sync_count() > 0; $i++ ) {
			$sync->process_batch( $sync->get_next_batch_to_process( 1000 ) );
		}
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}
}
