<?php
/**
 * Tests for V2 catalog proxy order searches under HPOS.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_HPOS_Unit_Test_Case;

/**
 * V2 order-search probes using HPOS.
 */
class Test_Catalog_Proxy_Orders_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use Catalog_Proxy_Order_Payload_Tests;
	use Catalog_Proxy_Order_Search_Tests;
	use HPOSToggleTrait;

	/**
	 * HPOS sorts `payment_method` by the gateway id column.
	 *
	 * @return bool
	 */
	protected function payment_method_sorts_gateway_id(): bool {
		return true;
	}

	/**
	 * Enable sync routes, HPOS, and create HPOS-backed fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		$this->create_order_search_fixtures();
	}

	/**
	 * Disable HPOS after each probe.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}
}
