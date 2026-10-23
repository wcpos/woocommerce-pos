<?php
/**
 * Gateway payment capture handler.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Describes full-order capture through the gateway's own PHP. */
class Gateway_Handler extends Abstract_Capture_Mode_Handler {
	/**
	 * Describe gateway capture.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$supports_refunds = (bool) $gateway->supports( 'refunds' );

		return array(
			'capture'      => array(
				'mode'              => 'gateway',
				'provider'          => null,
				'hardware'          => null,
				'webview_available' => true,
			),
			'capabilities' => array(
				'amount'   => array( 'partial' => false ),
				'change'   => false,
				'refunds'  => array(
					'via'     => $supports_refunds ? 'provider' : 'manual',
					'partial' => $supports_refunds,
				),
				'tips'     => 'none',
				'offline'  => 'none',
				'void'     => false,
			),
			'provider_data' => array(),
		);
	}

	/**
	 * Refund a recorded row the way the descriptor advertised: through the gateway's own
	 * process_refund() when it supports refunds, else marked succeeded at once (handed back
	 * by hand, as the manual handler does).
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund ID.
	 * @param string $amount    Refund amount.
	 *
	 * @return array|\WP_Error
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		$gateway = Descriptor_Builder::instance()->gateway( (string) ( $row['method_id'] ?? '' ) );
		if ( ! $gateway ) {
			// The plugin that took the money is gone; nobody can give it back from here.
			return new \WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( $gateway->supports( 'refunds' ) ) {
			$refund = $refund_id > 0 ? wc_get_order( $refund_id ) : null;
			$reason = $refund instanceof \WC_Order_Refund ? $refund->get_reason() : '';
			$result = $gateway->process_refund( (int) ( $row['order_id'] ?? 0 ), (float) $amount, $reason );
			if ( is_wp_error( $result ) ) {
				// A gateway that cannot tell whether it refunded says so; the ledger then keeps the reservation (rule 8).
				$data = $result->get_error_data();
				$flag = is_array( $data ) && true === ( $data['indeterminate'] ?? false ) ? array( 'indeterminate' => true ) : array();
				return new \WP_Error( 'wcpos_provider_error', $result->get_error_message(), array_merge( array( 'status' => 502 ), $flag ) );
			}
			// wc_refund_payment() treats any truthy result as success; so does this.
			if ( ! $result ) {
				return new \WP_Error( 'wcpos_provider_error', __( 'The payment provider did not refund this payment.', 'woocommerce-pos' ), array( 'status' => 502 ) );
			}
		}
		// process_refund() answers a boolean, never a refund reference, so none is recorded.
		$row['refunds'][] = array(
			'id'           => $refund_id,
			'amount'       => $amount,
			'status'       => 'succeeded',
			'provider_ref' => null,
		);
		return $row;
	}

	/**
	 * What became of a gateway attempt, read from the order (the gateway's PHP has no status API; `Attempts::reconcile()`
	 * asks with the attempt's pseudo row): `captured` for the order total, with its transaction id, only when the order
	 * is paid, through the attempt's method, and its counting rows do not cover it. Anything else, and any row that is
	 * not a gateway attempt, is answered unchanged: this provider cannot say.
	 *
	 * @param array $row Payment row, or an attempt's `{ id, status, capture_mode, method_id, order_id }`.
	 *
	 * @return array|\WP_Error
	 */
	public function status( array $row ) {
		$order = 'gateway' === ( $row['capture_mode'] ?? '' ) ? wc_get_order( (int) ( $row['order_id'] ?? 0 ) ) : null;
		if ( ! $order instanceof \WC_Order || ( $row['method_id'] ?? null ) !== $order->get_payment_method() || Money::minor( $order->get_total() ) <= 0
			|| ! ( Completion_Stamp::is_paid( $order ) || null !== $order->get_date_paid( 'edit' ) )
			|| Money::minor( Ledger::instance()->paid( Ledger::instance()->read( $order ) ) ) >= Money::minor( $order->get_total() ) ) {
			return $row;
		}
		$transaction = $order->get_transaction_id();
		return array_merge(
			$row,
			array(
				'status'        => 'captured',
				'amount'        => Money::normalize( $order->get_total() ),
				'provider_refs' => array( 'transaction_id' => '' !== $transaction ? $transaction : null ),
			)
		);
	}

	/**
	 * Gateway payments cannot be voided through the contract.
	 *
	 * @param array  $row    Payment row.
	 * @param string $reason Void reason.
	 *
	 * @return array|\WP_Error
	 */
	public function void( array $row, string $reason ) {
		return new \WP_Error(
			'wcpos_invalid_transition',
			__( 'This payment cannot be voided.', 'woocommerce-pos' ),
			array( 'status' => 409 )
		);
	}
}
