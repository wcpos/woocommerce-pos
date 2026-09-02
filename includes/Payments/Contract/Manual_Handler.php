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
				'webview_available' => true,
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
}
