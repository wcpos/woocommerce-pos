<?php
/**
 * Cloud-print provider adapter interface.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

namespace WCPOS\WooCommercePOS\Interfaces;

/**
 * Provider_Adapter_Interface interface.
 */
interface Provider_Adapter_Interface {
	/**
	 * Return the provider's default job content type.
	 *
	 * @return string
	 */
	public function content_type(): string;

	/**
	 * Resolve a template to the provider's wire format and content type.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function format( array $printer, array $template ): array;

	/**
	 * Build a base64-encoded provider diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array;

	/**
	 * Resolve provider status from printer data and plain runtime context.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $context Runtime status context.
	 *
	 * @return string
	 */
	public function status( array $printer, array $context ): string;
}
