<?php
/**
 * Provides a Cash Payment Gateway.
 *
 * @author      Paul Kilmurray <paul@kilbot.com>
 *
 * @see        https://wcpos.com
 *
 * @extends     WC_Payment_Gateway
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Gateways;

use WC_Order;
use WC_Order_Item_Fee;
use WC_Payment_Gateway;

/**
 * Cash class.
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
		$this->supports    = array( 'products', 'refunds' );

		// Actions.
		// @phpstan-ignore-next-line.
		add_action(
			'woocommerce_pos_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);
		add_action( 'woocommerce_thankyou_pos_cash', array( $this, 'calculate_change' ) );
		add_filter( 'wcpos_payment_gateway_provider', array( $this, 'wcpos_provider' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_pos_type', array( $this, 'wcpos_pos_type' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_provider_data', array( $this, 'wcpos_provider_data' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_bootstrap', array( $this, 'wcpos_bootstrap' ), 10, 4 );
		add_filter( 'wcpos_process_checkout_action_' . $this->id, array( $this, 'wcpos_process_checkout_action' ), 10, 5 );
	}

	/**
	 * Get payment details.
	 *
	 * @param WC_Order $order Order object.
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

		if ( 'left' == $currency_pos || 'left_space' == $currency_pos ) {
			$left_addon  = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
			$right_addon = '';
		} else {
			$left_addon  = '';
			$right_addon = '<span class="input-group-addon">' . get_woocommerce_currency_symbol( get_woocommerce_currency() ) . '</span>';
		}

		echo '
		<div class="form-row" id="pos-cash-tendered_field" style="display: flex; justify-content: space-between;">
			<div style="flex: 1;">
				<label for="pos-cash-tendered" style="padding-left:0">' . esc_html__( 'Amount Tendered', 'woocommerce-pos' ) . '</label>
				<div class="input-group">
					' . wp_kses_post( $left_addon ) . '
					<input type="text" class="form-control" name="pos-cash-tendered" id="pos-cash-tendered" maxlength="20" data-numpad="cash" data-label="' . esc_attr__( 'Amount Tendered', 'woocommerce-pos' ) . '" data-placement="bottom" data-value="{{total}}">
					' . wp_kses_post( $right_addon ) . '
				</div>
			</div>
			<div style="flex: 1;">
				<label style="padding-left:0">' . esc_html__( 'Change', 'woocommerce-pos' ) . '</label>
				<div id="pos-cash-change-display"></div>
			</div>';
		wp_nonce_field( 'pos_cash_payment_nonce', 'pos_cash_payment_nonce_field' );
		echo '
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
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string[]
	 */
	public function process_payment( $order_id ): array {
		// Check nonce.
		if ( ! isset( $_POST['pos_cash_payment_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pos_cash_payment_nonce_field'] ) ), 'pos_cash_payment_nonce' ) ) {
			wp_die( esc_html__( 'Nonce verification failed', 'woocommerce-pos' ) );
		}

		// get order object.
		$order = new WC_Order( $order_id );
		$tendered = $order->get_total();

		// get pos_cash data from $_POST.
		if ( isset( $_POST['pos-cash-tendered'] ) && ! empty( $_POST['pos-cash-tendered'] ) ) {
			$tendered = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['pos-cash-tendered'] ) ) );
		}
		$result = $this->apply_tendered_payment( $order, $tendered, true );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );

			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}

		// Return thankyou redirect
		// $redirect = add_query_arg(array(
		// 'wcpos' => 1,
		// ), get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() ));.

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process a refund.
	 *
	 * Cash refunds are handled manually, so just return true.
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
	 * Calculate and display change.
	 *
	 * @param int $order_id Order ID.
	 */
	public function calculate_change( $order_id ): void {
		$message  = '';
		$tendered = get_post_meta( $order_id, '_pos_cash_amount_tendered', true );
		$change   = get_post_meta( $order_id, '_pos_cash_change', true );

		// construct message.
		if ( $tendered && $change ) {
			$message = __( 'Amount Tendered', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $tendered ) . '<br>';
			$message .= _x( 'Change', 'Money returned from cash sale', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $change );
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
	 * Bootstrap response for POS cash.
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
	 * Handle POS checkout contract.
	 *
	 * @param array|\WP_Error $state        Checkout state.
	 * @param string   $action       Checkout action.
	 * @param array    $payment_data Payment data.
	 * @param WC_Order $order      Order object.
	 * @param mixed    $request      REST request.
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

		$tendered = isset( $payment_data['amount_tendered'] ) && '' !== $payment_data['amount_tendered']
			? wc_format_decimal( $payment_data['amount_tendered'] )
			: wc_format_decimal( $order->get_total() );

		$result = $this->apply_tendered_payment( $order, $tendered, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['status']        = 'completed';
		$state['provider_data'] = array(
			'amount_tendered' => $order->get_meta( '_pos_cash_amount_tendered' ),
			'change'          => $order->get_meta( '_pos_cash_change' ),
		);

		return $state;
	}

	/**
	 * Apply a tendered cash amount to an order.
	 *
	 * @param WC_Order $order         Order object.
	 * @param string   $tendered      Tendered amount.
	 * @param bool     $allow_partial Whether partial cash is allowed.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_tendered_payment( WC_Order $order, string $tendered, bool $allow_partial ) {
		$tendered = wc_format_decimal( $tendered );

		if ( (float) $tendered < 0 ) {
			return new \WP_Error(
				'wcpos_invalid_tendered_amount',
				__( 'Tendered amount must be zero or greater.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$change = $tendered > $order->get_total() ? wc_format_decimal( floatval( $tendered ) - floatval( $order->get_total() ) ) : '0';
		$order->set_payment_method( $this->id );
		$order->set_payment_method_title( $this->title );
		$order->update_meta_data( '_pos_cash_amount_tendered', $tendered );
		$order->update_meta_data( '_pos_cash_change', $change );

		if ( $tendered >= $order->get_total() ) {
			$order->payment_complete();
			return true;
		}

		if ( ! $allow_partial ) {
			return new \WP_Error(
				'wcpos_partial_cash_not_supported',
				__( 'Partial cash payments are not supported by the POS API checkout flow.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

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
		$fee->add_meta_data( 'date_paid_gmt', gmdate( 'Y-m-d\TH:i:s' ), true );
		$fee->set_order_id( $order->get_id() );
		$fee->save();

		$order->add_item( $fee );
		$order->set_total( wc_format_decimal( floatval( $order->get_total() ) - floatval( $tendered ) ) );
		$order->update_status( 'wc-pos-partial' );

		return true;
	}
}
