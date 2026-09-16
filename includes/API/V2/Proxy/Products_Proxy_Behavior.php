<?php
/**
 * Product catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
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
		'search'  => 'search',
	);

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
	 * written back onto the inner query by the plan.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		$plan_request = clone $request;
		$plan_request->set_param( 'search', $params['search'] ?? null );
		$this->plan = Collection_Rules::for_request( 'products', $plan_request, self::PARAM_MAP );

		return $this->plan->forwarded_params( $params );
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

		return $bindings;
	}

	/**
	 * Run the collection rules around the WooCommerce forward.
	 *
	 * @param callable $forward Forward operation.
	 * @return mixed
	 */
	protected function run( callable $forward ) {
		$plan = $this->plan ?? Collection_Rules::for_request( 'products', new WP_REST_Request( 'GET', '/wcpos/v2/products' ) );
		return $plan->around( $forward );
	}
}
