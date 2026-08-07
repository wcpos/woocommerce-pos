<?php
/**
 * Read Lane parity for order Collection Rules on HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_HPOS_Unit_Test_Case;

/**
 * The v1 <-> v2 parity matrix, HPOS storage.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Parity_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use Collection_Rules_Parity_Tests;
	use HPOSToggleTrait;

	/**
	 * HPOS sorts `payment_method` by the gateway id column.
	 *
	 * @var bool
	 */
	protected $payment_method_sorts_gateway_id = true;

	/**
	 * Enable the sync routes and HPOS.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Disable HPOS after each probe.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}
}
