<?php
/**
 * Provides a Cash Payment Gateway.
 *
 * @author      Paul Kilmurray <paul@kilbot.com>
 *
 * @see        https://wcpos.com
 *
 * @extends     WC_Payment_Gateway
 */

namespace WCPOS\WooCommercePOS\Gateways;

use WC_Order;
use WC_Order_Item_Fee;
use WC_Payment_Gateway;

/**
 *
 */
class Cash extends WC_Payment_Gateway {
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id          = 'pos_cash';
		$this->title       = __( 'Cash', 'woocommerce-pos' );
		$this->description = '';
		$this->icon        = apply_filters( 'woocommerce_pos_cash_icon', '' );
		$this->has_fields  = true;
		$this->enabled     = 'no';

		// Actions
		add_action(
			'woocommerce_pos_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);
		add_action( 'woocommerce_thankyou_pos_cash', array( $this, 'calculate_change' ) );
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public static function payment_details( WC_Order $order ) {
		return array(
			'tendered' => get_post_meta( $order->get_id(), '_pos_cash_amount_tendered', true ),
			'change'   => get_post_meta( $order->get_id(), '_pos_cash_change', true ),
		);
	}

	/**
	 * Display the payment fields on the checkout modal.
	 */
	public function payment_fields(): void {
		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? $wp->query_vars['order-pay'] : null;
		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		$currency_pos = get_option( 'woocommerce_currency_pos' );

		if ( 'left' == $currency_pos || 'left_space' ) {
			$left_addon  = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
			$right_addon = '';
		} else {
			$left_addon  = '';
			$right_addon = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
		}

		echo '
		<div class="form-row" id="pos-cash-tendered_field" style="display: flex; justify-content: space-between;">
			<div style="flex: 1;">
				<label for="pos-cash-tendered" style="padding-left:0">' . __( 'Amount Tendered', 'woocommerce-pos' ) . '</label>
				<div class="input-group">
					' . $left_addon . '
					<input type="text" class="form-control" name="pos-cash-tendered" id="pos-cash-tendered" maxlength="20" data-numpad="cash" data-label="' . __( 'Amount Tendered', 'woocommerce-pos' ) . '" data-placement="bottom" data-value="{{total}}">
					' . $right_addon . '
				</div>
			</div>
			<div style="flex: 1;">
				<label style="padding-left:0">' . __( 'Change', 'woocommerce-pos' ) . '</label>
				<div id="pos-cash-change-display"></div>
			</div>
			' . wp_nonce_field( 'pos_cash_payment_nonce', 'pos_cash_payment_nonce_field' ) . '
		</div>
    ';

		if ( $order && $order->get_total() > 0 ) {
			echo '
				<script>
				document.addEventListener("DOMContentLoaded", function() {
						var tenderedInput = document.getElementById("pos-cash-tendered");
						var changeDisplay = document.getElementById("pos-cash-change-display");

						tenderedInput.addEventListener("input", function() {
								var tenderedAmount = parseFloat(tenderedInput.value);
								var orderTotal = ' . json_encode( wc_format_decimal( $order->get_total() ) ) . '; // Get order total from PHP
								var change = tenderedAmount - orderTotal;

								if (change > 0) {
										changeDisplay.innerHTML = change.toFixed(' . json_encode( wc_get_price_decimals() ) . ');
								} else {
										changeDisplay.innerHTML = "";
								}
						});
				});
				</script>
			';
		}
	}

	/**
	 * @param int $order_id
	 *
	 * @return string[]
	 */
	public function process_payment( $order_id ): array {
		// Check nonce
		if ( ! isset( $_POST['pos_cash_payment_nonce_field'] ) || ! wp_verify_nonce( $_POST['pos_cash_payment_nonce_field'], 'pos_cash_payment_nonce' ) ) {
			wp_die( __( 'Nonce verification failed', 'woocommerce-pos' ) );
		}

		// get order object
		$order = new WC_Order( $order_id );
		$tendered = $order->get_total();

		// get pos_cash data from $_POST
		if ( isset( $_POST['pos-cash-tendered'] ) && ! empty( $_POST['pos-cash-tendered'] ) ) {
			$tendered = wc_format_decimal( wp_unslash( $_POST['pos-cash-tendered'] ) );
		}
		$change = $tendered > $order->get_total() ? wc_format_decimal( floatval( $tendered ) - floatval( $order->get_total() ) ) : '0';
		$order->update_meta_data( '_pos_cash_amount_tendered', $tendered );
		$order->update_meta_data( '_pos_cash_change', $change );

		if ( $tendered >= $order->get_total() ) {
			// payment complete
			$order->payment_complete();
		} else {
			// Add negative fee to adjust order total
			$fee = new WC_Order_Item_Fee();
			$fee->set_props(
				array(
					'name'      => __( 'Partial Payment', 'woocommerce-pos' ),
					'tax_class' => 0,
					'amount'    => '-' . $tendered,
					'total'     => '-' . $tendered,
					'total_tax' => 0,
				)
			);
			// $fee->set_name( __( 'Partial Payment', 'woocommerce-pos' ) );
			// $fee->set_amount( '-' . $tendered );
			// $fee->set_total( '-' . $tendered );
			// $fee->set_total_tax( '0' );
			// $fee->set_tax_status( 'none' );
			$fee->add_meta_data( 'date_paid_gmt', gmdate( 'Y-m-d\TH:i:s' ), true );
			$fee->set_order_id( $order_id );
			$fee->save();

			$order->add_item( $fee );
			$order->set_total( wc_format_decimal( floatval( $order->get_total() ) - floatval( $tendered ) ) );
			$order->save();

			// Set order status to 'wc-pos-partial'
			$order->update_status( 'wc-pos-partial' );
		}

		// Return thankyou redirect
		// $redirect = add_query_arg(array(
		// 'wcpos' => 1,
		// ), get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() ));

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * @param $order_id
	 */
	public function calculate_change( $order_id ): void {
		$message  = '';
		$tendered = get_post_meta( $order_id, '_pos_cash_amount_tendered', true );
		$change   = get_post_meta( $order_id, '_pos_cash_change', true );

		// construct message
		if ( $tendered && $change ) {
			$message = __( 'Amount Tendered', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $tendered ) . '<br>';
			$message .= _x( 'Change', 'Money returned from cash sale', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $change );
		}

		echo $message;
	}
}
