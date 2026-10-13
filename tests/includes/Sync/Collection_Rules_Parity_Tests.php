<?php
/**
 * Shared v1 <-> v2 Read Lane parity probes for order Collection Rules.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;

/**
 * The headline test the Collection Rules module exists to make writable: the SAME query,
 * dispatched down both Read Lanes, must come back as the same id sequence.
 *
 * Each consuming class fixes one storage, so the matrix is
 * (4 sorts x 2 directions x 2 storages) plus the POS filters. Before the module, only one
 * lane's behaviour existed in each place, so the two could not be compared at all.
 */
trait Collection_Rules_Parity_Tests {
	/**
	 * Whether this storage sorts `payment_method` by the gateway id column.
	 *
	 * HPOS does (`wc_orders.payment_method`); legacy storage sorts the merchant-visible
	 * `_payment_method_title` meta. See the parity pin in `Sync\Collection_Rules`.
	 *
	 * A method, not a property: PHP rejects a consuming class that redeclares a trait
	 * property with a different default.
	 *
	 * @return bool
	 */
	protected function payment_method_sorts_gateway_id(): bool {
		return false;
	}

	/**
	 * Both lanes return the same id sequence for every sort, in both directions.
	 */
	public function test_every_sort_returns_identical_id_sequences_on_both_lanes(): void {
		$orders = $this->create_sort_fixtures();
		$by_low = array( $orders['low']->get_id(), $orders['middle']->get_id(), $orders['high']->get_id() );

		foreach ( array( 'status', 'customer_id', 'payment_method', 'total' ) as $orderby ) {
			foreach ( array( 'asc', 'desc' ) as $order ) {
				$ids = $this->assert_lane_parity(
					array(
						'orderby' => $orderby,
						'order'   => $order,
					)
				);

				$this->assertCount( 3, $ids, "{$orderby} {$order} returned an unexpected number of orders" );
				if ( 'total' === $orderby ) {
					$this->assertEquals( 'asc' === $order ? $by_low : array_reverse( $by_low ), $ids, 'total_sort_returns_the_expected_sequence_on_both_lanes: ' . $order );
				}
			}
		}
	}

	/**
	 * BEHAVIOUR DELTA (v2 lane): `payment_method` now sorts v1's per-storage column.
	 *
	 * `wcpos/v1` sorts the HPOS `payment_method` COLUMN (the gateway id, e.g. `alpha`) and
	 * the legacy `_payment_method_title` META (the merchant-visible title). The v2 proxy
	 * used to mirror `payment_method_title` on HPOS, so the two lanes disagreed. Slice 1
	 * pins the proxy to v1's behaviour verbatim, which CHANGES the v2 HPOS result. The
	 * fixtures deliberately give each order a gateway id and a title that sort the other
	 * way, so a regression to `payment_method_title` fails here.
	 */
	public function test_payment_method_sort_uses_the_per_storage_column_on_both_lanes(): void {
		$orders = $this->create_sort_fixtures();

		$expected = $this->payment_method_sorts_gateway_id()
			// HPOS: gateway ids are alpha < bravo < charlie.
			? array( $orders['high']->get_id(), $orders['middle']->get_id(), $orders['low']->get_id() )
			// Legacy: titles are Alpha < Bravo < Charlie.
			: array( $orders['low']->get_id(), $orders['middle']->get_id(), $orders['high']->get_id() );

		$this->assertEquals(
			$expected,
			$this->assert_lane_parity(
				array(
					'orderby' => 'payment_method',
					'order'   => 'asc',
				)
			)
		);
	}

	/**
	 * BEHAVIOUR DELTA (v2 lane): the sort direction is derived exactly as v1 derives it.
	 *
	 * The proxy used to compute its own direction from the raw outer query params —
	 * `asc` or, for anything else INCLUDING an absent param, `DESC`. It now takes the
	 * direction from the query WooCommerce built (whose `order` carries wc/v3's own
	 * schema default), with v1's `ASC` as the terminal fallback. That makes a request
	 * with no `order` param resolve identically on both lanes instead of each lane
	 * defaulting on its own.
	 */
	public function test_absent_order_param_resolves_identically_on_both_lanes(): void {
		$orders   = $this->create_sort_fixtures();
		$by_total = array( $orders['low']->get_id(), $orders['middle']->get_id(), $orders['high']->get_id() );

		$this->assertEquals(
			array_reverse( $by_total ),
			$this->assert_lane_parity( array( 'orderby' => 'total' ) )
		);
		$this->assert_lane_parity( array( 'orderby' => 'status' ) );
		$this->assert_lane_parity( array( 'orderby' => 'customer_id' ) );
		$this->assert_lane_parity( array( 'orderby' => 'payment_method' ) );
	}

	/**
	 * The POS cashier filter narrows identically on both lanes.
	 */
	public function test_pos_cashier_filter_matches_on_both_lanes(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$matching = OrderHelper::create_order();
		$matching->update_meta_data( '_pos_user', (string) $cashier_id );
		$matching->save();

		$other = OrderHelper::create_order();
		$other->update_meta_data( '_pos_user', '999999' );
		$other->save();

		$this->assertEquals(
			array( $matching->get_id() ),
			$this->assert_lane_parity( array( 'pos_cashier' => $cashier_id ) )
		);
	}

	/**
	 * The POS store filter narrows identically on both lanes.
	 */
	public function test_pos_store_filter_matches_on_both_lanes(): void {
		$matching = OrderHelper::create_order();
		$matching->update_meta_data( '_pos_store', '314159' );
		$matching->save();

		$other = OrderHelper::create_order();
		$other->update_meta_data( '_pos_store', '271828' );
		$other->save();

		$this->assertEquals(
			array( $matching->get_id() ),
			$this->assert_lane_parity( array( 'pos_store' => 314159 ) )
		);
	}

	/**
	 * Order reads unwind every scoped query callback on both lanes.
	 */
	public function test_order_reads_on_both_lanes_leave_no_query_callbacks_installed(): void {
		// Arrange.
		$orders = $this->create_sort_fixtures();
		$ids    = array( $orders['low']->get_id(), $orders['middle']->get_id(), $orders['high']->get_id() );
		$before = $this->query_hook_counts();

		// Act / Assert: no hook snapshot is restored between dispatches.
		$this->lane_order_ids(
			'/wcpos/v1/orders',
			array(
				'wcpos_include' => $ids,
				'orderby'       => 'status',
				'order'         => 'asc',
			)
		);
		$this->assertSame( $before, $this->query_hook_counts() );
		$this->lane_order_ids(
			'/wcpos/v2/orders',
			array(
				'orderby'     => 'status',
				'order'       => 'asc',
				'pos_cashier' => 123,
			)
		);
		$this->assertSame( $before, $this->query_hook_counts() );
	}

	/**
	 * An order id set must not narrow the nested refund lookup on either lane.
	 */
	public function test_id_set_read_keeps_each_orders_refunds_on_both_lanes(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_billing_last_name( 'RefundLaneProbe' );
		$this->scrub_numeric_address_fields( $order );
		// The helper order carries no total, and a refund must fit inside one.
		$order->set_total( '10.00' );
		$id     = $order->save();
		$refund = wc_create_refund(
			array(
				'order_id' => $id,
				'amount'   => 1,
				'reason'   => 'Refund lane probe',
			)
		);
		$this->assertNotWPError( $refund );
		wp_cache_flush();

		// Act.
		$direct = $this->lane_rows( '/wcpos/v1/orders', array( 'wcpos_include' => array( $id ) ) );
		wp_cache_flush();
		$proxy = $this->lane_rows(
			'/wcpos/v2/orders',
			array(
				'include' => (string) $id,
				'search'  => 'RefundLaneProbe',
			)
		);

		// Assert.
		foreach ( array( $direct, $proxy ) as $rows ) {
			$this->assertCount( 1, $rows );
			$this->assertSame( $id, $rows[0]['id'] );
			$this->assertSame( array( $refund->get_id() ), array_map( 'intval', wp_list_pluck( $rows[0]['refunds'], 'id' ) ) );
		}
	}

	/**
	 * Count query callbacks across every priority, including absent hooks as zero.
	 *
	 * @return array<string, int>
	 */
	private function query_hook_counts(): array {
		$counts = array();
		foreach ( array( 'posts_where', 'posts_orderby', 'posts_clauses', 'woocommerce_orders_table_query_clauses', 'woocommerce_rest_shop_order_object_query' ) as $hook ) {
			$counts[ $hook ] = 0;
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $callbacks ) {
				$counts[ $hook ] += \count( $callbacks );
			}
		}
		return $counts;
	}

	/**
	 * Dispatch the same logical query down both lanes and assert the sequences match.
	 *
	 * @param array $query Query params, shared by both lanes.
	 *
	 * @return int[] The agreed id sequence.
	 */
	private function assert_lane_parity( array $query ): array {
		$query['per_page'] = 100;

		$direct = $this->isolated_lane_order_ids( '/wcpos/v1/orders', $query );
		$proxy  = $this->isolated_lane_order_ids( '/wcpos/v2/orders', $query );

		$this->assertEquals( $direct, $proxy, 'Read Lane divergence for ' . wp_json_encode( $query ) );

		return $direct;
	}

	/**
	 * Dispatch one lane without carrying its persistent hooks into the other lane.
	 *
	 * @param string $route Route to dispatch.
	 * @param array  $query Query params.
	 *
	 * @return int[]
	 */
	private function isolated_lane_order_ids( string $route, array $query ): array {
		$filter_snapshot = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
			$filter_snapshot[ $hook ] = clone $callbacks;
		}

		try {
			return $this->lane_order_ids( $route, $query );
		} finally {
			$GLOBALS['wp_filter'] = $filter_snapshot;
		}
	}

	/**
	 * Dispatch one lane and return its order ids.
	 *
	 * @param string $route Route to dispatch.
	 * @param array  $query Query params.
	 *
	 * @return int[]
	 */
	private function lane_order_ids( string $route, array $query ): array {
		return array_map( 'intval', wp_list_pluck( $this->lane_rows( $route, $query ), 'id' ) );
	}

	/**
	 * Dispatch one lane and return its response rows without restoring hooks.
	 *
	 * @param string $route Route to dispatch.
	 * @param array  $query Query params.
	 * @return array
	 */
	private function lane_rows( string $route, array $query ): array {
		$request = $this->wp_rest_get_request( $route );
		$request->set_query_params( $query );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), "{$route} did not return 200" );

		return $response->get_data();
	}

	/**
	 * Three orders that sort differently under every declared sort.
	 *
	 * @return array<string, WC_Order>
	 */
	private function create_sort_fixtures(): array {
		$customers = array(
			CustomerHelper::create_customer(),
			CustomerHelper::create_customer(),
			CustomerHelper::create_customer(),
		);

		$specs = array(
			// Key => status, total, gateway id, payment title.
			'low'    => array( 'completed', 9.99, 'charlie', 'Alpha' ),
			'middle' => array( 'on-hold', 100.00, 'bravo', 'Bravo' ),
			'high'   => array( 'pending', 1000.00, 'alpha', 'Charlie' ),
		);

		$orders = array();
		$index  = 0;
		foreach ( $specs as $key => $spec ) {
			$order = OrderHelper::create_order(
				array(
					'status'      => $spec[0],
					'total'       => $spec[1],
					'customer_id' => $customers[ $index ]->get_id(),
				)
			);
			$order->set_payment_method( $spec[2] );
			$order->set_payment_method_title( $spec[3] );
			$this->scrub_numeric_address_fields( $order );
			$order->save();

			$orders[ $key ] = $order;
			++$index;
		}

		return $orders;
	}

	/**
	 * Blank the numeric address fields so an id search can never collide with them.
	 *
	 * @param WC_Order $order Order to scrub.
	 */
	private function scrub_numeric_address_fields( $order ): void {
		$order->set_billing_postcode( '' );
		$order->set_billing_phone( '' );
		$order->set_shipping_postcode( '' );
	}
}
