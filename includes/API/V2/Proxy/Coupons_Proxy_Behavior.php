<?php
/**
 * Coupon catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

/**
 * Relaxes WooCommerce coupon read permission only during the forward.
 */
final class Coupons_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		$filter = static function ( $permission, $context, $object_id, $post_type ) {
			if ( ! $permission && 'shop_coupon' === $post_type && 'read' === $context ) {
				$permission = current_user_can( 'access_woocommerce_pos' );
			}

			return $permission;
		};
		add_filter( 'woocommerce_rest_check_permissions', $filter, 10, 4 );

		// Coupon date/modified sorts tie routinely (imports share timestamps);
		// pin the id tiebreak so multi-page walks stay stable (see Stable_Sort).
		$stable_sort = static function ( $args ) {
			return Stable_Sort::with_post_id_tiebreak( (array) $args );
		};
		add_filter( 'woocommerce_rest_shop_coupon_object_query', $stable_sort );

		return array(
			array( 'woocommerce_rest_check_permissions', $filter, 10 ),
			array( 'woocommerce_rest_shop_coupon_object_query', $stable_sort, 10 ),
		);
	}
}
