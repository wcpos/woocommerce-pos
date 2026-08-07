<?php
/**
 * Pure tests for the Collection Rules plan surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Claims, narrowing and passthrough — no database, no dispatch.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Plan extends WP_UnitTestCase {
	/**
	 * The direct lane's narrowing map, mirroring V1\Orders_Controller.
	 *
	 * @var array<string, mixed>
	 */
	private const V1_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'include'     => 'wcpos_include',
		'exclude'     => 'wcpos_exclude',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
	);

	/**
	 * The proxy lane's narrowing map, mirroring V2\Catalog_Proxy_Controller.
	 *
	 * @var array<string, mixed>
	 */
	private const V2_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
		'created_via' => 'created_via',
		'include'     => array(
			'key'   => 'include',
			'when'  => 'search',
			'parse' => 'id_list',
		),
		'exclude'     => array(
			'key'   => 'exclude',
			'when'  => 'search',
			'parse' => 'id_list',
		),
	);

	/**
	 * Build a plan for an arbitrary query.
	 *
	 * @param array  $params    Query params.
	 * @param array  $param_map Narrowing map.
	 * @param string $storage   Storage dialect.
	 *
	 * @return Collection_Rules_Plan
	 */
	private function plan_for( array $params, array $param_map, string $storage = Collection_Rules::STORAGE_HPOS ): Collection_Rules_Plan {
		$request = new WP_REST_Request();
		$request->set_query_params( $params );

		return Collection_Rules::for_request( 'orders', $request, $param_map, $storage );
	}

	/**
	 * The v1 map claims the WCPOS-private id sets, the sort and the POS filters.
	 */
	public function test_v1_param_map_claims_its_own_params(): void {
		$plan = $this->plan_for(
			array(
				'orderby'       => 'total',
				'order'         => 'asc',
				'wcpos_include' => array( 3, 1 ),
				'pos_cashier'   => 7,
				'pos_store'     => 9,
			),
			self::V1_MAP
		);

		$this->assertEquals(
			array(
				'pos_cashier' => 7,
				'pos_store'   => 9,
				'include'     => array( 3, 1 ),
				'orderby'     => 'total',
			),
			$plan->claims()
		);
	}

	/**
	 * `created_via` is invisible to the direct lane because its map omits the name.
	 */
	public function test_v1_param_map_never_claims_created_via(): void {
		$plan = $this->plan_for( array( 'created_via' => 'pos' ), self::V1_MAP );

		$this->assertSame( array(), $plan->claims() );
		$this->assertTrue( $plan->is_empty() );
	}

	/**
	 * The proxy map exposes `created_via`, so the same row is claimed there.
	 */
	public function test_v2_param_map_claims_created_via(): void {
		// Claimed values are run through sanitize_key, which lower-cases and drops
		// anything outside [a-z0-9_-].
		$plan = $this->plan_for( array( 'created_via' => array( 'CheckOut!', 'rest-api' ) ), self::V2_MAP );

		$this->assertEquals( array( 'created_via' => array( 'checkout', 'rest-api' ) ), $plan->claims() );
	}

	/**
	 * Without a search term the proxy leaves `include` to wc/v3's native handling.
	 */
	public function test_include_is_forwarded_when_no_search_is_present(): void {
		$params = array( 'include' => '4,5' );
		$plan   = $this->plan_for( $params, self::V2_MAP );

		$this->assertSame( array(), $plan->claims() );
		$this->assertSame( $params, $plan->forwarded_params( $params ) );
	}

	/**
	 * With a search term the rule takes ownership of the id set instead.
	 */
	public function test_include_is_claimed_when_search_would_clobber_it(): void {
		$params = array(
			'search'  => 'Aurelia',
			'include' => '4,5',
			'exclude' => '6',
		);
		$plan   = $this->plan_for( $params, self::V2_MAP );

		$this->assertEquals(
			array(
				'include' => array( 4, 5 ),
				'exclude' => array( 6 ),
			),
			$plan->claims()
		);
		$this->assertSame( array( 'search' => 'Aurelia' ), $plan->forwarded_params( $params ) );
	}

	/**
	 * A claimed param is stripped from the forward; `order` is read but never claimed.
	 */
	public function test_forwarded_params_strips_claims_and_keeps_order(): void {
		$params = array(
			'orderby'     => 'status',
			'order'       => 'asc',
			'per_page'    => 10,
			'pos_cashier' => 7,
		);
		$plan   = $this->plan_for( $params, self::V2_MAP );

		$this->assertSame(
			array(
				'order'    => 'asc',
				'per_page' => 10,
			),
			$plan->forwarded_params( $params )
		);
	}

	/**
	 * A wc/v3-native sort is left alone so the inner controller keeps handling it.
	 */
	public function test_native_orderby_is_not_claimed(): void {
		$params = array( 'orderby' => 'date' );
		$plan   = $this->plan_for( $params, self::V2_MAP );

		$this->assertNull( $plan->sort() );
		$this->assertSame( $params, $plan->forwarded_params( $params ) );
	}

	/**
	 * An unknown collection yields an empty, entirely inert plan.
	 */
	public function test_unknown_collection_yields_an_empty_plan(): void {
		$request = new WP_REST_Request();
		$request->set_query_params( array( 'orderby' => 'status' ) );
		$plan = Collection_Rules::for_request( 'widgets', $request, self::V2_MAP, Collection_Rules::STORAGE_HPOS );

		$this->assertTrue( $plan->is_empty() );
		$this->assertSame( array(), $plan->claims() );
		$this->assertSame( array( 'a' => 1 ), $plan->filter( Collection_Rules_Plan::HOOK_QUERY_ARGS, array( 'a' => 1 ) ) );
		$this->assertSame( array(), Collection_Rules::orderby_enum( 'widgets' ) );
		$this->assertSame( array(), Collection_Rules::collection_params( 'widgets' ) );
	}

	/**
	 * An empty plan's `around()` installs nothing and simply runs the callable.
	 */
	public function test_empty_plan_around_is_a_passthrough(): void {
		$plan   = $this->plan_for( array(), self::V2_MAP );
		$before = $this->hook_signature();

		$this->assertSame(
			'forwarded',
			$plan->around(
				static function () {
					return 'forwarded';
				}
			)
		);
		$this->assertSame( $before, $this->hook_signature() );
	}

	/**
	 * An unrecognised hook key is reported and passed through unchanged.
	 */
	public function test_unknown_hook_passes_the_value_through(): void {
		$this->setExpectedIncorrectUsage( 'WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan::filter' );
		$plan = $this->plan_for( array( 'orderby' => 'status' ), self::V2_MAP );

		$this->assertSame( 'untouched', $plan->filter( 'not_a_real_hook', 'untouched' ) );
	}

	/**
	 * The sort enum is a projection of the sort rows, in declaration order.
	 */
	public function test_orderby_enum_projects_the_sort_rows(): void {
		$this->assertSame(
			array( 'status', 'customer_id', 'payment_method', 'total' ),
			Collection_Rules::orderby_enum( 'orders' )
		);
	}

	/**
	 * The collection-params projection is value-identical to the literal it replaced.
	 */
	public function test_collection_params_match_the_literal_they_replaced(): void {
		$this->assertEquals(
			array(
				'pos_cashier' => array(
					'description' => __( 'Filter orders by POS cashier.', 'woocommerce-pos' ),
					'type'        => 'integer',
					'required'    => false,
				),
				'pos_store'   => array(
					'description' => __( 'Filter orders by POS store.', 'woocommerce-pos' ),
					'type'        => 'integer',
					'required'    => false,
				),
			),
			Collection_Rules::collection_params( 'orders' )
		);
	}

	/**
	 * Two calls for the same request, map and storage return the identical plan.
	 */
	public function test_plans_are_memoized_per_request(): void {
		$request = new WP_REST_Request();
		$request->set_query_params( array( 'orderby' => 'total' ) );

		$this->assertSame(
			Collection_Rules::for_request( 'orders', $request, self::V1_MAP, Collection_Rules::STORAGE_HPOS ),
			Collection_Rules::for_request( 'orders', $request, self::V1_MAP, Collection_Rules::STORAGE_HPOS )
		);
	}

	/**
	 * A signature of the global filter table, used to detect leaked callbacks.
	 *
	 * @return array<string, int>
	 */
	private function hook_signature(): array {
		$signature = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
			$count = 0;
			foreach ( $callbacks->callbacks as $priority => $entries ) {
				$count += \count( $entries );
			}
			$signature[ $hook ] = $count;
		}

		return $signature;
	}
}
