<?php
/**
 * POS Product Admin Class
 * - pos only products.
 * - barcode field.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin\Products;

use WCPOS\WooCommercePOS\Registry;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Post;

/**
 * Single_Product class for POS product admin functionality.
 */
class Single_Product {
	/**
	 * The barcode field key.
	 *
	 * @var string
	 */
	private $barcode_field;

	/**
	 * Visibility options for POS products.
	 *
	 * @phpstan-ignore-next-line
	 * @var array
	 */
	private $options;

	/**
	 * Link to upgrade to Pro.
	 *
	 * @var string
	 */
	private $pro_link = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		Registry::get_instance()->set( static::class, $this );

		$this->barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$this->pro_link      = '<a href="https://wcpos.com/pro">' . __( 'Upgrade to Pro', 'woocommerce-pos' ) . '</a>.';

		// visibility options.
		$this->options = array(
			''            => __( 'POS & Online', 'woocommerce-pos' ),
			'pos_only'    => __( 'POS Only', 'woocommerce-pos' ),
			'online_only' => __( 'Online Only', 'woocommerce-pos' ),
		);

		if ( $this->barcode_field && ! \in_array( $this->barcode_field, $this->get_excluded_barcode_fields(), true ) ) {
			add_action( 'woocommerce_product_options_sku', array( $this, 'woocommerce_product_options_sku' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'woocommerce_process_product_meta' ) );
			add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'after_variable_attributes_barcode_field' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_barcode_field' ) );
		}

		if ( woocommerce_pos_get_settings( 'general', 'pos_only_products' ) ) {
			add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
			add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ), 99 );
			add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'after_variable_attributes_pos_only_products' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_pos_only_products' ) );
		}

		add_action( 'woocommerce_product_options_pricing', array( $this, 'add_store_price_fields' ) );
		add_action( 'woocommerce_product_options_tax', array( $this, 'add_store_tax_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_variations_store_price_fields' ), 10, 3 );
		add_action( 'woocommerce_variation_options_tax', array( $this, 'add_variations_store_tax_fields' ), 10, 3 );
	}

	/**
	 * Show barcode input.
	 */
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
	 * Add store price fields to the product edit page.
	 */
	public function add_store_price_fields(): void {
		woocommerce_wp_checkbox(
			array(
				'id'                => '',
				'label'             => '',
				'value'             => true,
				'cbvalue'           => false,
				'description'       => __( 'Enable POS specific prices.', 'woocommerce-pos' ) . ' ' . $this->pro_link,
				'custom_attributes' => array( 'disabled' => 'disabled' ),
			)
		);
	}

	/**
	 * Add store tax fields to the product edit page.
	 */
	public function add_store_tax_fields(): void {
		$link = '<a href="https://wcpos.com/pro">' . __( 'Upgrade to Pro', 'woocommerce-pos' ) . '</a>.';

		woocommerce_wp_checkbox(
			array(
				'id'                => '',
				'label'             => '',
				'value'             => true,
				'cbvalue'           => false,
				'description'       => __( 'Enable POS specific taxes.', 'woocommerce-pos' ) . ' ' . $this->pro_link,
				'custom_attributes' => array( 'disabled' => 'disabled' ),
			)
		);
	}

	/**
	 * Save barcode field on product meta.
	 *
	 * @param int $post_id The post ID.
	 */
	public function woocommerce_process_product_meta( $post_id ): void {
		if ( isset( $_POST[ $this->barcode_field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
			update_post_meta( $post_id, $this->barcode_field, sanitize_text_field( wp_unslash( $_POST[ $this->barcode_field ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
		}
	}

	/**
	 * Add barcode field to variable product attributes.
	 *
	 * @param int     $loop           Position in the loop.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Post data.
	 */
	public function after_variable_attributes_barcode_field( $loop, $variation_data, $variation ): void {
		$value = get_post_meta( $variation->ID, $this->barcode_field, true );
		if ( ! $value ) {
			$value = '';
		}
		include 'templates/variation-metabox-pos-barcode.php';
	}

	/**
	 * Save barcode field for a product variation.
	 *
	 * @param int $variation_id The variation ID.
	 */
	public function save_product_variation_barcode_field( $variation_id ): void {
		if ( isset( $_POST['variable_pos_barcode'][ $variation_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
			update_post_meta( $variation_id, $this->barcode_field, sanitize_text_field( wp_unslash( $_POST['variable_pos_barcode'][ $variation_id ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
		}
	}

	/**
	 * Add store price fields to the variation edit page.
	 *
	 * @param int     $loop           Position in the loop.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Post data.
	 */
	public function add_variations_store_price_fields( $loop, $variation_data, $variation ): void {
		echo '<p class="form-row form-row-full"><label>';
		echo esc_html__( 'Enable POS specific prices.', 'woocommerce-pos' ) . ' ' . wp_kses_post( $this->pro_link );
		echo '<input style="vertical-align:middle;margin:0 5px 0 0 !important;" type="checkbox" class="checkbox" disabled />';
		echo '</label></p>';
	}

	/**
	 * Add store tax fields to the variation edit page.
	 *
	 * @param int     $loop           Position in the loop.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Post data.
	 */
	public function add_variations_store_tax_fields( $loop, $variation_data, $variation ): void {
		echo '<p class="form-row form-row-full"><label>';
		echo esc_html__( 'Enable POS specific taxes.', 'woocommerce-pos' ) . ' ' . wp_kses_post( $this->pro_link );
		echo '<input style="vertical-align:middle;margin:0 5px 0 0 !important;" type="checkbox" class="checkbox" disabled />';
		echo '</label></p>';
	}

	/**
	 * Save POS visibility on post save.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function save_post( $post_id, $post ): void {
		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) { // @phpstan-ignore-line
			return;
		}

		// Don't save revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Make sure the current user has permission to edit the post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Get the product and save.
		$valid_options = array( 'pos_only', 'online_only', '' );

		if ( isset( $_POST['_pos_visibility'] ) && \in_array( $_POST['_pos_visibility'], $valid_options, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress save_post.
			$settings_instance = Settings::instance();
			$args              = array(
				'post_type'  => 'products',
				'visibility' => sanitize_text_field( wp_unslash( $_POST['_pos_visibility'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress save_post.
				'ids'        => array( $post_id ),
			);
			$settings_instance->update_visibility_settings( $args );
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

		$selected          = '';
		$settings_instance = Settings::instance();
		$pos_only          = $settings_instance->is_product_pos_only( $post->ID );
		$online_only       = $settings_instance->is_product_online_only( $post->ID );

		// Set $selected based on the visibility status.
		if ( $pos_only ) {
			$selected = 'pos_only';
		} elseif ( $online_only ) {
			$selected = 'online_only';
		}

		if ( ! $selected ) {
			$selected = '';
			if ( 'add' == get_current_screen()->action ) {
				$selected = apply_filters( 'woocommerce_pos_default_product_visibility', '', $post );
			}
		}

		include 'templates/post-metabox-visibility-select.php';
	}

	/**
	 * Add POS visibility select to variable product attributes.
	 *
	 * @param int     $loop           Position in the loop.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Post data.
	 */
	public function after_variable_attributes_pos_only_products( $loop, $variation_data, $variation ): void {
		$selected          = '';
		$settings_instance = Settings::instance();
		$pos_only          = $settings_instance->is_variation_pos_only( $variation->ID );
		$online_only       = $settings_instance->is_variation_online_only( $variation->ID );

		// Set $selected based on the visibility status.
		if ( $pos_only ) {
			$selected = 'pos_only';
		} elseif ( $online_only ) {
			$selected = 'online_only';
		}

		include 'templates/variation-metabox-visibility-select.php';
	}

	/**
	 * Save POS visibility for a product variation.
	 *
	 * @param int $variation_id The variation ID.
	 */
	public function save_product_variation_pos_only_products( $variation_id ): void {
		$valid_options = array( 'pos_only', 'online_only', '' );

		if ( isset( $_POST['variable_pos_visibility'][ $variation_id ] ) && \in_array( $_POST['variable_pos_visibility'][ $variation_id ], $valid_options, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
			$settings_instance = Settings::instance();
			$args              = array(
				'post_type'  => 'variations',
				'visibility' => sanitize_text_field( wp_unslash( $_POST['variable_pos_visibility'][ $variation_id ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce.
				'ids'        => array( $variation_id ),
			);
			$settings_instance->update_visibility_settings( $args );
		}
	}

	/**
	 * Get the list of barcode fields that should be excluded from custom barcode field functionality.
	 *
	 * These fields are built-in WooCommerce or plugin fields that don't need custom barcode input.
	 *
	 * @return array Array of excluded barcode field keys.
	 */
	private function get_excluded_barcode_fields(): array {
		$excluded_fields = array(
			'_sku',                 // default WooCommerce SKU field.
			'_global_unique_id',    // default WooCommerce GTIN, UPC, EAN, or ISBN.
			'_alg_ean',             // https://wpfactory.com/item/ean-barcodes-woocommerce/.
		);

		/*
		 * Filter the list of barcode fields that should be excluded from custom barcode field functionality.
		 *
		 * @param array $excluded_fields Array of field keys to exclude from custom barcode input.
		 */
		return apply_filters( 'woocommerce_pos_excluded_custom_barcode_fields', $excluded_fields );
	}
}
