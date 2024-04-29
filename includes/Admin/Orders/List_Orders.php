<?php

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Abstract_Order;
use WP_Query;
use const WCPOS\WooCommercePOS\PLUGIN_URL;

class List_Orders {

	/**
	 * List_Orders constructor.
	 */
	public function __construct() {
		// add filter dropdown to orders list page
		add_action( 'restrict_manage_posts', array( $this, 'order_filter_dropdown' ) );
		add_filter( 'parse_query', array( $this, 'parse_query' ) );

		// add column for POS orders
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'pos_shop_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'pos_orders_list_column_content' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'pos_order_column_width' ) );
	}

	/**
	 * Add a filter dropdown to the orders list page.
	 *
	 * @return void
	 */
	public function order_filter_dropdown(): void {
		$selected = $_GET['pos_order'] ?? '';

		$options = array(
			'yes' => __( 'POS', 'woocommerce-pos' ),
			'no'  => __( 'Online', 'woocommerce-pos' ),
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
	 * Parse the query to filter orders by POS or online.
	 *
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 *
	 * @return WP_Query
	 */
	public function parse_query( $query ) {
		if ( isset( $_GET['pos_order'] ) && '' != $_GET['pos_order'] ) {
			$meta_query = array( 'relation' => 'AND' );

			if ( 'yes' == $_GET['pos_order'] ) {
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'       => '_pos',
						'value'     => '1',
						'compare'   => '=',
					),
					array(
						'key'       => '_created_via',
						'value'     => 'woocommerce-pos',
						'compare'   => '=',
					),
				);
			} elseif ( 'no' == $_GET['pos_order'] ) {
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
						'key'       => '_created_via',
						'compare'   => 'NOT EXISTS',
					),
					array(
						'key'       => '_created_via',
						'value'     => 'woocommerce-pos',
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

		return $query;
	}


	/**
	 * Add a custom column to the orders list table.
	 *
	 * @param string[] $columns The column header labels keyed by column ID.
	 *
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
	 * Display the content for the custom column.
	 *
	 * @param string $column_name The name of the column to display.
	 * @param int    $post_id     The current post ID.
	 *
	 * @return void
	 */
	public function pos_orders_list_column_content( string $column_name, int $post_id ): void {
		if ( 'wcpos' === $column_name && woocommerce_pos_is_pos_order( $post_id ) ) {
			echo '<span class="wcpos-icon" title="POS Order"></span>';
		}
	}


	/**
	 * @return void
	 */
	public function pos_order_column_width(): void {
		// Define the URL of your custom icon
		$icon_url = PLUGIN_URL . '/assets/img/wp-menu-icon.svg';

		$css = '
            body.post-type-shop_order .wp-list-table .column-wcpos { width: 16px !important; padding: 0 !important; }
            .wcpos-icon { display: inline-block; width: 16px; height: 16px; background: url(' . $icon_url . ') no-repeat center center; margin-top: 10px; }
        ';

		wp_add_inline_style( 'woocommerce_admin_styles', $css );
	}
}
