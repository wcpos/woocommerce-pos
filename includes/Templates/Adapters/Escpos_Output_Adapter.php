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
	 * ESC a 0 align left.
	 */
	const ALIGN_LEFT = "\x1Ba\x00";

	/**
	 * ESC a 1 align center.
	 */
	const ALIGN_CENTER = "\x1Ba\x01";

	/**
	 * ESC a 2 align right.
	 */
	const ALIGN_RIGHT = "\x1Ba\x02";

	/**
	 * ESC E 1 bold on.
	 */
	const BOLD_ON = "\x1BE\x01";

	/**
	 * ESC E 0 bold off.
	 */
	const BOLD_OFF = "\x1BE\x00";

	/**
	 * ESC t n select code page.
	 */
	const CODEPAGE_PREFIX = "\x1Bt";

	/**
	 * GS V A 1 partial cut command.
	 */
	const CUT_PARTIAL = "\x1DV\x41\x01";

	/**
	 * GS V A 0 full cut command.
	 */
	const CUT_FULL = "\x1DV\x41\x00";

	/**
	 * ESC p m t1 t2 kick cash drawer command.
	 */
	const DRAWER_KICK = "\x1Bp\x00\x19\xFA";

	/**
	 * Line feed.
	 */
	const LF = "\n";

	/**
	 * Transform receipt payload to ESC/POS command stream.
	 *
	 * @param array $receipt_data Canonical payload.
	 * @param array $context      Optional context.
	 *
	 * @return string
	 */
	public function transform( array $receipt_data, array $context = array() ): string {
		$paper_width   = isset( $context['paper_width_chars'] ) ? (int) $context['paper_width_chars'] : 48;
		$paper_width   = $paper_width > 0 ? $paper_width : 48;
		$codepage      = isset( $context['codepage'] ) ? (int) $context['codepage'] : 0;
		$cut           = isset( $context['cut'] ) ? (bool) $context['cut'] : true;
		$partial_cut   = isset( $context['partial_cut'] ) ? (bool) $context['partial_cut'] : false;
		$open_drawer   = isset( $context['open_drawer'] ) ? (bool) $context['open_drawer'] : false;
		$print_qr      = isset( $context['print_qr'] ) ? (bool) $context['print_qr'] : false;

		$order_number = isset( $receipt_data['meta']['order_number'] ) ? (string) $receipt_data['meta']['order_number'] : '';
		$total        = isset( $receipt_data['totals']['grand_total_incl'] ) ? (float) $receipt_data['totals']['grand_total_incl'] : 0;
		$currency     = isset( $receipt_data['meta']['currency'] ) ? (string) $receipt_data['meta']['currency'] : get_woocommerce_currency();
		$store_name   = isset( $receipt_data['store']['name'] ) ? (string) $receipt_data['store']['name'] : get_bloginfo( 'name' );
		$created_at   = isset( $receipt_data['meta']['created_at_gmt'] ) ? (string) $receipt_data['meta']['created_at_gmt'] : '';

		$output_lines = array(
			self::ALIGN_CENTER . self::BOLD_ON . $this->fit_text( $store_name, $paper_width ) . self::LF . self::BOLD_OFF,
			$this->fit_text( 'Receipt', $paper_width ) . self::LF,
			self::ALIGN_LEFT,
			$this->fit_text( 'Order #' . $order_number, $paper_width ) . self::LF,
		);

		if ( '' !== $created_at ) {
			$output_lines[] = $this->fit_text( 'Created: ' . $created_at, $paper_width ) . self::LF;
		}

		$output_lines[] = str_repeat( '-', $paper_width ) . self::LF;

		if ( isset( $receipt_data['lines'] ) && \is_array( $receipt_data['lines'] ) ) {
			foreach ( $receipt_data['lines'] as $line ) {
				$name       = isset( $line['name'] ) ? (string) $line['name'] : '';
				$qty        = isset( $line['qty'] ) ? (float) $line['qty'] : 0;
				$line_total = isset( $line['line_total_incl'] ) ? (float) $line['line_total_incl'] : 0;

				$output_lines[] = $this->fit_text( $name, $paper_width ) . self::LF;
				$output_lines[] = $this->two_column_line(
					'  x' . wc_format_decimal( $qty, 2 ),
					$this->format_money( $line_total, $currency ),
					$paper_width
				) . self::LF;
			}
		}

		$output_lines[] = str_repeat( '-', $paper_width ) . self::LF;
		$output_lines[] = self::BOLD_ON . $this->two_column_line(
			'TOTAL',
			$this->format_money( $total, $currency ),
			$paper_width
		) . self::BOLD_OFF . self::LF;
		$output_lines[] = self::LF;

		if ( $print_qr ) {
			$qr_payload = isset( $receipt_data['fiscal']['qr_payload'] ) ? (string) $receipt_data['fiscal']['qr_payload'] : '';
			if ( '' !== $qr_payload ) {
				$output_lines[] = self::ALIGN_CENTER . '[QR] ' . $this->fit_text( $qr_payload, $paper_width ) . self::LF;
				$output_lines[] = self::ALIGN_LEFT;
			}
		}

		$output = self::ESC_INIT;
		$output .= self::CODEPAGE_PREFIX . chr( max( 0, min( $codepage, 255 ) ) );
		$output .= implode( '', $output_lines );

		if ( $open_drawer ) {
			$output .= self::DRAWER_KICK;
		}

		if ( $cut ) {
			$output .= $partial_cut ? self::CUT_PARTIAL : self::CUT_FULL;
		}

		return $output;
	}

	/**
	 * Format money for thermal output.
	 *
	 * @param float  $value    Money value.
	 * @param string $currency Currency code.
	 *
	 * @return string
	 */
	private function format_money( float $value, string $currency ): string {
		return trim( $currency . ' ' . wc_format_decimal( $value, wc_get_price_decimals() ) );
	}

	/**
	 * Build a two-column thermal line.
	 *
	 * @param string $left       Left text.
	 * @param string $right      Right text.
	 * @param int    $line_width Line width in characters.
	 *
	 * @return string
	 */
	private function two_column_line( string $left, string $right, int $line_width ): string {
		$left  = $this->fit_text( $left, $line_width );
		$right = $this->fit_text( $right, $line_width );

		$available = $line_width - strlen( $right ) - 1;
		if ( $available < 1 ) {
			return substr( $left, 0, $line_width );
		}

		$left  = $this->fit_text( $left, $available );
		$space = str_repeat( ' ', max( 1, $line_width - strlen( $left ) - strlen( $right ) ) );

		return $left . $space . $right;
	}

	/**
	 * Fit text to thermal line width.
	 *
	 * @param string $text       Text value.
	 * @param int    $line_width Line width in characters.
	 *
	 * @return string
	 */
	private function fit_text( string $text, int $line_width ): string {
		$sanitized = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $sanitized ) <= $line_width ) {
			return $sanitized;
		}

		return substr( $sanitized, 0, max( 0, $line_width - 1 ) ) . '…';
	}
}
