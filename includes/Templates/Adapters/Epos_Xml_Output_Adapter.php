<?php
/**
 * Epson ePOS-Print XML output adapter.
 *
 * @package WCPOS\WooCommercePOS\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Templates\Adapters;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Output_Adapter_Interface;

/**
 * Epos_Xml_Output_Adapter class.
 */
class Epos_Xml_Output_Adapter implements Receipt_Output_Adapter_Interface {
	/**
	 * Transform receipt payload to Epson ePOS-Print XML.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$order_number = isset( $receipt_data['order']['number'] ) ? (string) $receipt_data['order']['number'] : '';
		$total        = isset( $receipt_data['totals']['total_incl'] ) ? (float) $receipt_data['totals']['total_incl'] : 0;
		$lines        = array(
			'<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">',
			'<text align="center" em="true">' . $this->escape( $store_name ) . "\n" . '</text>',
			'<text align="center">RECEIPT' . "\n" . '</text>',
			'<text align="left">Order #' . $this->escape( $order_number ) . "\n" . '</text>',
		);

		foreach ( $this->line_items( $receipt_data ) as $item ) {
			$name       = isset( $item['name'] ) ? (string) $item['name'] : '';
			$quantity   = isset( $item['quantity'] ) ? (float) $item['quantity'] : (float) ( $item['qty'] ?? 1 );
			$item_total = isset( $item['total'] ) ? (float) $item['total'] : (float) ( $item['line_total_incl'] ?? 0 );
			$lines[]    = '<text>' . $this->escape( $name ) . ' x' . $this->format_quantity( $quantity ) . ' ' . $this->format_money( $item_total ) . "\n" . '</text>';
		}

		$lines[] = '<text>Total ' . $this->escape( $this->format_money( $total ) ) . "\n" . '</text>';
		$lines[] = '<cut type="feed" />';
		$lines[] = '</epos-print>';

		return implode( '', $lines );
	}

	/**
	 * Read line items from known canonical keys.
	 *
	 * @param array $receipt_data Canonical payload.
	 *
	 * @return array
	 */
	private function line_items( array $receipt_data ): array {
		if ( isset( $receipt_data['lines'] ) && is_array( $receipt_data['lines'] ) ) {
			return $receipt_data['lines'];
		}
		if ( isset( $receipt_data['line_items'] ) && is_array( $receipt_data['line_items'] ) ) {
			return $receipt_data['line_items'];
		}
		if ( isset( $receipt_data['items'] ) && is_array( $receipt_data['items'] ) ) {
			return $receipt_data['items'];
		}

		return array();
	}

	/**
	 * Escape XML text content.
	 *
	 * @param string $value Raw text.
	 *
	 * @return string
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}

	/**
	 * Format an item quantity for receipt output.
	 *
	 * @param float $quantity Quantity.
	 *
	 * @return string
	 */
	private function format_quantity( float $quantity ): string {
		return wc_format_decimal( $quantity, 2 );
	}

	/**
	 * Format a money amount for receipt output.
	 *
	 * @param float $amount Amount.
	 *
	 * @return string
	 */
	private function format_money( float $amount ): string {
		return get_woocommerce_currency() . ' ' . wc_format_decimal( $amount, wc_get_price_decimals() );
	}
}
