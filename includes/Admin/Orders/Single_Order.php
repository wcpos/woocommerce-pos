<?php

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Order;

class Single_Order {
	public function __construct() {
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );
		add_action('woocommerce_admin_order_data_after_order_details', array( $this, 'add_cashier_select' ) );
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
		$cashier_id = $order->get_meta('_pos_user');

		if ( empty( $cashier_id ) ) {
			return;
		}

		// Create nonce for security
		wp_nonce_field('custom_nonce_action', 'custom_nonce');

		echo '<p class="form-field form-field-wide">';
		echo '<label for="custom_select">' . esc_html__( 'Cashier:', 'woocommerce-pos' ) . '</label>';
		echo '<select class="wc-customer-search" id="custom_user_select" name="custom_user_select" data-placeholder="' . esc_attr__('Search for a cashier&hellip;', 'woocommerce-pos') . '" data-allow_clear="true" style="width: 100%;">';
		if ($cashier_id) {
			$user = get_user_by('id', $cashier_id);
			if ($user) {
				echo '<option value="' . esc_attr($user->ID) . '"' . selected(true, true, false) . '>' . esc_html($user->display_name . ' (#' . $user->ID . ' &ndash; ' . $user->user_email) . ')</option>';
			}
		}
		echo '</select>';
		echo '</p>';
	}
}
