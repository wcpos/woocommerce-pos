<?php

/**
 * POS Product Admin Class
 * - pos only products.
 * - barcode field.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin\Products;

use function defined;
use function in_array;
use function is_array;
use function WCPOS\WooCommercePOS\Admin\is_pos;
use const DOING_AUTOSAVE;

class Single_Product {
	/**
	 * @var string
	 */
	private $barcode_field;

	/**
	 * @var array
	 */
	private $options;


	/**
	 *
	 */
	public function __construct() {
		$this->barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );

		// visibility options
		$this->options = array(
			''            => __( 'POS & Online', 'woocommerce-pos' ),
			'pos_only'    => __( 'POS Only', 'woocommerce-pos' ),
			'online_only' => __( 'Online Only', 'woocommerce-pos' ),
		);

		if ( $this->barcode_field && '_sku' !== $this->barcode_field ) {
			// product
			add_action( 'woocommerce_product_options_sku', array( $this, 'woocommerce_product_options_sku' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'woocommerce_process_product_meta' ) );
			// variations
			add_action('woocommerce_product_after_variable_attributes', array(
				$this,
				'after_variable_attributes_barcode_field',
			), 10, 3);
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_barcode_field' ) );
		}

		if ( woocommerce_pos_get_settings( 'general', 'pos_only_products' ) ) {
			add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
			add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ), 99 );
			add_action('woocommerce_product_after_variable_attributes', array(
				$this,
				'after_variable_attributes_pos_only_products',
			), 10, 3);
			add_action('woocommerce_save_product_variation', array(
				$this,
				'save_product_variation_pos_only_products',
			));
		}
	}

	public function woocommerce_product_options_sku(): void {
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
	public function woocommerce_process_product_meta( $post_id ): void {
		if ( isset( $_POST[ $this->barcode_field ] ) ) {
			update_post_meta( $post_id, $this->barcode_field, sanitize_text_field( $_POST[ $this->barcode_field ] ) );
		}
	}

	/**
	 * @param $loop
	 * @param $variation_data
	 * @param $variation
	 */
	public function after_variable_attributes_barcode_field( $loop, $variation_data, $variation ): void {
		$value = get_post_meta( $variation->ID, $this->barcode_field, true );
		if ( ! $value ) {
			$value = '';
		}
		include 'templates/variation-metabox-pos-barcode.php';
	}

	/**
	 * @param $variation_id
	 */
	public function save_product_variation_barcode_field( $variation_id ): void {
		if ( isset( $_POST['variable_pos_barcode'][ $variation_id ] ) ) {
			update_post_meta( $variation_id, $this->barcode_field, $_POST['variable_pos_barcode'][ $variation_id ] );
		}
	}

	/**
	 * @param $post_id
	 * @param $post
	 */
	public function save_post( $post_id, $post ): void {
		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( '\DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Don't save revisions and autosaves
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

        // Make sure the current user has permission to edit the post
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

		// Get the product and save
		if ( isset( $_POST['_pos_visibility'] ) ) {
			update_post_meta( $post_id, '_pos_visibility', $_POST['_pos_visibility'] );
		}
	}

	/**
	 * Add visibility option to the Product edit page.
	 */
	public function post_submitbox_misc_actions(): void {
		global $post;

		if ( 'product' != $post->post_type ) {
			return;
		}

		$selected = get_post_meta( $post->ID, '_pos_visibility', true );
		if ( ! $selected ) {
			$selected = '';
			if ( 'add' == get_current_screen()->action ) {
				$selected = apply_filters( 'woocommerce_pos_default_product_visibility', '', $post );
			}
		}

		include 'templates/post-metabox-visibility-select.php';
	}

	/**s
	 * @param $loop
	 * @param $variation_data
	 * @param $variation
	 */
	public function after_variable_attributes_pos_only_products( $loop, $variation_data, $variation ): void {
		$selected = get_post_meta( $variation->ID, '_pos_visibility', true );
		if ( ! $selected ) {
			$selected = '';
		}
		include 'templates/variation-metabox-visibility-select.php';
	}

	/**
	 * @param $variation_id
	 */
	public function save_product_variation_pos_only_products( $variation_id ): void {
		if ( isset( $_POST['variable_pos_visibility'][ $variation_id ] ) ) {
			update_post_meta( $variation_id, '_pos_visibility', $_POST['variable_pos_visibility'][ $variation_id ] );
		}
	}
}
