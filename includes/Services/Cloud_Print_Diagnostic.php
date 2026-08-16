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
	 * @throws \RuntimeException When the provider has no server-side diagnostic.
	 */
	public function build( string $provider, string $printer_name ): array {
		if ( ! Provider::supports_server_diagnostic( $provider ) ) {
			throw new \RuntimeException( esc_html( 'No server-side diagnostic for provider: ' . $provider ) );
		}

		$adapter = Provider::adapter( $provider );
		if ( null === $adapter ) {
			throw new \RuntimeException( esc_html( 'Unknown cloud-print provider: ' . $provider ) );
		}

		return $adapter->diagnostic( $printer_name );
	}

	/**
	 * Build a PrintNode diagnostic receipt as PDF bytes.
	 *
	 * @param string $printer_name Display name.
	 *
	 * @throws \RuntimeException When the PrintNode adapter cannot be resolved.
	 *
	 * @return string PDF document bytes.
	 */
	public function build_pdf( string $printer_name ): string {
		$adapter = Provider::adapter( 'printnode' );
		if ( null === $adapter ) {
			throw new \RuntimeException( esc_html( 'Unknown cloud-print provider: printnode' ) );
		}

		return base64_decode( $adapter->diagnostic( $printer_name )['payload'], true );
	}

	/**
	 * Build a Star Document Markup diagnostic receipt.
	 *
	 * @param string $printer_name Display name.
	 *
	 * @throws \RuntimeException When the Star Online adapter cannot be resolved.
	 *
	 * @return string Star Document Markup source.
	 */
	public function star_markup( string $printer_name ): string {
		$adapter = Provider::adapter( 'star-online' );
		if ( null === $adapter ) {
			throw new \RuntimeException( esc_html( 'Unknown cloud-print provider: star-online' ) );
		}

		return base64_decode( $adapter->diagnostic( $printer_name )['payload'], true );
	}
}
