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
		$width         = isset( $context['label_width'] ) ? (int) $context['label_width'] : 576;
		$height        = isset( $context['label_height'] ) ? (int) $context['label_height'] : 700;
		$print_qr      = isset( $context['print_qr'] ) ? (bool) $context['print_qr'] : false;
		$print_barcode = isset( $context['print_barcode'] ) ? (bool) $context['print_barcode'] : true;

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$qr_payload   = isset( $receipt_data['fiscal']['qr_payload'] ) ? (string) $receipt_data['fiscal']['qr_payload'] : '';

		$cpcl = array(
			'! 0 200 200 ' . max( 200, $height ) . ' 1',
			'PW ' . max( 200, $width ),
			'TEXT 4 0 30 20 ' . $this->sanitize_text( $store_name ),
			'TEXT 4 0 30 60 WCPOS RECEIPT',
			'TEXT 4 0 30 80 Order #' . $order_number,
			'TEXT 4 0 30 130 Total ' . wc_format_decimal( $total, wc_get_price_decimals() ),
		);

		if ( $print_barcode ) {
			$cpcl[] = 'BARCODE 128 1 1 60 30 180 ' . $this->sanitize_text( $order_number );
		}
		if ( $print_qr && '' !== $qr_payload ) {
			$cpcl[] = 'B QR 30 260 M 2 U 6';
			$cpcl[] = 'MA,' . $this->sanitize_text( $qr_payload );
			$cpcl[] = 'ENDQR';
		}

		$cpcl[] = 'FORM';
		$cpcl[] = 'PRINT';

		return implode( "\n", $cpcl ) . "\n";
	}

	/**
	 * Sanitize text for CPCL commands.
	 *
	 * @param string $value Text value.
	 *
	 * @return string
	 */
	private function sanitize_text( string $value ): string {
		return trim( preg_replace( '/[\r\n]/', ' ', $value ) );
	}
}
