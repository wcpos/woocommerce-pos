<?php
/**
 * POS Product Class.
 *
 * @author Paul Kilmurray <paul@kilbot.com>
 *
 * @see    https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WC_Product;
use Automattic\WooCommerce\StoreApi\Exceptions\NotPurchasableException;

/**
 *
 */
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
			add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_prevent_pos_only_add_to_cart' ) );

			// NOTE: this hook is marked as deprecated.
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'prevent_pos_only_add_to_cart' ), 10, 2 );

			// add_filter( 'woocommerce_get_product_subcategories_args', array( $this, 'filter_category_count_exclude_pos_only' ) );
		}

		/**
		 * Allow decimal quantities for products and variations.
		 *
		 * @TODO - this will affect the online store as well, should I make it POS only?
		 */
		$allow_decimal_quantities = woocommerce_pos_get_settings( 'general', 'decimal_qty' );
		if ( \is_bool( $allow_decimal_quantities ) && $allow_decimal_quantities ) {
			remove_filter( 'woocommerce_stock_amount', 'intval' );
			add_filter( 'woocommerce_stock_amount', 'floatval' );
			add_action( 'woocommerce_before_product_object_save', array( $this, 'save_decimal_quantities' ) );
		}
	}

	/**
	 * Bump modified date on stock change.
	 * - variation->id = parent id.
	 *
	 * @param WC_Product $product
	 */
	public function product_set_stock( WC_Product $product ): void {
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

	/**
	 * Prevent POS Only products from being added to the cart.
	 *
	 * NOTE: this hook is marked as deprecated.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 *
	 * @return bool
	 */
	public function prevent_pos_only_add_to_cart( $passed, $product_id ) {
		$pos_visibility = get_post_meta( $product_id, '_pos_visibility', true );

		if ( $pos_visibility === 'pos_only' ) {
			return false;
		}

		return $passed;
	}

	/**
	 * Prevent POS Only products from being added to the cart via the Store API.
	 *
	 * @throws NotPurchasableException Exception if product is POS Only.

	 * @param WC_Product $product Product.
	 *
	 * @return void
	 */
	public function store_api_prevent_pos_only_add_to_cart( WC_Product $product ) {
		$pos_visibility = get_post_meta( $product->get_id(), '_pos_visibility', true );

		if ( $pos_visibility === 'pos_only' ) {
			throw new NotPurchasableException(
				'woocommerce_pos_product_not_purchasable',
				$product->get_name()
			);
		}
	}

	/**
	 * Save decimal quantities for products and variations.
	 *
	 * @param WC_Product $product Product.
	 */
	public function save_decimal_quantities( WC_Product $product ) {
		if ( ! $product->get_manage_stock() ) {
			$product->set_stock_status( 'instock' );
			return;
		}

		$stock_quantity = $product->get_stock_quantity();
		$stock_notification_threshold = absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) );

		// Adjust the condition to consider stock quantities between 0 and 1 as instock if greater than 0.
		$stock_is_above_notification_threshold = ( $stock_quantity > 0 && $stock_quantity > $stock_notification_threshold );
		$backorders_are_allowed = ( 'no' !== $product->get_backorders() );

		if ( $stock_is_above_notification_threshold ) {
			$new_stock_status = 'instock';
		} elseif ( $backorders_are_allowed ) {
			$new_stock_status = 'onbackorder';
		} else {
			$new_stock_status = 'outofstock';
		}

		$product->set_stock_status( $new_stock_status );
	}
}
