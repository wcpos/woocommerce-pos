<?php
/**
 * Regression pin for the retired HPOS ORDER BY guard.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_HPOS_Unit_Test_Case;

/**
 * Which of the four declared sorts WooCommerce maps for itself, and which it leaves to us.
 *
 * The HPOS sort rule writes `ORDER BY` only when WooCommerce left `$clauses['orderby']`
 * empty. That guard is load-bearing: `OrdersTableQuery::sanitize_order_orderby()` has a
 * mapping table that already covers `total`, so writing unconditionally would overwrite a
 * correct WooCommerce clause. This test pins the split. If a future WooCommerce release
 * adds `status`, `customer_id` or `payment_method` to that table — or drops `total` from
 * it — ownership silently changes hands, and this fails loudly instead.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Guard_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable the sync routes and HPOS.
	 */
	public function setUp(): void {
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
	}

	/**
	 * The ownership split between WooCommerce's own mapping and the Collection Rule.
	 */
	public function test_woocommerce_owns_only_the_sorts_in_its_mapping_table(): void {
		OrderHelper::create_order();

		$expected = array(
			// WooCommerce has no mapping for these, so they reach the rule empty.
			'status'         => '',
			'customer_id'    => '',
			'payment_method' => '',
			// WooCommerce maps this one itself; the rule must defer to it.
			'total'          => 'total_amount ASC',
		);

		foreach ( $expected as $orderby => $expected_clause ) {
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
			$this->assertIsString( $observed, "the clauses filter never fired for {$orderby}" );

			if ( '' === $expected_clause ) {
				$this->assertSame( '', $observed, "WooCommerce now maps the '{$orderby}' sort itself" );
			} else {
				$this->assertStringEndsWith(
					$expected_clause,
					$observed,
					"WooCommerce no longer maps the '{$orderby}' sort itself"
				);
			}
		}
	}

	/**
	 * The rule leaves a WooCommerce-owned ORDER BY exactly as WooCommerce wrote it.
	 */
	public function test_rule_defers_to_the_woocommerce_owned_total_sort(): void {
		OrderHelper::create_order();

		$before = null;
		$after  = null;
		$probe  = static function ( $clauses ) use ( &$before ) {
			if ( null === $before ) {
				$before = $clauses['orderby'] ?? null;
			}

			return $clauses;
		};
		$check  = static function ( $clauses ) use ( &$after ) {
			if ( null === $after ) {
				$after = $clauses['orderby'] ?? null;
			}

			return $clauses;
		};
		add_filter( 'woocommerce_orders_table_query_clauses', $probe, 9, 1 );
		add_filter( 'woocommerce_orders_table_query_clauses', $check, 11, 1 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_query_params(
			array(
				'orderby' => 'total',
				'order'   => 'asc',
			)
		);
		$response = $this->server->dispatch( $request );

		remove_filter( 'woocommerce_orders_table_query_clauses', $probe, 9 );
		remove_filter( 'woocommerce_orders_table_query_clauses', $check, 11 );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( $before, $after, 'the rule overwrote a WooCommerce-owned ORDER BY' );
	}
}
