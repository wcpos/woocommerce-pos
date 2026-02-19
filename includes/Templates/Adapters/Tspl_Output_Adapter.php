<?php
/**
 * TSPL output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Tspl_Output_Adapter class.
 */
class Tspl_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to TSPL/TSPL2 commands.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;

		$tspl = array(
			'SIZE 72 mm,120 mm',
			'GAP 2 mm,0 mm',
			'DENSITY 8',
			'DIRECTION 1',
			'CLS',
			'TEXT 30,30,"0",0,1,1,"WCPOS RECEIPT"',
			'TEXT 30,80,"0",0,1,1,"Order #' . $order_number . '"',
			'TEXT 30,130,"0",0,1,1,"Total ' . wc_format_decimal( $total, wc_get_price_decimals() ) . '"',
			'PRINT 1,1',
		);

		return implode( "\n", $tspl ) . "\n";
	}
}
