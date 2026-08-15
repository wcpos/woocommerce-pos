<?php
/**
 * Star CloudPRNT provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Provider_Adapter_Interface;

/**
 * Star_Cloudprnt_Adapter class.
 */
class Star_Cloudprnt_Adapter implements Provider_Adapter_Interface {
	/**
	 * Return the StarPRNT content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'application/vnd.star.starprnt';
	}

	/**
	 * Resolve StarPRNT format data.
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
			'kind' => 'starprnt',
			'content_type' => $this->content_type(),
		);
	}

	/**
	 * Build a native StarPRNT diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$name  = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $printer_name );
		$date  = gmdate( 'Y-m-d H:i' );
		$bytes = "\x1B\x1D\x29\x55\x02\x00\x30\x01";
		$bytes .= "\x1B\x1D\x29\x55\x02\x00\x40\x00";
		$bytes .= "\x1B\x1D\x61\x01WCPOS\nCloud Print Test\n\x1B\x1D\x61\x00";
		$bytes .= 'Printer: ' . $name . "\nDate: " . $date . "\nIf you can read this, printing works!\n\n\n";
		$bytes .= "\x1B\x64\x03";

		return array(
			'content_type' => $this->content_type(),
			'payload' => base64_encode( $bytes ),
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
