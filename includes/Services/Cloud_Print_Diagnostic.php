<?php
/**
 * Builds cloud-print test/diagnostic payloads per provider.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Diagnostic class.
 */
class Cloud_Print_Diagnostic {
	/**
	 * Build a base64 diagnostic payload + content type for a provider.
	 *
	 * @param string $provider     Provider key.
	 * @param string $printer_name Display name.
	 *
	 * @return array{content_type:string, payload:string}
	 *
	 * @throws \RuntimeException When the provider has no server-side diagnostic (PrintNode: see Phase 4).
	 */
	public function build( string $provider, string $printer_name ): array {
		if ( ! Provider::supports_server_diagnostic( $provider ) ) {
			throw new \RuntimeException( esc_html( 'No server-side diagnostic for provider: ' . $provider ) );
		}

		$date = gmdate( 'Y-m-d H:i' );
		if ( 'epson-sdp' === $provider ) {
			$payload = $this->epos( $printer_name, $date );
		} elseif ( 'star-cloudprnt' === $provider ) {
			$payload = $this->starprnt( $printer_name, $date );
		} else {
			$payload = $this->escpos( $printer_name, $date );
		}

		return array(
			'content_type' => Provider::content_type( $provider ),
			'payload'      => base64_encode( $payload ),
		);
	}

	/**
	 * Build a PrintNode diagnostic receipt as PDF bytes.
	 *
	 * A simple, black-and-white, printer-friendly page used to confirm a PrintNode
	 * printer is wired up correctly. Rendered via the shared PDF renderer.
	 *
	 * @param string $printer_name Display name.
	 *
	 * @return string PDF document bytes (begins with '%PDF-').
	 */
	public function build_pdf( string $printer_name ): string {
		$date = gmdate( 'Y-m-d H:i' );

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8">';
		$html .= '<style>'
			. 'body{font-family:"dejavu sans",sans-serif;color:#000;background:#fff;margin:24px;}'
			. 'h1{font-size:18px;margin:0 0 12px;}'
			. '.row{font-size:13px;margin:4px 0;}'
			. '.label{font-weight:bold;}'
			. 'hr{border:none;border-top:1px solid #000;margin:16px 0;}'
			. '.ok{font-size:14px;font-weight:bold;margin-top:16px;}'
			. '</style></head><body>';
		$html .= '<h1>WCPOS &mdash; Cloud Print Test</h1>';
		$html .= '<div class="row"><span class="label">Printer:</span> ' . esc_html( $printer_name ) . '</div>';
		$html .= '<div class="row"><span class="label">Date (UTC):</span> ' . esc_html( $date ) . '</div>';
		$html .= '<hr>';
		$html .= '<div class="ok">If you can read this, printing works!</div>';
		$html .= '</body></html>';

		return ( new Pdf_Renderer() )->render_html( $html );
	}

	/**
	 * Minimal ESC/POS capability check.
	 *
	 * @param string $name Printer display name.
	 * @param string $date Render date.
	 *
	 * @return string
	 */
	private function escpos( string $name, string $date ): string {
		$esc  = "\x1B@";        // init.
		$esc .= "\x1Ba\x01";    // center.
		$esc .= "WCPOS\n";
		$esc .= "Cloud Print Test\n";
		$esc .= "\x1Ba\x00";    // left.
		$esc .= 'Printer: ' . $name . "\n";
		$esc .= 'Date: ' . $date . "\n";
		$esc .= "If you can read this, printing works!\n\n\n";
		$esc .= "\x1DV\x41\x00"; // full cut.

		return $esc;
	}

	/**
	 * Minimal native StarPRNT capability check.
	 *
	 * StarPRNT-native printers (the whole TSP100 line) cannot decode ESC/POS,
	 * so the Star diagnostic must carry StarPRNT commands. No initialize
	 * command: CloudPRNT jobs must not reset the printer; the job opens with
	 * the UTF-8 encoding select sequence instead.
	 *
	 * @param string $name Printer display name.
	 * @param string $date Render date.
	 *
	 * @return string
	 */
	private function starprnt( string $name, string $date ): string {
		$star  = "\x1B\x1D\x29\x55\x02\x00\x30\x01"; // Select UTF-8 encoding.
		$star .= "\x1B\x1D\x29\x55\x02\x00\x40\x00"; // UTF-8 font setting.
		$star .= "\x1B\x1D\x61\x01";                 // Center align.
		$star .= "WCPOS\n";
		$star .= "Cloud Print Test\n";
		$star .= "\x1B\x1D\x61\x00";                 // Left align.
		$star .= 'Printer: ' . $name . "\n";
		$star .= 'Date: ' . $date . "\n";
		$star .= "If you can read this, printing works!\n\n\n";
		$star .= "\x1B\x64\x03";                     // Feed and partial cut.

		return $star;
	}

	/**
	 * Minimal ePOS-Print XML capability check.
	 *
	 * @param string $name Printer display name.
	 * @param string $date Render date.
	 *
	 * @return string
	 */
	private function epos( string $name, string $date ): string {
		$text  = "WCPOS - Cloud Print Test\n";
		$text .= 'Printer: ' . $name . "\n";
		$text .= 'Date: ' . $date . "\n";
		$text .= "If you can read this, printing works!\n";

		return '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
			. '<text>' . esc_html( $text ) . '</text>'
			. '<cut type="feed"/>'
			. '</epos-print>';
	}
}
