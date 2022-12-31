<?php

/**
 * POS Product Admin Class
 * - pos only products.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use const DOING_AUTOSAVE;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION;

class Products {
	/**
	 * @var string
	 */
	private $barcode_field;

	/**
	 * @var array
	 */
	private $options;


	public function __construct() {
		$this->barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field', '' );

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

			// product_variation
			// note: variation HTML fetched via AJAX
			add_action('woocommerce_product_after_variable_attributes', array(
				$this,
				'after_variable_attributes_barcode_field',
			), 10, 3);
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_barcode_field' ) );
		}

		if ( woocommerce_pos_get_settings( 'general', 'pos_only_products' ) ) {
			add_filter( 'views_edit-product', array( $this, 'pos_visibility_filters' ), 10, 1 );
			add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit' ), 10, 2 );
			add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
			add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'manage_product_posts_custom_column', array( $this, 'custom_product_column' ), 10, 2 );
			add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ), 99 );
			add_action('woocommerce_product_after_variable_attributes', array(
				$this,
				'after_variable_attributes_pos_only_products',
			), 10, 3);
			add_action('woocommerce_save_product_variation', array(
				$this,
				'save_product_variation_pos_only_products',
			));
			//          add_filter( 'woocommerce_get_children', array( $this, 'get_children' ) );
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


	public function woocommerce_product_after_variable_attributes(): void {
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
	 * Show/hide POS products.
	 *
	 * @param $where
	 * @param $query
	 *
	 * @return string
	 */
	public function posts_where( $where, $query ) {
		global $wpdb;
		$post_types = \is_array( $query->get( 'post_type' ) ) ? $query->get( 'post_type' ) : array( $query->get( 'post_type' ) );

		// only alter product queries
		if ( ! \in_array( 'product', $post_types, true ) ) {
			return $where;
		}

		// don't alter product queries in the admin
		if ( is_admin() && ! is_pos() ) {
			return $where;
		}

		// hide setting
		$hide = is_pos() ? 'online_only' : 'pos_only';

		$where .= " AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_pos_visibility' AND meta_value = '$hide')";

		return $where;
	}

	/**
	 * @param $column_name
	 * @param $post_type
	 */
	public function bulk_edit( $column_name, $post_type ): void {
		if ( 'name' != $column_name || 'product' != $post_type ) {
			return;
		}
		$options = array_merge(
			array( '-1' => '&mdash; No Change &mdash;' ),
			$this->options
		);
		include 'templates/quick-edit-visibility-select.php';
	}

	/**
	 * @param $column_name
	 * @param $post_type
	 */
	public function quick_edit( $column_name, $post_type ): void {
		if ( 'product_cat' != $column_name || 'product' != $post_type ) {
			return;
		}
		$options = $this->options;
		include 'templates/quick-edit-visibility-select.php';
	}

	/**
	 * @param $post_id
	 * @param $post
	 */
	public function save_post( $post_id, $post ): void {
		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( \defined( '\DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Don't save revisions and autosaves
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Check post type is product
		if ( 'product' != $post->post_type ) {
			return;
		}

		// Check user permission
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check nonces
		if ( ! isset( $_REQUEST['woocommerce_quick_edit_nonce'] ) &&
			 ! isset( $_REQUEST['woocommerce_bulk_edit_nonce'] ) &&
			 ! isset( $_REQUEST['woocommerce_meta_nonce'] ) ) {
			return;
		}
		if ( isset( $_REQUEST['woocommerce_quick_edit_nonce'] ) &&
			 ! wp_verify_nonce( $_REQUEST['woocommerce_quick_edit_nonce'], 'woocommerce_quick_edit_nonce' ) ) {
			return;
		}
		if ( isset( $_REQUEST['woocommerce_bulk_edit_nonce'] ) &&
			 ! wp_verify_nonce( $_REQUEST['woocommerce_bulk_edit_nonce'], 'woocommerce_bulk_edit_nonce' ) ) {
			return;
		}
		if ( isset( $_REQUEST['woocommerce_meta_nonce'] ) &&
			 ! wp_verify_nonce( $_REQUEST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
			return;
		}

		// Get the product and save
		if ( isset( $_REQUEST['_pos_visibility'] ) ) {
			update_post_meta( $post_id, '_pos_visibility', $_REQUEST['_pos_visibility'] );
		}
	}

	/**
	 * @param $hook
	 */
	public function admin_enqueue_scripts( $hook ): void {
		$pages  = array( 'edit.php', 'post.php', 'post-new.php' );
		$screen = get_current_screen();

		if ( ! \in_array( $hook, $pages, true ) || 'product' != $screen->post_type ) {
			return;
		}

		if ( isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'] ) {
			$script = PLUGIN_URL . 'build/js/edit-product.js';
		} else {
			$script = PLUGIN_URL . 'assets/js/edit-product.js';
		}

		wp_enqueue_script(
			PLUGIN_NAME . '-edit-product',
			$script,
			false,
			VERSION,
			true
		);
	}

	/**
	 * @param $column
	 * @param $post_id
	 */
	public function custom_product_column( $column, $post_id ): void {
		if ( 'name' == $column ) {
			$selected = get_post_meta( $post_id, '_pos_visibility', true );
			echo '<div class="hidden" id="woocommerce_pos_inline_' . $post_id . '" data-visibility="' . $selected . '"></div>';
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

	/**
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
