<?php
/**
 * HPOS coverage for the received page honouring a gateway's configured POS order status.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Run the settled-status contract against HPOS storage.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Received_Settled_Status_HPOS extends Test_Received_Settled_Status {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS before creating orders.
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
}
