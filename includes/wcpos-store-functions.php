<?php
/**
 * WooCommerce POS Store Functions
 *
 * Functions for store specific things.
 */

defined( 'ABSPATH' ) || exit;

use WCPOS\WooCommercePOS\Abstracts\Store;

/**
 * Standard way of retrieving stores based on certain parameters.
 *
 * This function should be used for store retrieval so that we have a data agnostic
 * way to get a list of stores.
 *
 * @since 1.4.0
 *
 * @param  array $args Array of args.
 * @return array|stdClass Number of pages and an array of product objects if
 *                             paginate is true, or just an array of values.
 */
if ( ! \function_exists( 'wcpos_get_stores' ) ) {
	function wcpos_get_stores( $args = array() ) {
		$store = new Store();
		return apply_filters( 'woocommerce_pos_get_stores', array( $store ), $args );
	}
}

/**
 * Main function for returning store.
 *
 * This function should only be called after 'init' action is finished, as there might be taxonomies that are getting
 * registered during the init action.
 *
 * @since 1.4.0
 *
 * @param mixed $the_store Post object or post ID of the product.
 * @return Store|null|false
 */
if ( ! \function_exists( 'wcpos_get_store' ) ) {
	function wcpos_get_store( $the_store = false ) {
		$store = new Store();
		return apply_filters( 'woocommerce_pos_get_store', $store, $the_store );
	}
}

/**
 * Helper to get the store name by ID.
 *
 * This function should only be called after 'init' action is finished, as there might be taxonomies that are getting
 * registered during the init action.
 *
 * @since 1.4.0
 *
 * @param mixed $the_store Post object or post ID of the product.
 * @return Store|null|false
 */
if ( ! \function_exists( 'wcpos_get_store_name' ) ) {
	function wcpos_get_store_name( $the_store = false ) {
		$store = wcpos_get_store( $the_store );
		return $store->get_name();
	}
}
