<?php
/**
 * Print format resolver.
 *
 * Single source of truth for the wire format a print job uses, given a
 * printer and a template. PrintNode chooses between PDF and raw ESC/POS based
 * on the printer's configured format; other providers delegate to Provider.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Print_Format_Resolver class.
 */
class Print_Format_Resolver {
	/**
	 * Resolve the wire format and HTTP content type for a print job.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function resolve( array $printer, array $template ): array {
		$provider = (string) ( $printer['provider'] ?? '' );
		$engine   = (string) ( $template['engine'] ?? '' );

		if ( 'printnode' === $provider ) {
			if ( 'thermal' !== $engine ) {
				return array(
					'kind' => 'pdf',
					'content_type' => 'application/pdf',
				);
			}

			$format = (string) ( $printer['printnode_format'] ?? 'pdf' );
			if ( 'raw' === $format ) {
				return array(
					'kind' => 'escpos',
					'content_type' => 'application/octet-stream',
				);
			}

			return array(
				'kind' => 'pdf',
				'content_type' => 'application/pdf',
			);
		}

		$wire = Provider::wire_format( $provider, $engine );
		if ( null === $wire ) {
			return array(
				'kind' => '',
				'content_type' => '',
			);
		}

		return array(
			'kind' => $wire,
			'content_type' => Provider::content_type( $provider ),
		);
	}

	/**
	 * Resolve the HTTP content type for a printer when no template is in hand.
	 *
	 * Two callers have a printer but no loaded template: the diagnostic builder
	 * (its payload is hand-built, not rendered from a template) and the reprint
	 * path (it copies a job's template id without loading the template). Both
	 * get the provider's declared type.
	 *
	 * PrintNode therefore reports its PDF default here even for a printer in raw
	 * mode, unlike resolve(), which sees the engine and can honour
	 * `printnode_format`. That asymmetry is pre-existing and deliberate: it is
	 * inert because PrintNode submissions choose their wire from the job's
	 * `pn_kind` (see Cloud_Print_Submit_Service), never from this value.
	 * Collapsing the two answers waits on the mediaTypes negotiation work
	 * (issue #1351).
	 *
	 * @param array $printer Printer configuration.
	 *
	 * @return string
	 */
	public function content_type_for_printer( array $printer ): string {
		return Provider::content_type( (string) ( $printer['provider'] ?? '' ) );
	}
}
