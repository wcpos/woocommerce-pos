<?php
/**
 * Product catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\API\Product_Search;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_REST_Request;

/**
 * Scopes POS product visibility and the POS product sorts to the wc/v3 forward.
 */
final class Products_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Proxy request keys claimed by the product Collection Rules plan.
	 *
	 * `order` is READ but never claimed — wc/v3 needs it forwarded, and the clause
	 * bodies take the direction from the query WooCommerce builds from it.
	 */
	private const PARAM_MAP = array(
		'orderby' => 'orderby',
		'order'   => 'order',
	);

	/**
	 * Whether the forwarded request contains a product search.
	 *
	 * @var bool
	 */
	private $searching = false;

	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var null|Collection_Rules_Plan
	 */
	private $plan;

	/**
	 * Claim the POS sorts and remember search state.
	 *
	 * A POS sort (`sku`, `barcode`, `stock_quantity`, `stock_status`) is CLAIMED, not
	 * forwarded: wc/v3's own `orderby` enum has never heard of them and answers
	 * `rest_invalid_param` (400). The plan strips the claimed key here and the sort is
	 * written back onto the inner query in `install()`.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		// Not empty(): the literal search term "0" is a search too.
		$this->searching = isset( $params['search'] ) && '' !== trim( (string) $params['search'] );
		$this->plan      = Collection_Rules::for_request( 'products', $request, self::PARAM_MAP );

		return $this->plan->forwarded_params( $params );
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		$plan = $this->plan;

		// The stable-sort tiebreak is UNCONDITIONAL: the POS grid defaults to a
		// title sort (mono#1376), and a tied title at a page boundary would
		// otherwise skip or duplicate across the client's multi-page walk.
		// NOT the generic object_query filter: the products controller OVERWRITES
		// orderby AFTER that filter via WC()->query->get_catalog_ordering_args(),
		// so the rewrite must ride that function's own filter — the last word on
		// product catalog ordering.
		$stable_sort = static function ( $args ) {
			return Stable_Sort::with_post_id_tiebreak( (array) $args );
		};
		add_filter( 'woocommerce_get_catalog_ordering_args', $stable_sort );
		$bindings = array( array( 'woocommerce_get_catalog_ordering_args', $stable_sort, 10 ) );

		if ( null !== $plan && $plan->needs_meta_sort() ) {
			/*
			 * A claimed POS sort is written straight into the SQL clauses rather than into
			 * the ordering args: the args form can only express `meta_key` + `meta_value`,
			 * which INNER JOINs postmeta and drops every product that has no value for the
			 * key (#1779 follow-up). `posts_clauses` fires for EVERY WP_Query, so the
			 * binding is scoped to this forward AND guarded by post type — no unrelated
			 * query inside the forward can pick up a product sort.
			 */
			$clauses = static function ( $clauses, $query = null ) use ( $plan ) {
				return self::is_product_query( $query )
					? $plan->filter( Collection_Rules_Plan::HOOK_POSTS_CLAUSES, $clauses, $query )
					: $clauses;
			};
			add_filter( 'posts_clauses', $clauses, 10, 2 );
			$bindings[] = array( 'posts_clauses', $clauses, 10 );
		}

		if ( $this->searching ) {
			$search  = array( Product_Search::class, 'posts_search' );
			$join    = array( Product_Search::class, 'posts_join' );
			$groupby = array( Product_Search::class, 'posts_groupby' );
			add_filter( 'posts_search', $search, 10, 2 );
			add_filter( 'posts_join', $join, 10, 2 );
			add_filter( 'posts_groupby', $groupby, 10, 2 );
			$bindings[] = array( 'posts_search', $search, 10 );
			$bindings[] = array( 'posts_join', $join, 10 );
			$bindings[] = array( 'posts_groupby', $groupby, 10 );
		}

		$visibility = new Pos_Visibility();
		if ( array() === $visibility->hidden_ids( Pos_Visibility::CATALOG ) ) {
			return $bindings;
		}

		$filter = static function ( $args ) use ( $visibility ) {
			return $visibility->apply_to_wp_query_args( (array) $args, 'products' );
		};
		add_filter( 'woocommerce_rest_product_object_query', $filter );
		$bindings[] = array( 'woocommerce_rest_product_object_query', $filter, 10 );

		return $bindings;
	}

	/**
	 * Whether a WP_Query inside the forward is the product query this behavior owns.
	 *
	 * @param mixed $query The WP_Query instance.
	 *
	 * @return bool
	 */
	private static function is_product_query( $query ): bool {
		$post_type = \is_object( $query ) ? ( $query->query_vars['post_type'] ?? null ) : null;

		if ( 'product' === $post_type ) {
			return true;
		}

		return \is_array( $post_type ) && \in_array( 'product', $post_type, true );
	}
}
