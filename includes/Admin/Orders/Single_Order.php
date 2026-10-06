<?php
/**
 * Single Order
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin\Orders;

use WC_Abstract_Order;
use WC_Order;
use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Pos_Payments;

/**
 * Single_Order class.
 */
class Single_Order {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wc_order_is_editable', array( $this, 'wc_order_is_editable' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_cashier_select' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_cashier_select' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'add_customer_change_note' ), 10 );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_pos_payments' ) );

		$this->add_available_gateways();
	}

	/**
	 * We need to add the POS gateways to the available gateways for the edit order dropdown.
	 * It's possible another plugin could just re-init after us, but this will work for most cases.
	 */
	public function add_available_gateways() {
		// @phpstan-ignore-next-line
		if ( WC()->payment_gateways() ) {
			$payment_gateways = WC()->payment_gateways->payment_gateways;
			$settings = woocommerce_pos_get_settings( 'payment_gateways' );

			// Add POS gateways to the available gateways for the edit order dropdown.
			foreach ( $payment_gateways as $gateway ) {
				if ( isset( $settings['gateways'][ $gateway->id ] ) && $settings['gateways'][ $gateway->id ]['enabled'] ) {
					$gateway->enabled = 'yes';
				}
			}

			// Directly set the modified gateways back to the WooCommerce Payment Gateways instance.
			WC()->payment_gateways->payment_gateways = $payment_gateways;
		}
	}

	/**
	 * Makes POS orders editable by default.
	 *
	 * @param bool              $is_editable Whether the order is editable.
	 * @param WC_Abstract_Order $order       The order object.
	 *
	 * @return bool
	 */
	public function wc_order_is_editable( $is_editable, WC_Abstract_Order $order ) {
		if ( 'pos-open' == $order->get_status() ) {
			return true;
		}

		return $is_editable;
	}

	/**
	 * Add cashier select to order page.
	 *
	 * @param WC_Abstract_Order $order Order object.
	 */
	public function add_cashier_select( $order ): void {
		// only show if order created by POS.
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return;
		}

		$cashier_id = $order->get_meta( '_pos_user' );

		// Create nonce for security.
		wp_nonce_field( 'pos_cashier_select_action', 'pos_cashier_select_nonce' );

		echo '<p class="form-field form-field-wide">';
		echo '<label for="_pos_user">' . /* translators: Order list label shown in WooCommerce admin for POS orders. */ esc_html__( 'POS Cashier', 'woocommerce-pos' ) . ':</label>';
		echo '<select class="wc-customer-search" id="_pos_user" name="_pos_user" data-placeholder="' . /* translators: Order list label shown in WooCommerce admin for POS orders. */ esc_attr__( 'Search for a cashier&hellip;', 'woocommerce-pos' ) . '" data-allow_clear="true" style="width: 100%;">';
		if ( $cashier_id ) {
			$user = get_user_by( 'id', $cashier_id );
			if ( $user ) {
				echo '<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( true, true, false ) . '>' . esc_html( $user->display_name . ' (#' . $user->ID . ' &ndash; ' . $user->user_email ) . ')</option>';
			}
		}
		echo '</select>';
		echo '</p>';
	}

	/**
	 * Display POS payments below the admin order totals.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_pos_payments( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Abstract_Order || ! woocommerce_pos_is_pos_order( $order ) ) {
			return;
		}
		$tenders = Pos_Payments::from_order( $order );
		if ( empty( $tenders ) ) {
			return;
		}

		echo '<tr><td class="label">' . /* translators: Order totals label shown in WooCommerce admin for POS orders. */ esc_html__( 'POS payments', 'woocommerce-pos' ) . ':</td><td width="1%"></td><td class="total"></td></tr>';
		foreach ( $tenders as $tender ) {
			echo '<tr><td class="label">' . esc_html( $tender['title'] );
			if ( isset( $tender['reference'] ) ) {
				echo ' (' . esc_html( $tender['reference'] ) . ')';
			}
			echo ':</td><td width="1%"></td><td class="total">';
			echo wp_kses_post( wc_price( (float) $tender['amount'], array( 'currency' => $order->get_currency() ) ) );
			$details = array();
			if ( isset( $tender['tendered'] ) ) {
				$details[] = /* translators: Order note label for the cash amount received from the customer at checkout. */ __( 'Amount Tendered', 'woocommerce-pos' ) . ': ' . wc_price( (float) $tender['tendered'], array( 'currency' => $order->get_currency() ) );
			}
			if ( isset( $tender['change'] ) ) {
				$details[] = /* translators: Money returned to the customer after a cash payment. */ _x( 'Change', 'Money returned from cash sale', 'woocommerce-pos' ) . ': ' . wc_price( (float) $tender['change'], array( 'currency' => $order->get_currency() ) );
			}
			if ( ! empty( $details ) ) {
				echo '<br><small>' . wp_kses_post( implode( '<br>', $details ) ) . '</small>';
			}
			echo '</td></tr>';
		}
	}

	/**
	 * Save store select in order admin.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_cashier_select( $post_id ): void {
		// Security checks.
		if ( ! isset( $_POST['pos_cashier_select_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pos_cashier_select_nonce'] ) ), 'pos_cashier_select_action' ) ) {
			return;
		}

		/**
		 * NOTE: HPOS adds a second arg for WC_Abstract_Order, but we will make it backwards compatible.
		 */
		$order = wc_get_order( $post_id );

		// Check if $order is instance of WC_Abstract_Order and $_POST['_pos_user'] is set.
		if ( $order instanceof WC_Abstract_Order && isset( $_POST['_pos_user'] ) ) {
			$new_pos_cashier     = (int) sanitize_text_field( wp_unslash( $_POST['_pos_user'] ) );
			$current_pos_cashier = (int) $order->get_meta( '_pos_user' );

			// Update meta only if _pos_user has changed.
			if ( $current_pos_cashier !== $new_pos_cashier ) {
				$order->update_meta_data( '_pos_user', (string) $new_pos_cashier );
				$order->save();

				// Add an order note indicating the change.
				$current_cashier    = get_userdata( $current_pos_cashier );
				$new_cashier        = get_userdata( $new_pos_cashier );
				$note               = sprintf(
					// translators: 1: old POS cashier, 2: new POS cashier.
					__( 'POS cashier changed from %1$s to %2$s.', 'woocommerce-pos' ),
					$current_cashier ? $current_cashier->display_name : /* translators: Fallback cashier name shown when the POS cashier user cannot be found. */ __( 'Unknown', 'woocommerce-pos' ),
					$new_cashier ? $new_cashier->display_name : /* translators: Fallback cashier name shown when the POS cashier user cannot be found. */ __( 'Unknown', 'woocommerce-pos' )
				);
				$order->add_order_note( $note );
			}
		}
	}

	/**
	 * Add an audit note before WooCommerce applies its posted customer change.
	 *
	 * WooCommerce 10.4.3 registers WC_Meta_Box_Order_Data::save at priority 40;
	 * this priority-10 callback therefore reads the old customer from the order.
	 * Core verifies its order-data nonce before firing this hook.
	 *
	 * @param int $post_id The order ID.
	 */
	public function add_customer_change_note( $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its order-data nonce before firing this hook.
		if ( ! array_key_exists( 'customer_user', $_POST ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order instanceof WC_Order || ! woocommerce_pos_is_pos_order( $order ) ) {
			return;
		}
		$old_customer_id = $order->get_customer_id();
		$new_customer_id = absint( wp_unslash( $_POST['customer_user'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $old_customer_id !== $new_customer_id ) {
			Order_Notes::add_admin_customer_change_note( $order, $old_customer_id, $new_customer_id );
		}
	}
}
