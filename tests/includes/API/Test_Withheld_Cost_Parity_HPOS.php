<?php
/**
 * Withheld-cost write parity with HPOS order storage (roadmap 623).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * Runs every parity case with orders in the custom orders table.
 */
class Test_Withheld_Cost_Parity_HPOS extends Test_Withheld_Cost_Parity {
	use HPOSToggleTrait;

	/** Turn HPOS on before the order fixtures are built. */
	protected function arrange_storage(): void {
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/** Turn HPOS off. */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_all_filters( 'wc_allow_changing_orders_storage_while_sync_is_pending' );
		parent::tearDown();
	}
}
