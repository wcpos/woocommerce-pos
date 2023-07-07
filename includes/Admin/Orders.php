<?php

namespace WCPOS\WooCommercePOS\Admin;

use WC_Order;
use WP_Query;
use const WCPOS\WooCommercePOS\PLUGIN_URL;

class Orders {

	public function __construct() {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );

        // add filter dropdown to orders list page
        add_action( 'restrict_manage_posts', array( $this, 'order_filter_dropdown' ) );
        add_filter( 'parse_query', array( $this, 'parse_query' ) );

        // add column for POS orders
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'pos_shop_order_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'pos_orders_list_column_content' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'pos_order_column_width' ) );
    }

	/**
	 * Hides uuid from appearing on Order Edit page
	 *
	 * @param array $meta_keys
	 * @return array
	 */
	public function hidden_order_itemmeta( array $meta_keys ): array {
		return array_merge( $meta_keys, array( '_woocommerce_pos_uuid', '_woocommerce_pos_tax_status' ) );
	}

	/**
	 * Makes POS orders editable by default
	 *
	 * @param bool $is_editable
	 * @param WC_Order $order
	 * @return bool
	 */
	public function wc_order_is_editable( bool $is_editable, WC_Order $order ): bool {
		if ( $order->get_status() == 'pos-open' ) {
			$is_editable = true;
		}
		return $is_editable;
	}

    /**
     *
     */
    public function order_filter_dropdown() {
        $selected = isset( $_GET['pos_order'] ) ? $_GET['pos_order'] : '';

        $options = array(
            'yes' => __( 'POS', 'woocommerce-pos' ),
            'no' => __( 'Online', 'woocommerce-pos' ),
        );

        echo '<select name="pos_order" id="pos_order">';
        echo '<option value="">All orders</option>';
        foreach ( $options as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ';
            selected( $selected, $value );
            echo '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param WP_Query $query
     * @return void
     */
    public function parse_query( WP_Query $query ) {
        if ( isset( $_GET['pos_order'] ) && $_GET['pos_order'] != '' ) {
            $meta_query = array( 'relation' => 'AND' );

            if ( $_GET['pos_order'] == 'yes' ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'       => '_pos',
                        'value'     => '1',
                        'compare'   => '=',
                    ),
                    array(
                        'key' => '_created_via',
                        'value' => 'woocommerce-pos',
                        'compare'   => '=',
                    ),
                );
            } elseif ( $_GET['pos_order'] == 'no' ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'       => '_pos',
                        'compare'   => 'NOT EXISTS',
                    ),
                    array(
                        'key'       => '_pos',
                        'value'     => '1',
                        'compare'   => '!=',
                    ),
                );
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key' => '_created_via',
                        'compare'   => 'NOT EXISTS',
                    ),
                    array(
                        'key' => '_created_via',
                        'value' => 'woocommerce-pos',
                        'compare'   => '!=',
                    ),
                );
            }

            if ( isset( $query->query_vars['meta_query'] ) ) {
                $query->query_vars['meta_query'] = array_merge(
                    $query->query_vars['meta_query'],
                    $meta_query
                );
            } else {
                $query->query_vars['meta_query'] = $meta_query;
            }
        }
    }


    /**
     * @param string[] $columns The column header labels keyed by column ID.
     * @return string[]
     */
    public function pos_shop_order_column( array $columns ): array {
        $new_columns = array();

        foreach ( $columns as $column_name => $column_info ) {

            if ( 'order_date' === $column_name ) {
                $new_columns['wcpos'] = '';
            }

            $new_columns[ $column_name ] = $column_info;
        }

        return $new_columns;
    }

    /**
     *
     *
     * @param string $column_name The name of the column to display.
     * @param int $post_id     The current post ID.
     *
     * @return void
     */
    public function pos_orders_list_column_content( string $column_name, int $post_id ): void {
        global $post;
        if ( $column_name == 'wcpos' ) {
            $legacy = get_post_meta( $post->ID, '_pos', true );
            $created_via = get_post_meta( $post->ID, '_created_via', true );
            if ( $created_via == 'woocommerce-pos' || $legacy == 1 ) {
                echo '<span class="wcpos-icon"></span>';
            }
        }
    }

    /**
     * @return void
     */
    public function pos_order_column_width() {
        // Define the URL of your custom icon
        $icon_url = PLUGIN_URL . '/assets/img/wp-menu-icon.svg';

        $css = '
            body.post-type-shop_order .wp-list-table .column-wcpos { width: 16px !important; padding: 0 !important; }
            .wcpos-icon { display: inline-block; width: 16px; height: 16px; background: url(' . $icon_url . ') no-repeat center center; margin-top: 10px; }
        ';

        wp_add_inline_style( 'woocommerce_admin_styles', $css );
    }
}
