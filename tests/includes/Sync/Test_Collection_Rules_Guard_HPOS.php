<?php
/**
 * Regression pin for the retired HPOS ORDER BY guard.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_HPOS_Unit_Test_Case;

/**
 * `wcpos/v1` used to write its HPOS `ORDER BY` only when WooCommerce had left
 * `$clauses['orderby']` empty. A Collection Rule that claims a sort OWNS the ordering, so
 * that guard is gone — which is safe exactly as long as the condition it tested still
 * holds. This pins it: for all four declared sorts, WooCommerce must still arrive at the
 * clauses filter with an EMPTY `orderby`. If a future WooCommerce release starts mapping
 * one of these names itself, this fails loudly instead of two writers silently fighting.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Guard_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;

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

	/**
	 * WooCommerce still leaves ORDER BY empty for every sort the rules claim.
	 */
	public function test_woocommerce_leaves_orderby_empty_for_every_claimed_sort(): void {
		OrderHelper::create_order();

		foreach ( array( 'status', 'customer_id', 'payment_method', 'total' ) as $orderby ) {
			$observed = null;
			$probe    = static function ( $clauses ) use ( &$observed ) {
				if ( null === $observed ) {
					$observed = $clauses['orderby'] ?? null;
				}

				return $clauses;
			};
			// Priority 9: before the rule (and, on the direct lane, before v1's own
			// callbacks) writes anything.
			add_filter( 'woocommerce_orders_table_query_clauses', $probe, 9, 1 );

			$request = $this->wp_rest_get_request( '/wcpos/v1/orders' );
			$request->set_query_params(
				array(
					'orderby' => $orderby,
					'order'   => 'asc',
				)
			);
			$response = $this->server->dispatch( $request );

			remove_filter( 'woocommerce_orders_table_query_clauses', $probe, 9 );

			$this->assertEquals( 200, $response->get_status(), "v1 orders request failed for {$orderby}" );
			$this->assertSame( '', $observed, "WooCommerce now maps the '{$orderby}' sort itself" );
		}
	}
}
