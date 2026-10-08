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
