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
use WCPOS\WooCommercePOS\Payments\Abstract_POS_Gateway;
use WP_REST_Request;

/**
 * Cash class.
 */
class Cash extends Abstract_POS_Gateway {
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id          = 'pos_cash';
		$this->title       = /* translators: POS payment gateway label shown during checkout. */ __( 'Cash', 'woocommerce-pos' );
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
		$this->register_pos_gateway_contract_hooks();
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
				<label for="pos-cash-tendered" style="padding-left:0">' . /* translators: Cash checkout field label for the amount of money received from the customer. */ esc_html__( 'Amount Tendered', 'woocommerce-pos' ) . '</label>
				<div class="input-group">
					' . wp_kses_post( $left_addon ) . '
					<input type="text" class="form-control" name="pos-cash-tendered" id="pos-cash-tendered" maxlength="20" data-numpad="cash" data-label="' . /* translators: Cash numpad label for the amount of money received from the customer. */ esc_attr__( 'Amount Tendered', 'woocommerce-pos' ) . '" data-placement="bottom" data-value="{{total}}">
					' . wp_kses_post( $right_addon ) . '
				</div>
			</div>
			<div style="flex: 1;">
				<label style="padding-left:0">' . /* translators: Cash checkout label for money returned to the customer when the received amount exceeds the total. */ esc_html__( 'Change', 'woocommerce-pos' ) . '</label>
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
			wp_die( /* translators: Checkout error shown when cash nonce validation fails. */ esc_html__( 'Nonce verification failed', 'woocommerce-pos' ) );
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
			$message = /* translators: Order note label for the cash amount received from the customer at checkout. */ __( 'Amount Tendered', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $tendered ) . '<br>';
			$message .= /* translators: Money returned to the customer after a cash payment. */ _x( 'Change', 'Money returned from cash sale', 'woocommerce-pos' ) . ': ';
			$message .= wc_price( $change );
		}

		echo wp_kses_post( $message );
	}

	/**
	 * Provider family identifier.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider( ?WP_REST_Request $request = null ): string {
		return 'wcpos';
	}

	/**
	 * Process a POS checkout action for cash.
	 *
	 * @param array                $state        Checkout state.
	 * @param string               $action       Checkout action.
	 * @param array                $payment_data Payment data.
	 * @param WC_Order             $order        Order object.
	 * @param WP_REST_Request|null $request      Request object.
	 *
	 * @return array|\WP_Error
	 */
	public function process_pos_checkout_action( array $state, string $action, array $payment_data, WC_Order $order, ?WP_REST_Request $request = null ) {
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
				/* translators: Checkout validation error for the cash amount received from the customer. */
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
				'name'      => /* translators: Fee line-item name added when a cash payment is partial. */ __( 'Partial Payment', 'woocommerce-pos' ),
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
