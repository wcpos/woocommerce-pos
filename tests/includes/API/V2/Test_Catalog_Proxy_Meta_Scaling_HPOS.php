<?php
/**
 * Meta-heavy scaling coverage for the v2 read surface under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Re-runs every meta-scaling pin with the custom orders table enabled, so the
 * HPOS branch of the order-meta fixtures and the HPOS read path are exercised
 * (the parent class runs under CPT storage only).
 */
class Test_Catalog_Proxy_Meta_Scaling_HPOS extends Test_Catalog_Proxy_Meta_Scaling {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS after the v2 routes are registered.
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

	/**
	 * The inherited pins are only meaningful if HPOS is genuinely active.
	 */
	public function test_hpos_storage_is_active(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
