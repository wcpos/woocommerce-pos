<?php
/**
 * ZPL output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Zpl_Output_Adapter class.
 */
class Zpl_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to a ZPL label payload.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;

		$zpl = array(
			'^XA',
			'^CF0,28',
			'^FO30,30^FDWCPOS RECEIPT^FS',
			'^FO30,75^FDOrder #' . $order_number . '^FS',
			'^FO30,120^FDTotal ' . wc_format_decimal( $total, wc_get_price_decimals() ) . '^FS',
			'^XZ',
		);

		return implode( "\n", $zpl );
	}
}
