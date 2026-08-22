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
	 * Two callers have a printer but no template to resolve against: the
	 * diagnostic builder (its payload is hand-built, not rendered from a
	 * template) and the reprint path, when the source job's template has since
	 * been deleted or can no longer be rendered on the printer. Both get the
	 * provider's declared type.
	 *
	 * PrintNode therefore reports its PDF default here even for a printer in raw
	 * mode, unlike resolve(), which sees the engine and can honour
	 * `printnode_format`. Neither caller can act on the difference: the
	 * diagnostic builder throws for PrintNode before it gets here, and the
	 * reprint path only consults this method for jobs that carry no `pn_kind`
	 * to contradict. Prefer resolve() wherever a template is in hand — it
	 * answers both halves of the pairing at once.
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
