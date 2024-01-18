<?php

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Order;

class Single_Order {
	public function __construct() {
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_cashier_select' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_cashier_select' ) );
	}

	/**
	 * Makes POS orders editable by default.
	 *
	 * @param bool     $is_editable
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function wc_order_is_editable( bool $is_editable, WC_Order $order ): bool {
		if ( 'pos-open' == $order->get_status() ) {
			$is_editable = true;
		}

		return $is_editable;
	}

	/**
	 * Add cashier select to order page.
	 */
	public function add_cashier_select( WC_Order $order ): void {
		// only show if order created by POS
		if ( 'woocommerce-pos' !== $order->get_created_via() ) {
			return;
		}

		$cashier_id = $order->get_meta( '_pos_user' );

		// Create nonce for security
		wp_nonce_field( 'pos_cashier_select_action', 'pos_cashier_select_nonce' );

		echo '<p class="form-field form-field-wide">';
		echo '<label for="_pos_user">' . esc_html__( 'POS Cashier', 'woocommerce-pos' ) . ':</label>';
		echo '<select class="wc-customer-search" id="_pos_user" name="_pos_user" data-placeholder="' . esc_attr__( 'Search for a cashier&hellip;', 'woocommerce-pos' ) . '" data-allow_clear="true" style="width: 100%;">';
		if ( $cashier_id ) {
			$user = get_user_by( 'id', $cashier_id );
			if ( $user ) {
				echo '<option value="' . esc_attr( $user->ID ) . '"' . selected( true, true, false ) . '>' . esc_html( $user->display_name . ' (#' . $user->ID . ' &ndash; ' . $user->user_email ) . ')</option>';
			}
		}
		echo '</select>';
		echo '</p>';
	}

	/**
	 * Save store select in order admin.
	 *
	 * @param int $post_id
	 */
	public function save_cashier_select( $post_id ): void {
		// Security checks
		if ( ! isset( $_POST['pos_cashier_select_nonce'] ) || ! wp_verify_nonce( $_POST['pos_cashier_select_nonce'], 'pos_cashier_select_action' ) ) {
			return;
		}

		/**
		 * NOTE: HPOS adds a second arg for WC_Order, but we will make it backwards compatible.
		 */
		$order = wc_get_order( $post_id );

		// Check if $order is instance of WC_Order and $_POST['_pos_user'] is set
		if ( $order instanceof WC_Order && isset( $_POST['_pos_user'] ) ) {
			$new_pos_cashier     = (int) sanitize_text_field( $_POST['_pos_user'] );
			$current_pos_cashier = (int) $order->get_meta( '_pos_user' );

			// Update meta only if _pos_user has changed
			if ( $current_pos_cashier !== $new_pos_cashier ) {
				$order->update_meta_data( '_pos_user', $new_pos_cashier );
				$order->save();

				// Add an order note indicating the change
				$current_cashier    = get_userdata( $current_pos_cashier );
				$new_cashier        = get_userdata( $new_pos_cashier );
				$note               = sprintf(
					// translators: 1: old POS cashier, 2: new POS cashier
					__( 'POS cashier changed from %1$s to %2$s.', 'woocommerce-pos' ),
					$current_cashier ? $current_cashier->display_name : __( 'Unknown', 'woocommerce-pos' ),
					$new_cashier ? $new_cashier->display_name : __( 'Unknown', 'woocommerce-pos' )
				);
				$order->add_order_note( $note );
			}
		}
	}
}
