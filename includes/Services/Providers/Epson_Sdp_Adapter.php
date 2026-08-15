<?php
/**
 * Epson Server Direct Print provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Provider_Adapter_Interface;

/**
 * Epson_Sdp_Adapter class.
 */
class Epson_Sdp_Adapter implements Provider_Adapter_Interface {
	/**
	 * Return the ePOS-Print XML content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'application/xml';
	}

	/**
	 * Resolve ePOS-Print XML format data.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function format( array $printer, array $template ): array {
		if ( 'thermal' !== (string) ( $template['engine'] ?? '' ) ) {
			return array(
				'kind' => '',
				'content_type' => '',
			);
		}

		return array(
			'kind' => 'epos-xml',
			'content_type' => $this->content_type(),
		);
	}

	/**
	 * Build an ePOS-Print XML diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $printer_name );
		$text = "WCPOS - Cloud Print Test\nPrinter: " . $name . "\n";
		$text .= 'Date: ' . gmdate( 'Y-m-d H:i' ) . "\nIf you can read this, printing works!\n";
		$xml = '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
			. '<text>' . esc_html( $text ) . '</text><cut type="feed"/></epos-print>';

		return array(
			'content_type' => $this->content_type(),
			'payload' => base64_encode( $xml ),
		);
	}

	/**
	 * Resolve polling status.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $context Runtime status context.
	 *
	 * @return string
	 */
	public function status( array $printer, array $context ): string {
		$relay = $context['relay_status'] ?? null;
		if ( \is_array( $relay ) && 'blocked' === ( $relay['origin_status'] ?? '' ) ) {
			return 'blocked';
		}
		if ( \is_array( $relay ) && null !== ( $relay['last_seen_seconds_ago'] ?? null ) && (int) $relay['last_seen_seconds_ago'] <= (int) $context['seen_ttl'] ) {
			return 'connected';
		}

		$seen = (int) ( $context['seen'] ?? 0 );
		if ( 0 === $seen ) {
			return 'waiting';
		}

		return ( (int) $context['now'] - $seen ) <= (int) $context['seen_ttl'] ? 'connected' : 'offline';
	}
}
