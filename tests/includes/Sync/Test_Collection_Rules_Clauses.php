<?php
/**
 * Clause goldens for the Collection Rules storage dialects.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Every sort on both storages, the id sets, and the search-clobber ownership rule.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Clauses extends WP_UnitTestCase {
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
	 * @param string $storage   Storage dialect.
	 * @param array  $param_map Narrowing map.
	 *
	 * @return Collection_Rules_Plan
	 */
	private function plan_for( array $params, string $storage, array $param_map = self::V1_MAP ): Collection_Rules_Plan {
		$request = new WP_REST_Request();
		$request->set_query_params( $params );

		return Collection_Rules::for_request( 'orders', $request, $param_map, $storage );
	}

	/**
	 * Every HPOS sort writes its own `wc_orders` column.
	 *
	 * `payment_method` is the parity pin: HPOS sorts the gateway id column, NOT
	 * `payment_method_title`. This is `wcpos/v1`'s frozen behaviour and the proxy lane
	 * now shares it.
	 */
	public function test_hpos_sort_columns_are_declared_per_sort(): void {
		$expected = array(
			'status'         => 'stub_wc_orders.status DESC',
			'customer_id'    => 'stub_wc_orders.customer_id DESC',
			'payment_method' => 'stub_wc_orders.payment_method DESC',
			'total'          => 'stub_wc_orders.total_amount DESC',
		);

		foreach ( $expected as $orderby => $clause ) {
			$plan    = $this->plan_for( array( 'orderby' => $orderby ), Collection_Rules::STORAGE_HPOS );
			$clauses = $plan->filter(
				Collection_Rules_Plan::HOOK_HPOS_ORDERBY,
				array( 'orderby' => '' ),
				new Collection_Rules_Query_Stub(),
				array( 'order' => 'DESC' )
			);

			$this->assertSame( $clause, $clauses['orderby'], "HPOS sort clause for {$orderby}" );
		}
	}

	/**
	 * The HPOS sort direction comes from the query args WooCommerce built.
	 */
	public function test_hpos_sort_direction_comes_from_the_query_args(): void {
		$plan    = $this->plan_for( array( 'orderby' => 'total' ), Collection_Rules::STORAGE_HPOS );
		$clauses = $plan->filter(
			Collection_Rules_Plan::HOOK_HPOS_ORDERBY,
			array( 'orderby' => '' ),
			new Collection_Rules_Query_Stub(),
			array( 'order' => 'ASC' )
		);

		$this->assertSame( 'stub_wc_orders.total_amount ASC', $clauses['orderby'] );
	}

	/**
	 * The rule defers when WooCommerce already mapped the sort the client asked for.
	 */
	public function test_hpos_sort_defers_to_a_woocommerce_clause_for_the_same_sort(): void {
		$plan    = $this->plan_for( array( 'orderby' => 'total' ), Collection_Rules::STORAGE_HPOS );
		$clauses = $plan->filter(
			Collection_Rules_Plan::HOOK_HPOS_ORDERBY,
			array( 'orderby' => 'wc_orders.total_amount ASC' ),
			new Collection_Rules_Query_Stub(),
			array(
				'orderby' => 'total',
				'order'   => 'asc',
			)
		);

		$this->assertSame( 'wc_orders.total_amount ASC', $clauses['orderby'] );
	}

	/**
	 * The rule takes over when WooCommerce's clause is for a DIFFERENT sort.
	 *
	 * This is the proxy lane: the claimed `orderby` is stripped before the forward, so
	 * wc/v3 maps its own default and the non-empty clause is not the client's sort.
	 */
	public function test_hpos_sort_overrides_a_woocommerce_clause_for_another_sort(): void {
		$plan    = $this->plan_for( array( 'orderby' => 'total' ), Collection_Rules::STORAGE_HPOS );
		$clauses = $plan->filter(
			Collection_Rules_Plan::HOOK_HPOS_ORDERBY,
			array( 'orderby' => 'wc_orders.date_created_gmt asc' ),
			new Collection_Rules_Query_Stub(),
			array(
				'orderby' => 'date',
				'order'   => 'asc',
			)
		);

		$this->assertSame( 'stub_wc_orders.total_amount asc', $clauses['orderby'] );
	}

	/**
	 * Every legacy sort maps onto the meta key `wcpos/v1` has always used.
	 *
	 * `payment_method` is the other half of the parity pin: legacy sorts the
	 * merchant-visible `_payment_method_title` meta.
	 */
	public function test_legacy_sort_args_are_declared_per_sort(): void {
		$expected = array(
			'customer_id'    => array( '_customer_user', 'meta_value_num' ),
			'payment_method' => array( '_payment_method_title', 'meta_value' ),
			'total'          => array( '_order_total', 'meta_value_num' ),
		);

		foreach ( $expected as $orderby => $pair ) {
			$plan = $this->plan_for( array( 'orderby' => $orderby ), Collection_Rules::STORAGE_POSTS );
			$args = $plan->filter( Collection_Rules_Plan::HOOK_PREPARE_ARGS, array() );

			$this->assertSame( $pair[0], $args['meta_key'], "legacy meta key for {$orderby}" );
			$this->assertSame( $pair[1], $args['orderby'], "legacy orderby for {$orderby}" );
		}
	}

	/**
	 * The legacy status sort is rewritten through the ORDER BY clause.
	 */
	public function test_legacy_status_sort_rewrites_the_orderby_clause(): void {
		global $wpdb;

		$plan = $this->plan_for(
			array(
				'orderby' => 'status',
				'order'   => 'desc',
			),
			Collection_Rules::STORAGE_POSTS
		);

		$this->assertSame(
			"{$wpdb->posts}.post_status DESC",
			$plan->filter( Collection_Rules_Plan::HOOK_POSTS_ORDERBY, 'wp_posts.post_date DESC', new Collection_Rules_Query_Stub() )
		);
		// The status sort has no legacy meta-key encoding, so it contributes no sort args.
		$this->assertSame( array(), $plan->filter( Collection_Rules_Plan::HOOK_PREPARE_ARGS, array() ) );
	}

	/**
	 * The legacy sort direction prefers the direction WooCommerce put on the query.
	 *
	 * Both Read Lanes therefore resolve it the same way; the request param is only a
	 * fallback for a query that carries no direction at all.
	 */
	public function test_legacy_sort_direction_rejects_values_outside_asc_desc(): void {
		global $wpdb;

		// The raw request `order` param reaches SQL text on the legacy fallback path;
		// anything but the two legal directions must render as v1's ASC fallback.
		$plan = $this->plan_for(
			array(
				'orderby' => 'status',
				'order'   => 'DESC, (SELECT 1)',
			),
			Collection_Rules::STORAGE_POSTS
		);

		$this->assertSame(
			"{$wpdb->posts}.post_status ASC",
			$plan->filter(
				Collection_Rules_Plan::HOOK_POSTS_ORDERBY,
				'wp_posts.post_date DESC',
				new Collection_Rules_Query_Stub( array( 'post_type' => 'shop_order' ) )
			)
		);
	}

	public function test_legacy_include_where_binding_matches_array_form_post_type(): void {
		// wc_get_order_types() / explicit `type` args can populate post_type as an
		// array; the installed posts_where binding must treat array( 'shop_order' )
		// like 'shop_order' or the proxy lane silently drops the id-set clause v1
		// still applies. The guard lives on the BINDING, so exercise it via around().
		$plan = $this->plan_for(
			array( 'wcpos_include' => '11,12' ),
			Collection_Rules::STORAGE_POSTS
		);

		$captured = array();
		$plan->around(
			function () use ( &$captured ): void {
				$captured['string'] = apply_filters(
					'posts_where',
					' AND 1=1',
					new Collection_Rules_Query_Stub( array( 'post_type' => 'shop_order' ) )
				);
				$captured['array'] = apply_filters(
					'posts_where',
					' AND 1=1',
					new Collection_Rules_Query_Stub( array( 'post_type' => array( 'shop_order' ) ) )
				);
				$captured['other'] = apply_filters(
					'posts_where',
					' AND 1=1',
					new Collection_Rules_Query_Stub( array( 'post_type' => 'product' ) )
				);
			}
		);

		$this->assertSame( $captured['string'], $captured['array'] );
		$this->assertNotSame( ' AND 1=1', $captured['array'] );
		$this->assertSame( ' AND 1=1', $captured['other'] );
	}

	public function test_legacy_status_sort_direction_prefers_the_query_vars(): void {
		global $wpdb;

		$plan = $this->plan_for(
			array(
				'orderby' => 'status',
				'order'   => 'desc',
			),
			Collection_Rules::STORAGE_POSTS
		);

		$this->assertSame(
			"{$wpdb->posts}.post_status ASC",
			$plan->filter(
				Collection_Rules_Plan::HOOK_POSTS_ORDERBY,
				'wp_posts.post_date DESC',
				new Collection_Rules_Query_Stub(
					array(
						'post_type' => 'shop_order',
						'order'     => 'ASC',
					)
				)
			)
		);
	}

	/**
	 * A query for another post type is left alone.
	 */
	public function test_legacy_status_sort_ignores_other_post_types(): void {
		$plan = $this->plan_for( array( 'orderby' => 'status' ), Collection_Rules::STORAGE_POSTS );

		$this->assertSame(
			'wp_posts.post_date DESC',
			$plan->filter(
				Collection_Rules_Plan::HOOK_POSTS_ORDERBY,
				'wp_posts.post_date DESC',
				new Collection_Rules_Query_Stub( array( 'post_type' => 'product' ) )
			)
		);
	}

	/**
	 * HPOS id sets become raw `IN` / `NOT IN` predicates, include first.
	 */
	public function test_hpos_id_sets_append_in_and_not_in_predicates(): void {
		$plan = $this->plan_for(
			array(
				'wcpos_include' => array( 3, 1 ),
				'wcpos_exclude' => array( 9 ),
			),
			Collection_Rules::STORAGE_HPOS
		);

		$clauses = $plan->filter( Collection_Rules_Plan::HOOK_HPOS_FILTERS, array( 'where' => ' AND 1=1' ), new Collection_Rules_Query_Stub() );

		$this->assertSame(
			' AND 1=1 AND stub_wc_orders.id IN (3,1) AND stub_wc_orders.id NOT IN (9)',
			$clauses['where']
		);
	}

	/**
	 * Legacy id sets are prepared with `%d` placeholders.
	 */
	public function test_legacy_id_sets_append_prepared_predicates(): void {
		global $wpdb;

		$plan = $this->plan_for(
			array(
				'wcpos_include' => array( 3, 1 ),
				'wcpos_exclude' => array( 9 ),
			),
			Collection_Rules::STORAGE_POSTS
		);

		$this->assertSame(
			" AND 1=1 AND {$wpdb->posts}.ID IN (3,1)  AND {$wpdb->posts}.ID NOT IN (9) ",
			$plan->filter( Collection_Rules_Plan::HOOK_POSTS_WHERE, ' AND 1=1', new Collection_Rules_Query_Stub() )
		);
	}

	/**
	 * An empty id set is claimed but contributes nothing.
	 */
	public function test_empty_id_set_contributes_no_clause(): void {
		$plan    = $this->plan_for( array( 'wcpos_include' => '' ), Collection_Rules::STORAGE_HPOS );
		$clauses = $plan->filter( Collection_Rules_Plan::HOOK_HPOS_FILTERS, array( 'where' => '' ), new Collection_Rules_Query_Stub() );

		$this->assertSame( '', $clauses['where'] );
	}

	/**
	 * The POS filters are meta rows on both storages, in declaration order.
	 */
	public function test_pos_filters_become_meta_query_rows(): void {
		foreach ( array( Collection_Rules::STORAGE_HPOS, Collection_Rules::STORAGE_POSTS ) as $storage ) {
			$plan = $this->plan_for(
				array(
					'pos_cashier' => 7,
					'pos_store'   => 9,
				),
				$storage
			);

			$this->assertEquals(
				array(
					'meta_query' => array(
						array(
							'key'   => '_pos_user',
							'value' => 7,
						),
						array(
							'key'   => '_pos_store',
							'value' => 9,
						),
					),
				),
				$plan->filter( Collection_Rules_Plan::HOOK_QUERY_ARGS, array() ),
				"meta rows on {$storage}"
			);
		}
	}

	/**
	 * `created_via` is an operational-data subquery under HPOS and a meta row under legacy.
	 */
	public function test_created_via_uses_a_different_encoding_per_storage(): void {
		$hpos    = $this->plan_for( array( 'created_via' => array( 'checkout', 'rest-api' ) ), Collection_Rules::STORAGE_HPOS, self::V2_MAP );
		$clauses = $hpos->filter( Collection_Rules_Plan::HOOK_HPOS_FILTERS, array( 'where' => '' ), new Collection_Rules_Query_Stub() );

		$this->assertSame(
			" AND stub_wc_orders.id IN (SELECT order_id FROM stub_wc_order_operational_data WHERE created_via IN ('checkout', 'rest-api'))",
			$clauses['where']
		);
		// Under HPOS the row must NOT also land in meta_query.
		$this->assertSame( array(), $hpos->filter( Collection_Rules_Plan::HOOK_QUERY_ARGS, array() ) );

		$legacy = $this->plan_for( array( 'created_via' => array( 'checkout' ) ), Collection_Rules::STORAGE_POSTS, self::V2_MAP );

		$this->assertEquals(
			array(
				'meta_query' => array(
					array(
						'key'   => '_created_via',
						'value' => array( 'checkout' ),
					),
				),
			),
			$legacy->filter( Collection_Rules_Plan::HOOK_QUERY_ARGS, array() )
		);
	}

	/**
	 * Search-clobber ownership: without a search term the rule contributes no id clause.
	 */
	public function test_search_clobber_rule_only_owns_id_sets_alongside_search(): void {
		$without = $this->plan_for( array( 'include' => '3,1' ), Collection_Rules::STORAGE_HPOS, self::V2_MAP );
		$clauses = $without->filter( Collection_Rules_Plan::HOOK_HPOS_FILTERS, array( 'where' => '' ), new Collection_Rules_Query_Stub() );
		$this->assertSame( '', $clauses['where'] );

		$with    = $this->plan_for(
			array(
				'search'  => 'Aurelia',
				'include' => '3,1',
			),
			Collection_Rules::STORAGE_HPOS,
			self::V2_MAP
		);
		$clauses = $with->filter( Collection_Rules_Plan::HOOK_HPOS_FILTERS, array( 'where' => '' ), new Collection_Rules_Query_Stub() );

		// The proxy map parses id lists, so a comma-joined string keeps both ids.
		$this->assertSame( ' AND stub_wc_orders.id IN (3,1)', $clauses['where'] );
	}

	/**
	 * Storage gating: a clause body for the other storage is a passthrough.
	 */
	public function test_clause_bodies_are_gated_on_the_resolved_storage(): void {
		$hpos = $this->plan_for( array( 'orderby' => 'status' ), Collection_Rules::STORAGE_HPOS );
		$this->assertSame( 'untouched', $hpos->filter( Collection_Rules_Plan::HOOK_POSTS_ORDERBY, 'untouched', new Collection_Rules_Query_Stub() ) );

		$posts   = $this->plan_for( array( 'orderby' => 'status' ), Collection_Rules::STORAGE_POSTS );
		$clauses = $posts->filter( Collection_Rules_Plan::HOOK_HPOS_ORDERBY, array( 'orderby' => '' ), new Collection_Rules_Query_Stub(), array( 'order' => 'DESC' ) );
		$this->assertSame( '', $clauses['orderby'] );
	}
}
