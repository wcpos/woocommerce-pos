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
			add_action( 'woocommerce_product_query', array( $this, 'hide_pos_only_products' ) );
			add_filter( 'woocommerce_variation_is_visible', array( $this, 'hide_pos_only_variations' ), 10, 4 );
		}
	}

	/**
	 * Hide POS Only products from the shop and category pages.
	 *
	 * @TODO - this should be improved so that admin users can see the product, but get a message
	 *
	 * @param WP_Query $query Query instance.
	 *
	 * @return void
	 */
	public function hide_pos_only_products( $query ) {
		$meta_query = $query->get( 'meta_query' );

		// Define your default meta query.
		$default_meta_query = array(
			'relation' => 'OR',
			array(
				'key' => '_pos_visibility',
				'value' => 'pos_only',
				'compare' => '!=',
			),
			array(
				'key' => '_pos_visibility',
				'compare' => 'NOT EXISTS',
			),
		);

		// Check if an existing meta query exists.
		if ( is_array( $meta_query ) ) {
			if ( ! isset( $meta_query ['relation'] ) ) {
				$meta_query['relation'] = 'AND';
			}
			$meta_query[] = $default_meta_query;
		} else {
			$meta_query = $default_meta_query;
		}

		// Set the updated meta query back to the query.
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Remove POS Only variations from the storefront.
	 *
	 * @param bool                  $visible Whether the variation is visible.
	 * @param int                   $variation_id The variation ID.
	 * @param int                   $product_id The product ID.
	 * @param \WC_Product_Variation $variation The variation object.
	 */
	public function hide_pos_only_variations( $visible, $variation_id, $product_id, $variation ) {
		if ( \is_shop() || \is_product_category() || \is_product() ) {
			// Get the _pos_visibility meta value for the variation.
			$pos_visibility = get_post_meta( $variation_id, '_pos_visibility', true );

			// Check if _pos_visibility is 'pos_only' for this variation.
			if ( $pos_visibility === 'pos_only' ) {
				return false;
			}
		}

		return $visible;
	}
}
