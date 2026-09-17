<?php
/**
 * Refund catalog proxy behavior.
 *
 * The permission hook lets POS cashiers read refunds only during the forward.
 * The parent hook restores `parent`, discarded by WC_REST_Refunds_Controller::prepare_objects_query,
 * using post_parent__in for posts storage and HPOS's parent_order_id mapping.
 * The sort hook pins the ID tiebreak so tied refund dates have stable pagination.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

/**
 * Applies refund read permissions, parent filtering and stable sorting in scope.
 */
final class Refunds_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/** POS read grant for this collection only.
	 *
	 * @var string
	 */
	protected $permission_collection = 'refunds';

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {

		$parent = static function ( $args, $request ) {
			if ( ! empty( $request['parent'] ) ) {
				$args['post_parent__in'] = array_map( 'absint', (array) $request['parent'] );
			}

			return $args;
		};
		add_filter( 'woocommerce_rest_refunds_prepare_object_query', $parent, 10, 2 );

		$stable_sort = static function ( $args ) {
			return Stable_Sort::with_post_id_tiebreak( (array) $args );
		};
		add_filter( 'woocommerce_rest_shop_order_refund_object_query', $stable_sort );

		return array(
			array( 'woocommerce_rest_refunds_prepare_object_query', $parent, 10 ),
			array( 'woocommerce_rest_shop_order_refund_object_query', $stable_sort, 10 ),
		);
	}
}
