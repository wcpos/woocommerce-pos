<?php
/**
 * Product catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\API\Product_Search;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_REST_Request;

/**
 * Scopes POS product visibility to the wc/v3 forward.
 */
final class Products_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Whether the forwarded request contains a product search.
	 *
	 * @var bool
	 */
	private $searching = false;

	/**
	 * Remember search state without changing the forwarded parameters.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		// Not empty(): the literal search term "0" is a search too.
		$this->searching = isset( $params['search'] ) && '' !== trim( (string) $params['search'] );

		return $params;
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
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
}
