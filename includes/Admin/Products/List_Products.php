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

use WC_Product;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;

/**
 *
 */
class List_Products {
	/**
	 * @var string
	 */
	private $barcode_field;

	/**
	 * @var array
	 */
	private $options;



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
			add_action(
				'woocommerce_product_after_variable_attributes',
				array(
					$this,
					'after_variable_attributes_barcode_field',
				),
				10,
				3
			);
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_barcode_field' ) );
		}

		if ( woocommerce_pos_get_settings( 'general', 'pos_only_products' ) ) {
			add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
			add_filter( 'views_edit-product', array( $this, 'pos_visibility_filters' ), 10, 1 );
			add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit' ), 10, 2 );
			add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save' ) );
			add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
			add_action( 'manage_product_posts_custom_column', array( $this, 'custom_product_column' ), 10, 2 );
			add_action(
				'woocommerce_product_after_variable_attributes',
				array(
					$this,
					'after_variable_attributes_pos_only_products',
				),
				10,
				3
			);
			add_action(
				'woocommerce_save_product_variation',
				array(
					$this,
					'save_product_variation_pos_only_products',
				)
			);
		}

		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_uuid_meta_on_product_duplicate' ) );
	}

	/**
	 *
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
	 * @param $post_id
	 */
	public function woocommerce_process_product_meta( $post_id ): void {
		if ( isset( $_POST[ $this->barcode_field ] ) ) {
			update_post_meta( $post_id, $this->barcode_field, sanitize_text_field( $_POST[ $this->barcode_field ] ) );
		}
	}

	/**
	 * Admin filters for POS / Online visibility.
	 *
	 * @param array $views
	 *
	 * @return array
	 */
	public function pos_visibility_filters( array $views ): array {
		global $wpdb;

		$visibility_filters = array(
			'pos_only'    => __( 'POS Only', 'woocommerce-pos' ),
			'online_only' => __( 'Online Only', 'woocommerce-pos' ),
		);

		if ( ! empty( $_GET['pos_visibility'] ) ) {
			$views['all'] = str_replace( 'class="current"', '', $views['all'] );
		}

		$settings_instance = Settings::instance();

		// Get the product IDs for the POS and Online only products
		$pos_only = $settings_instance->get_pos_only_product_visibility_settings();
		$pos_only_ids = isset( $pos_only['ids'] ) && is_array( $pos_only['ids'] ) ? array_map( 'intval', (array) $pos_only['ids'] ) : array();
		$online_only = $settings_instance->get_online_only_product_visibility_settings();
		$online_only_ids = isset( $online_only['ids'] ) && is_array( $online_only['ids'] ) ? array_map( 'intval', (array) $online_only['ids'] ) : array();

		$new_views = array();

		foreach ( $visibility_filters as $key => $label ) {
			$count = 0;
			$ids = array();
			$format = '';

			if ( 'pos_only' === $key ) {
				$ids = $pos_only_ids;
				$format = implode( ',', array_fill( 0, count( $pos_only_ids ), '%d' ) );
			} elseif ( 'online_only' === $key ) {
				$ids = $online_only_ids;
				$format = implode( ',', array_fill( 0, count( $online_only_ids ), '%d' ) );
			}

			if ( ! empty( $ids ) ) {
				$sql = "SELECT count(DISTINCT ID) FROM {$wpdb->posts} WHERE post_type = 'product'";
				$sql .= $wpdb->prepare( " AND ID IN ($format) ", $ids );
				$count = $wpdb->get_var( $sql );
			}

			$class             = ( isset( $_GET['pos_visibility'] ) && $_GET['pos_visibility'] == $key ) ? 'current' : '';
			$query_string      = remove_query_arg( array( 'pos_visibility', 'post_status' ) );
			$query_string      = add_query_arg( 'pos_visibility', urlencode( $key ), $query_string );
			$new_views[ $key ] = '<a href="' . esc_url( $query_string ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</a></a>';
		}

		// Insert before the 'sort' key
		$offset = array_search( 'publish', array_keys( $views ), true );
		$views  = array_merge(
			\array_slice( $views, 0, $offset + 1 ),
			$new_views,
			\array_slice( $views, $offset + 1 )
		);

		return $views;
	}


	/**
	 * Modify the SQL clauses for the query to filter by POS visibility.
	 *
	 * @param array    $clauses
	 * @param WP_Query $query
	 *
	 * @return array
	 */
	public function posts_clauses( array $clauses, WP_Query $query ): array {
			// Ensure we're in the admin and it's the main query.
		if ( ! is_admin() || ! $query->is_main_query() ) {
				return $clauses;
		}

		// If 'pos_visibility' filter is set.
		if ( empty( $_GET['pos_visibility'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$visibility = sanitize_text_field( wp_unslash( $_GET['pos_visibility'] ) );
		$settings_instance = Settings::instance();

		if ( 'pos_only' === $visibility ) {
			$pos_only = $settings_instance->get_pos_only_product_visibility_settings();
			$pos_only_ids = isset( $pos_only['ids'] ) && is_array( $pos_only['ids'] ) ? array_map( 'intval', (array) $pos_only['ids'] ) : array();
			$format = implode( ',', array_fill( 0, count( $pos_only_ids ), '%d' ) );
			if ( empty( $pos_only_ids ) ) {
				// No IDs, show no records.
				$clauses['where'] .= ' AND 1=0 ';
			} else {
				$clauses['where'] .= $wpdb->prepare( " AND ID IN ($format) ", $pos_only_ids );
			}
		} elseif ( 'online_only' === $visibility ) {
			$online_only = $settings_instance->get_online_only_product_visibility_settings();
			$online_only_ids = isset( $online_only['ids'] ) && is_array( $online_only['ids'] ) ? array_map( 'intval', (array) $online_only['ids'] ) : array();
			$format = implode( ',', array_fill( 0, count( $online_only_ids ), '%d' ) );
			if ( empty( $online_only_ids ) ) {
				// No IDs, show no records.
				$clauses['where'] .= ' AND 1=0 ';
			} else {
				$clauses['where'] .= $wpdb->prepare( " AND ID IN ($format) ", $online_only_ids );
			}
		}

		return $clauses;
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
	 * NOTE: This is required for AJAX save to work on the quick edit form.
	 *
	 * @param WC_Product $product
	 *
	 * @return void
	 */
	public static function quick_edit_save( WC_Product $product ): void {
		$valid_options = array( 'pos_only', 'online_only', '' );

		if ( isset( $_POST['_pos_visibility'] ) && in_array( $_POST['_pos_visibility'], $valid_options, true ) ) {
			$settings_instance = Settings::instance();
			$args = array(
				'post_type' => 'products',
				'visibility' => sanitize_text_field( $_POST['_pos_visibility'] ),
				'ids' => array( $product->get_id() ),
			);
			$settings_instance->update_visibility_settings( $args );
		}
	}

	/**
	 * @param WC_Product $product
	 *
	 * @return void
	 */
	public function bulk_edit_save( WC_Product $product ): void {
		$valid_options = array( 'pos_only', 'online_only', '' );

		if ( isset( $_GET['_pos_visibility'] ) && in_array( $_GET['_pos_visibility'], $valid_options, true ) ) {
			$settings_instance = Settings::instance();
			$args = array(
				'post_type' => 'products',
				'visibility' => $_GET['_pos_visibility'],
				'ids' => array( $product->get_id() ),
			);
			$settings_instance->update_visibility_settings( $args );
		}
	}

	/**
	 * @param $column
	 * @param $post_id
	 */
	public function custom_product_column( $column, $post_id ): void {
		if ( 'name' == $column ) {
			$selected = '';
			$settings_instance = Settings::instance();
			$pos_only = $settings_instance->is_product_pos_only( $post_id );
			$online_only = $settings_instance->is_product_online_only( $post_id );

			 // Set $selected based on the visibility status.
			if ( $pos_only ) {
				$selected = 'pos_only';
			} elseif ( $online_only ) {
				$selected = 'online_only';
			}

			echo '<div class="hidden" id="woocommerce_pos_inline_' . $post_id . '" data-visibility="' . $selected . '"></div>';
		}
	}

	/**
	 * Filter to allow us to exclude meta keys from product duplication..
	 *
	 * @param array $exclude_meta The keys to exclude from the duplicate.
	 * @param array $existing_meta_keys The meta keys that the product already has.
	 *
	 * @return array
	 */
	public function exclude_uuid_meta_on_product_duplicate( array $meta_keys ) {
		$meta_keys[] = '_woocommerce_pos_uuid';
		return $meta_keys;
	}
}
