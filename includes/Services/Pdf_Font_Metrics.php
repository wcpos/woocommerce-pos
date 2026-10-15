<?php
/**
 * Unicode font aliases for receipt PDFs.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\Vendor\Dompdf\FontMetrics;

/**
 * Dompdf font lookup that never resolves text to a PDF core font.
 *
 * Every receipt template ends its font stack in a generic family
 * (sans-serif, serif, monospace), which Dompdf's bundled map resolves to the
 * PDF core fonts Helvetica, Times and Courier. Core fonts are Windows-1252
 * only: Dompdf converts their text with mb_convert_encoding() and every
 * character outside that code page prints as a literal "?" — Greek, Cyrillic,
 * Arabic and CJK receipts lose all their labels. The `defaultFont` option does
 * not help, because it only applies when no family in the stack is known.
 *
 * The aliases below point those families at the DejaVu TrueType fonts Dompdf
 * ships, which embed a Unicode subset. They are applied to the in-memory
 * lookup only; setFontFamily() would persist them into the font cache dir.
 */
class Pdf_Font_Metrics extends FontMetrics {
	/**
	 * Core font families (lower-case) and the bundled Unicode family that
	 * replaces each. Symbol and ZapfDingbats are left alone.
	 */
	private const FONT_ALIASES = array(
		'sans-serif'  => 'dejavu sans',
		'helvetica'   => 'dejavu sans',
		'serif'       => 'dejavu serif',
		'times'       => 'dejavu serif',
		'times-roman' => 'dejavu serif',
		'monospace'   => 'dejavu sans mono',
		'courier'     => 'dejavu sans mono',
		'fixed'       => 'dejavu sans mono',
	);

	/**
	 * Build the font lookup table and apply aliases in memory only.
	 *
	 * @return void
	 */
	public function setFontFamilies() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides Dompdf's method.
		parent::setFontFamilies();

		foreach ( self::FONT_ALIASES as $alias => $target ) {
			if ( isset( $this->fontFamilies[ $target ] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
				$this->fontFamilies[ $alias ] = $this->fontFamilies[ $target ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
			}
		}
	}
}
