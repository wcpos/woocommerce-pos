<?php
/**
 * WooCommerce REST API Class, ie: /wc/v3/ endpoints.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 *
 */
class WC_API {

	/**
	 *
	 */
	public function __construct() {
		$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		if ( $pos_only_products ) {
			add_filter( 'woocommerce_rest_product_object_query', array( $this, 'hide_pos_only_products' ), 10, 2 );
			add_filter( 'woocommerce_rest_product_variation_object_query', array( $this, 'hide_pos_only_products' ), 10, 2 );
		}
	}

	/**
	 * Filter the query arguments for a request.
	 *
	 * Enables adding extra arguments or setting defaults for a post
	 * collection request.
	 *
	 * @param array           $args    Key value array of query var to query value.
	 * @param WP_REST_Request $request The request used.
	 */
	public function hide_pos_only_products( $args, $request ) {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => '_pos_visibility',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_pos_visibility',
				'value'   => 'pos_only',
				'compare' => '!=',
			),
		);

		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = $meta_query;
		} else {
			$args['meta_query'] = array(
				'relation' => 'AND',
				$args['meta_query'],
				$meta_query,
			);
		}

		return $args;
	}
}
