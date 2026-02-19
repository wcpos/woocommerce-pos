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
	 * StarPRNT initialize marker.
	 */
	const CMD_INIT = "\x1b\x40";

	/**
	 * StarPRNT center alignment marker.
	 */
	const CMD_ALIGN_CENTER = '[STARPRNT:ALIGN:CENTER]';

	/**
	 * StarPRNT left alignment marker.
	 */
	const CMD_ALIGN_LEFT = '[STARPRNT:ALIGN:LEFT]';

	/**
	 * StarPRNT full cut marker.
	 */
	const CMD_CUT_FULL = '[STARPRNT:CUT:FULL]';

	/**
	 * StarPRNT partial cut marker.
	 */
	const CMD_CUT_PARTIAL = '[STARPRNT:CUT:PARTIAL]';

	/**
	 * StarPRNT drawer marker.
	 */
	const CMD_DRAWER = '[STARPRNT:DRAWER]';

	/**
	 * Transform receipt payload to StarPRNT line format.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$partial_cut = isset( $context['partial_cut'] ) ? (bool) $context['partial_cut'] : false;
		$open_drawer = isset( $context['open_drawer'] ) ? (bool) $context['open_drawer'] : false;
		$print_qr    = isset( $context['print_qr'] ) ? (bool) $context['print_qr'] : false;

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$qr_payload   = isset( $receipt_data['fiscal']['qr_payload'] ) ? (string) $receipt_data['fiscal']['qr_payload'] : '';

		$lines = array(
			self::CMD_INIT,
			self::CMD_ALIGN_CENTER,
			$store_name,
			'RECEIPT',
			self::CMD_ALIGN_LEFT,
			'Order #' . $order_number,
			'Total ' . wc_format_decimal( $total, wc_get_price_decimals() ),
		);

		if ( $print_qr && '' !== $qr_payload ) {
			$lines[] = '[STARPRNT:QR] ' . $qr_payload;
		}
		if ( $open_drawer ) {
			$lines[] = self::CMD_DRAWER;
		}

		$lines[] = $partial_cut ? self::CMD_CUT_PARTIAL : self::CMD_CUT_FULL;

		return implode( "\n", $lines ) . "\n";
	}
}
