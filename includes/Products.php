<?php

/**
 * POS Product Class.
 *
 * @author Paul Kilmurray <paul@kilbot.com>
 *
 * @see    https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_set_stock', array( $this, 'product_set_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'product_set_stock' ) );

		$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		if ( $pos_only_products ) {
			add_action( 'woocommerce_product_query', array( $this, 'hide_pos_only_products' ) );
			add_filter( 'woocommerce_variation_is_visible', array( $this, 'hide_pos_only_variations' ), 10, 4 );
			// add_filter( 'woocommerce_get_product_subcategories_args', array( $this, 'filter_category_count_exclude_pos_only' ) );
		}

		/**
		 * Allow decimal quantities for products and variations.
		 *
		 * @TODO - this will affect the online store aswell, should I make it POS only?
		 */
		$allow_decimal_quantities = woocommerce_pos_get_settings( 'general', 'decimal_qty' );
		if ( \is_bool( $allow_decimal_quantities ) && $allow_decimal_quantities ) {
			remove_filter( 'woocommerce_stock_amount', 'intval' );
			add_filter( 'woocommerce_stock_amount', 'floatval' );
		}
	}

	/**
	 * Bump modified date on stock change
	 * - variation->id = parent id.
	 *
	 * @param $product
	 */
	public function product_set_stock( $product ): void {
		$post_modified     = current_time( 'mysql' );
		$post_modified_gmt = current_time( 'mysql', 1 );
		wp_update_post(
			array(
				'ID'                => $product->get_id(),
				'post_modified'     => $post_modified,
				'post_modified_gmt' => $post_modified_gmt,
			)
		);
	}

	/**
	 * Hide POS Only products from the shop and category pages.
	 *
	 * @TODO - this should be improved so that admin users can see the product, but get a message
	 * @TODO - should I use the 'woocommerce_product_query' action instead? I found it doesn't work correctly
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

	/**
	 * Filter category count to exclude pos_only products.
	 *
	 * @param array $args The arguments for getting product subcategories.
	 *
	 * @return array The modified arguments.
	 */
	public function filter_category_count_exclude_pos_only( $args ) {
		if ( ! is_admin() && \function_exists( 'woocommerce_pos_get_settings' ) ) {
			$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

			if ( $pos_only_products ) {
				$meta_query = $args['meta_query'] ?? array();

				$meta_query['relation'] = 'OR';
				$meta_query[]           = array(
					'key'     => '_pos_visibility',
					'value'   => 'pos_only',
					'compare' => '!=',
				);
				$meta_query[] = array(
					'key'     => '_pos_visibility',
					'compare' => 'NOT EXISTS',
				);

				$args['meta_query'] = $meta_query;
			}
		}

		return $args;
	}
}
