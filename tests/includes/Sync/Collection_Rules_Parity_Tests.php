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
use WCPOS\WooCommercePOS\API\V1\Orders_Controller;

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
		$this->create_sort_fixtures();

		foreach ( array( 'status', 'customer_id', 'payment_method', 'total' ) as $orderby ) {
			foreach ( array( 'asc', 'desc' ) as $order ) {
				$ids = $this->assert_lane_parity(
					array(
						'orderby' => $orderby,
						'order'   => $order,
					)
				);

				$this->assertCount( 3, $ids, "{$orderby} {$order} returned an unexpected number of orders" );
			}
		}
	}

	/**
	 * The `total` sort is unambiguous, so both lanes are pinned to the exact sequence.
	 *
	 * Guards against the two lanes agreeing on the WRONG order.
	 */
	public function test_total_sort_returns_the_expected_sequence_on_both_lanes(): void {
		$orders = $this->create_sort_fixtures();
		$by_low = array( $orders['low']->get_id(), $orders['middle']->get_id(), $orders['high']->get_id() );

		$this->assertEquals(
			$by_low,
			$this->assert_lane_parity(
				array(
					'orderby' => 'total',
					'order'   => 'asc',
				)
			)
		);
		$this->assertEquals(
			array_reverse( $by_low ),
			$this->assert_lane_parity(
				array(
					'orderby' => 'total',
					'order'   => 'desc',
				)
			)
		);
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
	 * The proxy query must not inherit the direct lane's persistent callbacks.
	 */
	public function test_proxy_lane_is_not_contaminated_by_direct_lane_hooks(): void {
		$v1_callback_by_route = array();
		$capture_callbacks     = static function ( array $args, $request ) use ( &$v1_callback_by_route ): array {
			$has_v1_callback = false;
			foreach ( $GLOBALS['wp_filter']['woocommerce_rest_shop_order_object_query']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( \is_array( $function ) && $function[0] instanceof Orders_Controller ) {
						$has_v1_callback = true;
						break 2;
					}
				}
			}

			$v1_callback_by_route[ $request->get_route() ] = $has_v1_callback;

			return $args;
		};

		add_filter( 'woocommerce_rest_shop_order_object_query', $capture_callbacks, 1, 2 );
		try {
			$this->create_sort_fixtures();
			$this->assert_lane_parity(
				array(
					'orderby' => 'total',
					'order'   => 'asc',
				)
			);
		} finally {
			remove_filter( 'woocommerce_rest_shop_order_object_query', $capture_callbacks, 1 );
		}

		$this->assertEquals(
			array(
				'/wcpos/v1/orders' => true,
				'/wc/v3/orders'    => false,
			),
			$v1_callback_by_route
		);
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
		$request = $this->wp_rest_get_request( $route );
		$request->set_query_params( $query );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), "{$route} did not return 200" );

		return array_map( 'intval', wp_list_pluck( (array) $response->get_data(), 'id' ) );
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
