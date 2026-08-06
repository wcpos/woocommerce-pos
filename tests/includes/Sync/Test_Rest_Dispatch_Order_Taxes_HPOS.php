<?php
/**
 * HPOS coverage for the v2 order-tax dispatch matrix.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Re-run the order-tax dispatch contract with HPOS authoritative.
 */
class Test_Rest_Dispatch_Order_Taxes_HPOS extends Test_Rest_Dispatch_Order_Taxes {
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
	 *
	 * The COT setup runs DDL mid-test (an implicit COMMIT), so the inherited
	 * tax-rate fixtures escape the test transaction and would leak into every
	 * later count-based tax suite — wipe the tax tables explicitly.
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rate_locations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}
}
