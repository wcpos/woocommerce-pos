<?php
/**
 *
 */

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Abstract_Order;

/**
 * Class HPOS_List_Orders
 */
class HPOS_List_Orders extends List_Orders {
	/**
	 * NOTE: When HPOS is anabled we go to a different Order List page
	 * There are some changes to the hooks and filters.
	 *
	 * Hook Changes:
	 *
	 * OLD                                   NEW
	 * restrict_manage_posts                 woocommerce_order_list_table_restrict_manage_orders
	 * manage_edit-shop_order_columns        manage_woocommerce_page_wc-orders_columns
	 * manage_shop_order_posts_custom_column manage_woocommerce_page_wc-orders_custom_column
	 * bulk_actions-edit-shop_order          bulk_actions-woocommerce_page_wc-orders
	 * handle_bulk_actions-edit-shop_order   handle_bulk_actions-woocommerce_page_wc-orders
	 */
	public function __construct() {
		// add filter dropdown to orders list page
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'order_filter_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'query_args' ) );

		// add column for POS orders
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'pos_shop_order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'orders_custom_column_content' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'pos_order_column_width' ) );
	}

	/**
	 * Display the content for the custom column.
	 *
	 * @param string            $column_name The name of the column to display.
	 * @param WC_Abstract_Order $order       The order object whose data will be used to render the column.
	 *
	 * @return void
	 */
	public function orders_custom_column_content( string $column_name, $order ): void {
		if ( 'wcpos' === $column_name && woocommerce_pos_is_pos_order( $order ) ) {
			echo '<span class="wcpos-icon" title="POS Order"></span>';
		}
	}

	/**
	 * Parse the query to filter orders by POS or online.
	 *
	 * @TODO - add legacy support for _pos = 1
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function query_args( $args ) {
		$pos_order_filter = $_GET['pos_order'] ?? '';

		if ( $pos_order_filter ) {
			if ( ! isset( $args['field_query'] ) ) {
				$args['field_query'] = array();
			}

			if ( 'yes' === $pos_order_filter ) {
				$args['field_query'][] = array(
					'field' => 'created_via',
					'value' => 'woocommerce-pos',
				);
			}

			if ( 'no' === $pos_order_filter ) {
				$args['field_query'][] = array(
					'field'   => 'created_via',
					'value'   => 'woocommerce-pos',
					'compare' => '!=',
				);
			}
		}

		return $args;
	}
}
