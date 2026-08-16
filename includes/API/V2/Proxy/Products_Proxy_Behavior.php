<?php
/**
 * Product catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Sync\Pos_Visibility;

/**
 * Scopes POS product visibility to the wc/v3 forward.
 */
final class Products_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		$visibility = new Pos_Visibility();
		if ( array() === $visibility->hidden_ids( Pos_Visibility::CATALOG ) ) {
			return array();
		}

		$filter = static function ( $args ) use ( $visibility ) {
			return $visibility->apply_to_wp_query_args( (array) $args, 'products' );
		};
		add_filter( 'woocommerce_rest_product_object_query', $filter );

		return array( array( 'woocommerce_rest_product_object_query', $filter, 10 ) );
	}
}
