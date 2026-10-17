<?php
/**
 * Session snapshot coverage with HPOS and compatibility sync disabled.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

require_once __DIR__ . '/Test_Session_Figures.php';

/** Run the session snapshot contracts against order-table storage. */
class Test_Session_Figures_HPOS extends Test_Session_Figures {
	use HPOSToggleTrait;

	/** Teardown must discard committed HPOS fixtures, not copy them into posts. */
	public function test_teardown_discards_committed_orders_without_sync(): void {
		global $wpdb;
		$order = new \WC_Order();
		$order->save();
		$wpdb->query( 'COMMIT' );
		$this->tearDown();
		try {
			$this->assertSame( array(), wc_get_orders( array( 'include' => array( $order->get_id() ) ) ) );
			$this->assertSame( 0, wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->get_current_orders_pending_sync_count() );
		} finally {
			$this->setUp();
		}
	}

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
	 * shard 1): tear the fixture down first, then delete committed orders and hand storage back
	 * in autocommit so the restore is durable.
	 */
	public function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		try {
			foreach ( array(
				OrdersTableDataStore::get_orders_table_name(),
				OrdersTableDataStore::get_addresses_table_name(),
				OrdersTableDataStore::get_operational_data_table_name(),
				OrdersTableDataStore::get_meta_table_name(),
			) as $table ) {
				$wpdb->query( "DELETE FROM {$table}" );
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
