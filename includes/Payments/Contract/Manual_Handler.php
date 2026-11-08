<?php
/**
 * Manual payment capture handler.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Describes payments recorded manually at the till. */
class Manual_Handler extends Abstract_Capture_Mode_Handler {
	/**
	 * Describe manual capture.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array(
			'capture'      => array(
				'mode'              => 'manual',
				'provider'          => null,
				'hardware'          => null,
				// The built-in Cash gateway is taken at the till through the ledger, never on the
				// order-pay page (free#382, ruled 2026-11-08). By id: COD also resolves to kind cash and stays.
				'webview_available' => 'pos_cash' !== $gateway->id,
			),
			'capabilities' => array(
				'amount'   => array( 'partial' => true ),
				'change'   => 'cash' === Descriptor_Builder::resolve_kind( $gateway ),
				'refunds'  => array(
					'via' => 'manual',
					'partial' => true,
				),
				'tips'     => 'none',
				'offline'  => 'record',
				'void'     => true,
			),
			'provider_data' => array(),
		);
	}
	/**
	 * Cash handed back is immediately refunded.
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund ID.
	 * @param string $amount    Refund amount.
	 * @return array
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		$row['refunds'][] = array(
			'id' => $refund_id,
			'amount' => $amount,
			'status' => 'succeeded',
			'provider_ref' => null,
		);
		return $row;
	}
}
