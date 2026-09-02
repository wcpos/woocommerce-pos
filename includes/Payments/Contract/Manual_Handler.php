<?php
/** Manual payment capture handler. */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Describes payments recorded manually at the till. */
class Manual_Handler extends Abstract_Capture_Mode_Handler {
	/** Describe manual capture. */
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
				'refunds'  => array( 'via' => 'manual', 'partial' => true ),
				'tips'     => 'none',
				'offline'  => 'record',
				'void'     => true,
			),
			'provider_data' => array(),
		);
	}
}
