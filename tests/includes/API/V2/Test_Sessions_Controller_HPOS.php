<?php
/**
 * Session snapshot coverage with HPOS and compatibility sync disabled.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

require_once __DIR__ . '/Test_Sessions_Controller.php';

/** Run the session snapshot contracts against order-table storage. */
class Test_Sessions_Controller_HPOS extends Test_Sessions_Controller {
	use HPOSToggleTrait;

	/** Enable HPOS before creating fixture orders. */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	/**
	 * Restore posts storage.
	 *
	 * The session fixture COMMITs, so orders written with compatibility sync off outlive the
	 * parent rollback while an in-transaction cleanup does not (woocommerce-pos#2298, PHP Tests
	 * shard 1): tear the fixture down first, then drain the synchroniser and hand storage back
	 * in autocommit so the restore is durable.
	 */
	public function tearDown(): void {
		parent::tearDown();
		$this->enable_cot_sync();
		$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		for ( $i = 0; $i < 10 && $sync->get_current_orders_pending_sync_count() > 0; $i++ ) {
			$sync->process_batch( $sync->get_next_batch_to_process( 1000 ) );
		}
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
	}
}
