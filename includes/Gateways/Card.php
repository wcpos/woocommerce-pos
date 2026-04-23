<?php
/**
 * Provides a Card Payment Gateway.
 *
 * @author      Paul Kilmurray <paul@kilbot.com>
 *
 * @see        https://wcpos.com
 *
 * @extends     WC_Payment_Gateway
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Gateways;

use WC_Payment_Gateway;

/**
 * Card class.
 */
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
		$this->supports    = array( 'products', 'refunds' );

		// Actions.
		// @phpstan-ignore-next-line -- Action hook type mismatch.
		add_action(
			'woocommerce_pos_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);
		add_action( 'woocommerce_thankyou_pos_card', array( $this, 'calculate_cashback' ) );
		add_filter( 'wcpos_payment_gateway_provider', array( $this, 'wcpos_provider' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_pos_type', array( $this, 'wcpos_pos_type' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_provider_data', array( $this, 'wcpos_provider_data' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_bootstrap', array( $this, 'wcpos_bootstrap' ), 10, 4 );
		add_filter( 'wcpos_process_checkout_action_' . $this->id, array( $this, 'wcpos_process_checkout_action' ), 10, 5 );
	}

	/**
	 * Display the payment fields in the checkout.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		$currency_pos = get_option( 'woocommerce_currency_pos' );

		if ( 'left' == $currency_pos || 'left_space' == $currency_pos ) {
			$left_addon  = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
			$right_addon = '';
		} else {
			$left_addon  = '';
			$right_addon = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
		}

		echo '
      <div class="form-row " id="pos-cashback_field">
        <label for="pos-cashback" class="">' . esc_html__( 'Cashback', 'woocommerce-pos' ) . '</label>
        <div class="input-group">
        ' . wp_kses_post( $left_addon ) . '
          <input type="text" class="form-control" name="pos-cashback" id="pos-cashback" placeholder="" maxlength="20" data-value="0" data-numpad="cash" data-label="' . esc_attr__( 'Cashback', 'woocommerce-pos' ) . '">
        ' . wp_kses_post( $right_addon ) . '
        </div>';
		wp_nonce_field( 'pos_card_payment_nonce', 'pos_card_payment_nonce_field' );
		echo '
      </div>
    ';
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		// Check nonce.
		if ( ! isset( $_POST['pos_card_payment_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pos_card_payment_nonce_field'] ) ), 'pos_card_payment_nonce' ) ) {
			wp_die( esc_html__( 'Nonce verification failed', 'woocommerce-pos' ) );
		}

		// get order object.
		$order = wc_get_order( $order_id );

		$cashback = 0; // Initialize with default value.
		if ( isset( $_POST['pos-cashback'] ) && ! empty( $_POST['pos-cashback'] ) ) {
			$cashback = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['pos-cashback'] ) ) );
		}

		$result = $this->apply_card_payment( $order, $cashback );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );

			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}

		// success.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process a refund.
	 *
	 * Card refunds are handled manually, so just return true.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 *
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return true;
	}

	/**
	 * Calculate and display cashback.
	 *
	 * @param int $order_id Order ID.
	 */
	public function calculate_cashback( $order_id ): void {
		$message  = '';
		$order    = wc_get_order( $order_id );
		$cashback = $order->get_meta( '_pos_card_cashback' );

		// construct message.
		if ( $cashback ) {
			$message = __( 'Cashback', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $cashback );
		}

		echo wp_kses_post( $message );
	}

	/**
	 * POS provider family.
	 *
	 * @param string $provider Provider value.
	 * @param mixed  $gateway  Gateway object.
	 * @param mixed  $request  REST request.
	 */
	public function wcpos_provider( $provider, $gateway, $request = null ) {
		if ( $gateway instanceof WC_Payment_Gateway && $this->id === $gateway->id ) {
			return 'wcpos';
		}

		return $provider;
	}

	/**
	 * POS type.
	 *
	 * @param string $pos_type POS type.
	 * @param mixed  $gateway  Gateway object.
	 * @param mixed  $request  REST request.
	 */
	public function wcpos_pos_type( $pos_type, $gateway, $request = null ) {
		if ( $gateway instanceof WC_Payment_Gateway && $this->id === $gateway->id ) {
			return 'manual';
		}

		return $pos_type;
	}

	/**
	 * Non-secret provider data.
	 *
	 * @param array $provider_data Provider data.
	 * @param mixed $gateway       Gateway object.
	 * @param mixed $request       REST request.
	 */
	public function wcpos_provider_data( $provider_data, $gateway, $request = null ) {
		if ( $gateway instanceof WC_Payment_Gateway && $this->id === $gateway->id ) {
			return array();
		}

		return $provider_data;
	}

	/**
	 * Bootstrap response for POS card.
	 *
	 * @param array  $response   Bootstrap response.
	 * @param string $gateway_id Gateway ID.
	 * @param array  $context    Bootstrap context.
	 * @param mixed  $request    REST request.
	 */
	public function wcpos_bootstrap( $response, $gateway_id, $context = array(), $request = null ) {
		if ( $this->id === $gateway_id ) {
			return array(
				'gateway_id'    => $gateway_id,
				'status'        => 'ready',
				'expires_at'    => null,
				'provider_data' => array(),
			);
		}

		return $response;
	}

	/**
	 * Handle POS checkout contract for card.
	 *
	 * @param array|\WP_Error $state        Checkout state.
	 * @param string    $action       Checkout action.
	 * @param array     $payment_data Payment data.
	 * @param \WC_Order $order       Order object.
	 * @param mixed     $request      REST request.
	 */
	public function wcpos_process_checkout_action( $state, $action, $payment_data, $order, $request = null ) {
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ( $state['gateway_id'] ?? '' ) !== $this->id ) {
			return $state;
		}

		if ( 'cancel' === $action ) {
			$state['status'] = 'cancelled';
			return $state;
		}

		if ( 'start' !== $action ) {
			return $state;
		}

		$cashback = isset( $payment_data['cashback_amount'] ) && '' !== $payment_data['cashback_amount']
			? wc_format_decimal( $payment_data['cashback_amount'] )
			: 0;

		$result = $this->apply_card_payment( $order, $cashback );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['status']        = 'completed';
		$state['provider_data'] = array(
			'cashback' => $order->get_meta( '_pos_card_cashback' ),
		);

		return $state;
	}

	/**
	 * Apply card payment changes to an order.
	 *
	 * @param \WC_Order        $order    Order object.
	 * @param float|int|string $cashback Cashback amount.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_card_payment( $order, $cashback ) {
		$cashback = wc_format_decimal( $cashback );

		if ( (float) $cashback < 0 ) {
			return new \WP_Error(
				'wcpos_invalid_cashback_amount',
				__( 'Cashback amount must be zero or greater.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$order->set_payment_method( $this->id );
		$order->set_payment_method_title( $this->title );

		if ( 0 !== $cashback && '0' !== (string) $cashback ) {
			$order->update_meta_data( '_pos_card_cashback', $cashback );

			$item_id = wc_add_order_item(
				$order->get_id(),
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

			$order->set_total( wc_format_decimal( \floatval( $order->get_total() ) + \floatval( $cashback ) ) );
		}

		$order->payment_complete();

		return true;
	}
}
