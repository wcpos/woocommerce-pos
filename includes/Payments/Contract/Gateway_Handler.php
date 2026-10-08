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
		$ref     = null;
		if ( $gateway && $gateway->supports( 'refunds' ) ) {
			$refund = $refund_id > 0 ? wc_get_order( $refund_id ) : null;
			$reason = $refund instanceof \WC_Order_Refund ? $refund->get_reason() : '';
			$result = $gateway->process_refund( (int) ( $row['order_id'] ?? 0 ), (float) $amount, $reason );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'wcpos_provider_error', $result->get_error_message(), array( 'status' => 502 ) );
			}
			if ( true !== $result ) {
				return new \WP_Error( 'wcpos_provider_error', __( 'The payment provider did not refund this payment.', 'woocommerce-pos' ), array( 'status' => 502 ) );
			}
			$ref = wc_get_order( (int) ( $row['order_id'] ?? 0 ) );
			$ref = $ref ? ( '' !== $ref->get_transaction_id() ? $ref->get_transaction_id() : null ) : null;
		}
		$row['refunds'][] = array(
			'id'           => $refund_id,
			'amount'       => $amount,
			'status'       => 'succeeded',
			'provider_ref' => $ref,
		);
		return $row;
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
