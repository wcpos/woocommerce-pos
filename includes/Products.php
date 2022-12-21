<?php

/**
 * POS Product Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_set_stock', array( $this, 'product_set_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'product_set_stock' ) );
		$this->init();
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
		wp_update_post(array(
			'ID'                => $product->id,
			'post_modified'     => $post_modified,
			'post_modified_gmt' => $post_modified_gmt,
		));
	}

	/**
	 * Load Product subclasses.
	 */
	private function init(): void {
		// decimal quantities
		if ( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) ) {
			remove_filter( 'woocommerce_stock_amount', 'intval' );
			add_filter( 'woocommerce_stock_amount', 'floatval' );
		}
	}
}
