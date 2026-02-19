<?php
/**
 * ESC/POS output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Escpos_Output_Adapter class.
 */
class Escpos_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * ESC @ initialize printer command.
	 */
	const ESC_INIT = "\x1B@";

	/**
	 * GS V A 0 full cut command.
	 */
	const CUT_FULL = "\x1DV\x41\x00";

	/**
	 * Transform receipt payload to ESC/POS command stream.
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
			'STORE RECEIPT',
			'Order #' . $order_number,
			'Total: ' . wc_format_decimal( $total, wc_get_price_decimals() ),
		);

		if ( isset( $receipt_data['lines'] ) && \is_array( $receipt_data['lines'] ) ) {
			foreach ( $receipt_data['lines'] as $line ) {
				$name      = isset( $line['name'] ) ? (string) $line['name'] : '';
				$qty       = isset( $line['qty'] ) ? (float) $line['qty'] : 0;
				$line_total = isset( $line['line_total_incl'] ) ? (float) $line['line_total_incl'] : 0;
				$lines[]   = sprintf( '%s x%s %s', $name, wc_format_decimal( $qty, 2 ), wc_format_decimal( $line_total, 2 ) );
			}
		}

		$payload = implode( "\n", $lines ) . "\n\n";

		return self::ESC_INIT . $payload . self::CUT_FULL;
	}
}
