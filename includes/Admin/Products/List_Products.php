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
use WP_Query;
use function defined;
use function in_array;
use function is_array;
use const DOING_AUTOSAVE;

class List_Products {
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
            add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
            add_filter( 'views_edit-product', array( $this, 'pos_visibility_filters' ), 10, 1 );
            add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit' ), 10, 2 );
            add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save' ) );
            add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
            add_action( 'manage_product_posts_custom_column', array( $this, 'custom_product_column' ), 10, 2 );
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
     * Admin filters for POS / Online visibility
     *
     * @param  array $views
     *
     * @return array
     */
    public function pos_visibility_filters( array $views ): array {
        global $wpdb;

        $visibility_filters = array(
            'pos_only' => __( 'POS Only', 'woocommerce-pos' ),
            'online_only' => __( 'Online Only', 'woocommerce-pos' ),
        );

        if ( ! empty( $_GET['pos_visibility'] ) ) {
            $views['all'] = str_replace( 'class="current"', '', $views['all'] );
        }

        $new_views = array();

        foreach ( $visibility_filters as $key => $label ) {

            $sql = $wpdb->prepare(
                "SELECT count(DISTINCT pm.post_id)
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON (p.ID = pm.post_id)
                WHERE pm.meta_key = '_pos_visibility'
                AND pm.meta_value = %s
                AND p.post_type = 'product'
                AND p.post_status = 'publish'
              ",
                $key
            );
            $count = $wpdb->get_var( $sql );

            $class = ( isset( $_GET['pos_visibility'] ) && $_GET['pos_visibility'] == $key ) ? 'current' : '';
            $query_string = remove_query_arg( array( 'pos_visibility', 'post_status' ) );
            $query_string = add_query_arg( 'pos_visibility', urlencode( $key ), $query_string );
            $new_views[ $key ] = '<a href="' . esc_url( $query_string ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</a></a>';
        }

        // Insert before the 'sort' key
        $offset = array_search( 'publish', array_keys( $views ) );
        $views = array_merge(
            array_slice( $views, 0, $offset + 1 ),
            $new_views,
            array_slice( $views, $offset + 1 )
        );

        return $views;
    }


    /**
     * Show/hide POS products.
        *
     * @param WP_Query $query
     */
    public function pre_get_posts( WP_Query $query ) {
        // Ensure we're in the admin and it's the main query
        if ( ! is_admin() && ! $query->is_main_query() ) {
            return;
        }

        // If 'pos_visibility' filter is set
        if ( ! empty( $_GET['pos_visibility'] ) ) {
            $meta_query = array(
                array(
                    'key' => '_pos_visibility',
                    'value' => sanitize_text_field( wp_unslash( $_GET['pos_visibility'] ) ),
                ),
            );

            $query->set( 'meta_query', $meta_query );
        }
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
     * @param WC_Product $product
     * @return void
     */
    public static function quick_edit_save( WC_Product $product ): void {
        if ( ! empty( $_POST['_pos_visibility'] ) ) {
            $product->update_meta_data( '_pos_visibility', sanitize_text_field( $_POST['_pos_visibility'] ) );
            $product->save();
        }
    }

    /**
     * @param WC_Product $product
     * @return void
     */
    public function bulk_edit_save( WC_Product $product ): void {
        if ( ! empty( $_GET['_pos_visibility'] ) ) {
            $product->update_meta_data( '_pos_visibility', sanitize_text_field( $_GET['_pos_visibility'] ) );
            $product->save();
        }
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
}
