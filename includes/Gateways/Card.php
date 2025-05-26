<?php

/**
 * Provides a Card Payment Gateway.
 *
 * @author      Paul Kilmurray <paul@kilbot.com>
 *
 * @see        https://wcpos.com
 *
 * @extends     WC_Payment_Gateway
 */

namespace WCPOS\WooCommercePOS\Gateways;

use WC_Payment_Gateway;

class Card extends WC_Payment_Gateway {
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id          = 'pos_card';
		$this->title       = __( 'Card', 'woocommerce-pos' );
		$this->description = '';
		$this->icon        = apply_filters( 'woocommerce_pos_card_icon', '' );
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
		add_action( 'woocommerce_thankyou_pos_card', array( $this, 'calculate_cashback' ) );
	}

	/**
	 * Display the payment fields in the checkout.
	 */
	public function payment_fields(): void {
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
      <div class="form-row " id="pos-cashback_field">
        <label for="pos-cashback" class="">' . __( 'Cashback', 'woocommerce-pos' ) . '</label>
        <div class="input-group">
        ' . $left_addon . '
          <input type="text" class="form-control" name="pos-cashback" id="pos-cashback" placeholder="" maxlength="20" data-value="0" data-numpad="cash" data-label="' . __( 'Cashback', 'woocommerce-pos' ) . '">
        ' . $right_addon . '
        </div>
		' . wp_nonce_field( 'pos_card_payment_nonce', 'pos_card_payment_nonce_field' ) . '
      </div>
    ';
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		// Check nonce
		if ( ! isset( $_POST['pos_card_payment_nonce_field'] ) || ! wp_verify_nonce( $_POST['pos_card_payment_nonce_field'], 'pos_card_payment_nonce' ) ) {
			wp_die( __( 'Nonce verification failed', 'woocommerce-pos' ) );
		}

		// get order object
		$order = wc_get_order( $order_id );

		$cashback = 0; // Initialize with default value
		if ( isset( $_POST['pos-cashback'] ) && ! empty( $_POST['pos-cashback'] ) ) {
			$cashback = wc_format_decimal( wp_unslash( $_POST['pos-cashback'] ) );
		}

		if ( 0 !== $cashback ) {
			// add order meta
			$order->update_meta_data( '_pos_card_cashback', $cashback );

			// add cashback as fee line item
			// TODO: this should be handled by $order->add_fee after WC 2.2
			$item_id = wc_add_order_item(
				$order_id,
				array(
					'order_item_name' => __( 'Cashback', 'woocommerce-pos' ),
					'order_item_type' => 'fee',
				)
			);

			if ( $item_id ) {
				wc_add_order_item_meta( $item_id, '_line_total', $cashback );
				wc_add_order_item_meta( $item_id, '_line_tax', 0 );
				wc_add_order_item_meta( $item_id, '_line_subtotal', $cashback );
				wc_add_order_item_meta( $item_id, '_line_subtotal_tax', 0 );
				wc_add_order_item_meta( $item_id, '_tax_class', 'zero-rate' );
			}

			// update the order total to include fee
			$order->set_total( wc_format_decimal( \floatval( $order->get_total() ) + \floatval( $cashback ) ) );
			$order->save();
		}

		// payment complete
		$order->payment_complete();

		// success
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * @param $order_id
	 */
	public function calculate_cashback( $order_id ): void {
		$message  = '';
		$order    = wc_get_order( $order_id );
		$cashback = $order->get_meta( '_pos_card_cashback' );

		// construct message
		if ( $cashback ) {
			$message = __( 'Cashback', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $cashback );
		}

		echo $message;
	}
}
