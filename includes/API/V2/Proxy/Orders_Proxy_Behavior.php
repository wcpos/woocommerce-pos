<?php
/**
 * Order catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\API\Order_Search;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * Applies the order Collection Rules plan and v2 order wire shape.
 */
final class Orders_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Search text removed from the forwarded request.
	 *
	 * @var string
	 */
	private $search = '';

	/**
	 * Proxy request keys claimed by the order Collection Rules plan.
	 */
	private const PARAM_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
		'created_via' => 'created_via',
		'include'     => array(
			'key' => 'include',
			'when' => 'search',
			'parse' => 'id_list',
		),
		'exclude'     => array(
			'key' => 'exclude',
			'when' => 'search',
			'parse' => 'id_list',
		),
	);

	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var null|\WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
	 */
	private $plan;

	/**
	 * Claim the orders params the rules table owns, then ask wc/v3 for full precision.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		$this->plan = Collection_Rules::for_request( 'orders', $request, self::PARAM_MAP );
		$params     = $this->plan->forwarded_params( $params );
		// Claim only a string with at least one term. Anything else (an array from
		// `search[]=`, whitespace only) stays on the forward so wc/v3's own schema
		// validation answers it, as it did before.
		$search = $params['search'] ?? null;
		if ( is_string( $search ) && array() !== Order_Search::terms( $search ) ) {
			$this->search = $search;
			unset( $params['search'] );
		}
		$params['dp'] = '6';

		return $params;
	}

	/**
	 * Install the storage-specific POS order search filter.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		if ( '' === $this->search ) {
			return array();
		}

		$search = $this->search;
		// The class arrived after the declared WooCommerce minimum (5.3); without it the
		// store is on post storage. Same guard as Collection_Rules::detect_storage().
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$filter = static function ( $clauses, $query ) use ( $search ) {
				$clauses['where'] .= ' AND ' . Order_Search::hpos_where( $search, $query );
				return $clauses;
			};
			add_filter( 'woocommerce_orders_table_query_clauses', $filter, 10, 2 );
			return array( array( 'woocommerce_orders_table_query_clauses', $filter, 10 ) );
		}

		$filter = static function ( $where, $query ) use ( $search ) {
			$post_type = is_object( $query ) ? ( $query->query_vars['post_type'] ?? null ) : null;
			if ( 'shop_order' !== $post_type && ( ! is_array( $post_type ) || ! in_array( 'shop_order', $post_type, true ) ) ) {
				return $where;
			}
			return $where . ' AND ' . Order_Search::posts_where( $search );
		};
		add_filter( 'posts_where', $filter, 10, 2 );
		return array( array( 'posts_where', $filter, 10 ) );
	}

	/**
	 * Run the forward inside the Collection Rules plan for this request.
	 *
	 * @param callable $forward Forward operation.
	 *
	 * @return mixed
	 */
	protected function run( callable $forward ) {
		return null === $this->plan ? $forward() : $this->plan->around( $forward );
	}

	/**
	 * Apply the shared v2 order augmentations to every row.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return mixed
	 */
	public function post_process( $data ) {
		$serializer = new Order_Serializer();
		foreach ( (array) $data as $index => $payload ) {
			if ( is_array( $payload ) ) {
				$data[ $index ] = $serializer->document( $payload, Order_Serializer::V2_AUGMENTATIONS );
			}
		}

		return $data;
	}
}
