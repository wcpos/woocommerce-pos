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
		// Printer rows saved before the provider field existed have none; they
		// must behave as the default provider, not fall through every branch.
		$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );
		$adapter  = Provider::adapter( $provider );
		if ( null === $adapter ) {
			return array(
				'kind' => '',
				'content_type' => '',
			);
		}

		return $adapter->format( $printer, $template );
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
	 * `printnode_format`. Only the reprint path can actually reach that case —
	 * the diagnostic builder throws for PrintNode before it gets here. The
	 * asymmetry is pre-existing and, as far as we can tell, harmless: PrintNode
	 * submissions choose their wire from the job's `pn_kind` (see
	 * Cloud_Print_Submit_Service), never from this value, so a reprinted
	 * raw-mode job prints correctly even though its stored content type reads
	 * `application/pdf` next to `pn_kind` of `escpos`. Collapsing the two
	 * answers waits on the mediaTypes negotiation work (issue #1351).
	 *
	 * @param array $printer Printer configuration.
	 *
	 * @return string
	 */
	public function content_type_for_printer( array $printer ): string {
		$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );
		$adapter  = Provider::adapter( $provider );

		return null === $adapter ? 'application/octet-stream' : $adapter->content_type();
	}
}
