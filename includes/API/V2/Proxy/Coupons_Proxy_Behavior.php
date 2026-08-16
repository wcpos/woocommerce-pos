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

		return array( array( 'woocommerce_rest_check_permissions', $filter, 10 ) );
	}
}
