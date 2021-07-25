<?php

/**
 * POS Product Admin Class
 * - pos only products
 *
 * @package  WCPOS\WooCommercePOS\Admin\Products
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 * @link     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

class Products {

	private $barcode_field;

	public function __construct() {
		$this->barcode_field = woocommerce_pos_get_setting( 'general', 'barcode_field', '' );

		if ( $this->barcode_field && '_sku' !== $this->barcode_field ) {
			// product
			add_action( 'woocommerce_product_options_sku', array( $this, 'woocommerce_product_options_sku' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'woocommerce_process_product_meta' ) );

			// product_variation
			// note: variation HTML fetched via AJAX
			add_action( 'woocommerce_product_after_variable_attributes', array(
				$this,
				'after_variable_attributes',
			), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation' ) );
		}
	}

	/**
	 *
	 */
	public function woocommerce_product_options_sku() {
		woocommerce_wp_text_input(
			array(
				'id'          => $this->barcode_field,
				'label'       => __( 'POS Barcode', 'woocommerce-pos' ),
				'desc_tip'    => 'true',
				'description' => __( 'Product barcode used at the point of sale', 'woocommerce-pos' ),
			)
		);

	}

	/**
	 * @param $post_id
	 */
	public function woocommerce_process_product_meta( $post_id ) {
		if ( isset( $_POST[ $this->barcode_field ] ) ) {
			update_post_meta( $post_id, $this->barcode_field, sanitize_text_field( $_POST[ $this->barcode_field ] ) );
		}
	}

	/**
	 *
	 */
	public function woocommerce_product_after_variable_attributes() {
		woocommerce_wp_text_input(
			array(
				'id'          => $this->barcode_field,
				'label'       => __( 'POS Barcode', 'woocommerce-pos' ),
				'desc_tip'    => 'true',
				'description' => __( 'Product barcode used at the point of sale', 'woocommerce-pos' ),
			)
		);
	}

	/**
	 * @param $loop
	 * @param $variation_data
	 * @param $variation
	 */
	public function after_variable_attributes( $loop, $variation_data, $variation ) {
		$value = get_post_meta( $variation->ID, $this->barcode_field, true );
		if ( ! $value ) {
			$value = '';
		}
		include 'templates/variation-metabox-pos-barcode.php';
	}

	/**
	 * @param $variation_id
	 */
	public function save_product_variation( $variation_id ) {
		if ( isset( $_POST['variable_pos_barcode'][ $variation_id ] ) ) {
			update_post_meta( $variation_id, $this->barcode_field, $_POST['variable_pos_barcode'][ $variation_id ] );
		}
	}

}
