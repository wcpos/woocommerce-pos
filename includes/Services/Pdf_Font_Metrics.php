<?php
/**
 * Unicode font aliases for receipt PDFs.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\Vendor\Dompdf\Dompdf;
use WCPOS\Vendor\Dompdf\FontMetrics;
use WCPOS\Vendor\Dompdf\Options;

/**
 * Dompdf font lookup that never resolves text to a PDF core font.
 *
 * Every receipt template ends its font stack in a generic family
 * (sans-serif, serif, monospace), which Dompdf's bundled map resolves to the
 * PDF core fonts Helvetica, Times and Courier. Core fonts are Windows-1252
 * only: Dompdf converts their text with mb_convert_encoding() and every
 * character outside that code page prints as a literal "?" — a Greek or
 * Cyrillic receipt loses every label. The `defaultFont` option does not help,
 * because it only applies when no family in the stack is known.
 *
 * The aliases below point those families at the DejaVu TrueType fonts Dompdf
 * ships, which cover Latin, Greek and Cyrillic (not CJK, and Dompdf does no
 * Arabic shaping). They are applied to the in-memory lookup only;
 * setFontFamily() would persist them into the font cache dir.
 */
class Pdf_Font_Metrics extends FontMetrics {
	/**
	 * DejaVu Sans Mono advance per Dompdf's .ufm metrics, guaranteed by this
	 * class's aliases for every monospace stack the PDF renderer sees.
	 */
	public const MONOSPACE_ADVANCE_EM = 0.602;

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
	 * Install the aliased lookup on a Dompdf instance.
	 *
	 * Dompdf's constructor builds a FontMetrics and hands the same instance to
	 * its Stylesheet, and Dompdf::setFontMetrics() swaps only Dompdf's own
	 * reference. Styles resolve font-family through the Stylesheet's copy, so
	 * both must point at this class or the first lookup through the original
	 * still lands on a core font and, because FontMetrics::getFont() caches by
	 * family name in a static, pins that answer for the rest of the process.
	 *
	 * @param Dompdf  $dompdf  Freshly constructed Dompdf instance.
	 * @param Options $options The options the instance was built with.
	 *
	 * @return void
	 */
	public static function install( Dompdf $dompdf, Options $options ): void {
		$metrics = new self( $dompdf->getCanvas(), $options );
		$dompdf->setFontMetrics( $metrics );
		$dompdf->getCss()->setFontMetrics( $metrics );
	}

	/**
	 * Resolve a family, answering merchant-registered families from the live table.
	 *
	 * Dompdf's getFont() memoises every answer in a process-wide static keyed by
	 * family name, so a receipt rendered earlier in the same request pins
	 * "helvetica" to the alias before a later template's @font-face registers
	 * it. A family present in userFonts is therefore looked up directly.
	 *
	 * @param string|null $family_raw  Font family name as written in CSS.
	 * @param string|null $subtype_raw Dompdf subtype ("normal", "bold", "italic", "bold_italic").
	 *
	 * @return string|null Font file path without extension, or null.
	 */
	public function getFont( $family_raw, $subtype_raw = 'normal' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides Dompdf's method.
		if ( $family_raw ) {
			$family = str_replace( array( "'", '"' ), '', strtolower( (string) $family_raw ) );
			if ( isset( $this->userFonts[ $family ] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
				$subtype = strtolower( (string) ( $subtype_raw ? $subtype_raw : 'normal' ) );

				return $this->getFontFamilies()[ $family ][ $subtype ] ?? null;
			}
		}

		return parent::getFont( $family_raw, $subtype_raw );
	}

	/**
	 * Build the font lookup table and apply aliases in memory only.
	 *
	 * Dompdf's own method documents `@return array` and returns nothing; the
	 * table is returned here so the override matches the declared contract.
	 *
	 * @return array
	 */
	public function setFontFamilies() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides Dompdf's method.
		parent::setFontFamilies();

		foreach ( self::FONT_ALIASES as $alias => $target ) {
			// A merchant's own font registration takes precedence over aliases.
			if ( isset( $this->userFonts[ $alias ] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
				continue;
			}
			if ( isset( $this->fontFamilies[ $target ] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
				$this->fontFamilies[ $alias ] = $this->fontFamilies[ $target ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
			}
		}

		return $this->fontFamilies; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Inherited Dompdf property.
	}
}
