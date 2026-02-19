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
		$width        = isset( $context['label_width'] ) ? (int) $context['label_width'] : 812;
		$length       = isset( $context['label_length'] ) ? (int) $context['label_length'] : 1218;
		$print_qr     = isset( $context['print_qr'] ) ? (bool) $context['print_qr'] : false;
		$print_barcode = isset( $context['print_barcode'] ) ? (bool) $context['print_barcode'] : true;

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$qr_payload   = isset( $receipt_data['fiscal']['qr_payload'] ) ? (string) $receipt_data['fiscal']['qr_payload'] : '';

		$zpl = array(
			'^XA',
			'^PW' . max( 200, $width ),
			'^LL' . max( 300, $length ),
			'^CF0,28',
			'^FO30,30^FD' . $this->sanitize_zpl_text( $store_name ) . '^FS',
			'^FO30,65^FDWCPOS RECEIPT^FS',
			'^FO30,75^FDOrder #' . $order_number . '^FS',
			'^FO30,120^FDTotal ' . wc_format_decimal( $total, wc_get_price_decimals() ) . '^FS',
		);

		if ( $print_barcode ) {
			$zpl[] = '^BY2,2,60';
			$zpl[] = '^FO30,170^BCN,60,Y,N,N';
			$zpl[] = '^FD' . $this->sanitize_zpl_text( $order_number ) . '^FS';
		}
		if ( $print_qr && '' !== $qr_payload ) {
			$zpl[] = '^FO30,260^BQN,2,6';
			$zpl[] = '^FDLA,' . $this->sanitize_zpl_text( $qr_payload ) . '^FS';
		}

		$zpl[] = '^XZ';

		return implode( "\n", $zpl );
	}

	/**
	 * Sanitize text for ZPL fields.
	 *
	 * @param string $value Text value.
	 *
	 * @return string
	 */
	private function sanitize_zpl_text( string $value ): string {
		$value = preg_replace( '/[\^~]/', '-', $value );

		return trim( $value );
	}
}
