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
		$size_width_mm = isset( $context['label_width_mm'] ) ? (int) $context['label_width_mm'] : 72;
		$size_height_mm = isset( $context['label_height_mm'] ) ? (int) $context['label_height_mm'] : 120;
		$print_qr      = isset( $context['print_qr'] ) ? (bool) $context['print_qr'] : false;
		$print_barcode = isset( $context['print_barcode'] ) ? (bool) $context['print_barcode'] : true;

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$qr_payload   = isset( $receipt_data['fiscal']['qr_payload'] ) ? (string) $receipt_data['fiscal']['qr_payload'] : '';

		$tspl = array(
			'SIZE ' . max( 30, $size_width_mm ) . ' mm,' . max( 30, $size_height_mm ) . ' mm',
			'GAP 2 mm,0 mm',
			'DENSITY 8',
			'DIRECTION 1',
			'CLS',
			'TEXT 30,30,"0",0,1,1,"' . $this->sanitize_text( $store_name ) . '"',
			'TEXT 30,55,"0",0,1,1,"WCPOS RECEIPT"',
			'TEXT 30,80,"0",0,1,1,"Order #' . $order_number . '"',
			'TEXT 30,130,"0",0,1,1,"Total ' . wc_format_decimal( $total, wc_get_price_decimals() ) . '"',
		);

		if ( $print_barcode ) {
			$tspl[] = 'BARCODE 30,180,"128",80,1,0,2,2,"' . $this->sanitize_text( $order_number ) . '"';
		}
		if ( $print_qr && '' !== $qr_payload ) {
			$tspl[] = 'QRCODE 30,290,L,6,A,0,M2,S7,"' . $this->sanitize_text( $qr_payload ) . '"';
		}

		$tspl[] = 'PRINT 1,1';

		return implode( "\n", $tspl ) . "\n";
	}

	/**
	 * Sanitize text for TSPL commands.
	 *
	 * @param string $value Text value.
	 *
	 * @return string
	 */
	private function sanitize_text( string $value ): string {
		$value = preg_replace( '/["\r\n]/', ' ', $value );

		return trim( $value );
	}
}
