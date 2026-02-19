<?php
/**
 * CPCL output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Cpcl_Output_Adapter class.
 */
class Cpcl_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to CPCL print commands.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;

		$cpcl = array(
			'! 0 200 200 500 1',
			'TEXT 4 0 30 30 WCPOS RECEIPT',
			'TEXT 4 0 30 80 Order #' . $order_number,
			'TEXT 4 0 30 130 Total ' . wc_format_decimal( $total, wc_get_price_decimals() ),
			'FORM',
			'PRINT',
		);

		return implode( "\n", $cpcl ) . "\n";
	}
}
