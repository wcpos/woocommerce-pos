<?php
/**
 * StarPRNT output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Starprnt_Output_Adapter class.
 */
class Starprnt_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to StarPRNT line format.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;

		$lines = array(
			'[STARPRNT] RECEIPT',
			'Order #' . $order_number,
			'Total ' . wc_format_decimal( $total, wc_get_price_decimals() ),
			'[STARPRNT] CUT',
		);

		return implode( "\n", $lines ) . "\n";
	}
}
