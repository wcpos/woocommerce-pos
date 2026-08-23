<?php
/**
 * Thermal Attribute Bounds.
 *
 * The one table of legal ranges for the numeric attributes of thermal markup.
 *
 * A thermal template is authored once and rendered down six paths: the merchant
 * preview (packages/thermal-utils/src/thermal-renderer.ts), the PDF receipt
 * (Html_Thermal_Emitter) and the four wire emitters (Escpos, Starprnt, Epos_Xml,
 * Star_Markup). A bound that holds in only some of them is not a safety net, it
 * is a divergence: the merchant previews 50 blank lines and the printer spools
 * 500. So every shared bound lives here, Thermal_Markup_Parser applies it once
 * while building the AST, and the emitters re-apply it to stay safe against
 * hand-built ASTs rather than inventing numbers of their own.
 *
 * Bounds that belong to one device rather than to the markup stay in that
 * emitter, next to the command they constrain, and say so — Starprnt's 8-module
 * QR ceiling and its 8-dot minimum barcode height are the current examples.
 *
 * Keep in step with THERMAL_BOUNDS in
 * packages/thermal-utils/src/thermal-renderer.ts, which mirrors this table.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Thermal_Bounds class.
 */
final class Thermal_Bounds {

	/**
	 * Narrowest receipt in character columns.
	 *
	 * No thermal roll prints fewer; below this the row algebra has nothing to
	 * divide.
	 */
	public const PAPER_WIDTH_MIN = 16;

	/**
	 * Widest receipt in character columns.
	 *
	 * 120 covers the widest thermal and impact rolls sold; the character grid
	 * stops being a receipt beyond it.
	 */
	public const PAPER_WIDTH_MAX = 120;

	/**
	 * Smallest text size multiplier (normal size).
	 */
	public const SIZE_MULTIPLIER_MIN = 1;

	/**
	 * Largest text size multiplier.
	 *
	 * The ESC/POS `GS ! n` size byte carries one nibble per axis, so 8x is the
	 * ceiling the hardware can express; Star's magnification tops out lower
	 * still. Html_Thermal_Emitter renders the same 8em maximum.
	 */
	public const SIZE_MULTIPLIER_MAX = 8;

	/**
	 * Shortest 1D barcode, in dots.
	 */
	public const BARCODE_HEIGHT_MIN = 1;

	/**
	 * Tallest 1D barcode, in dots.
	 *
	 * `GS h n` is a single byte.
	 */
	public const BARCODE_HEIGHT_MAX = 255;

	/**
	 * Smallest QR module size.
	 */
	public const QRCODE_SIZE_MIN = 1;

	/**
	 * Largest QR module size.
	 *
	 * The ESC/POS `GS ( k` module-size function accepts 1-16.
	 */
	public const QRCODE_SIZE_MAX = 16;

	/**
	 * Narrowest image, in printer dots.
	 */
	public const IMAGE_WIDTH_DOTS_MIN = 1;

	/**
	 * Widest image, in printer dots.
	 *
	 * Comfortably past the 576-dot budget of an 80mm head, so it never truncates
	 * a real logo, while still bounding the em width handed to Dompdf.
	 */
	public const IMAGE_WIDTH_DOTS_MAX = 2000;

	/**
	 * Fewest lines a `<feed>` advances.
	 */
	public const FEED_LINES_MIN = 1;

	/**
	 * Most lines a `<feed>` advances.
	 *
	 * At roughly 3.5mm per line this is ~17cm of blank paper — already far more
	 * than any tear-off or cut gap a template legitimately wants, so a larger
	 * number is a typo, and an unbounded one is a hazard: every wire emitter
	 * turns `lines` straight into a loop or a str_repeat(). This is also the
	 * bound the PDF path has shipped since it was written, so pinning the rest
	 * of the paths to 50 moves the fewest of them.
	 */
	public const FEED_LINES_MAX = 50;

	/**
	 * Narrowest fixed column, in characters.
	 *
	 * A zero-width column would silently delete a semantic cell from the row.
	 */
	public const COL_WIDTH_MIN = 1;

	/**
	 * Widest fixed column, in characters.
	 *
	 * A column cannot outgrow the widest paper.
	 */
	public const COL_WIDTH_MAX = self::PAPER_WIDTH_MAX;

	/**
	 * Clamp a value into one of the ranges above.
	 *
	 * Thermal_Markup_Parser already clamps every attribute on its way into the
	 * AST, so for parsed templates this is a no-op. It is here for ASTs built by
	 * hand — tests, and any future caller that skips the parser — because a
	 * bound the emitters do not enforce themselves is a bound that stops holding
	 * the moment someone builds a node directly.
	 *
	 * @param mixed $value    The candidate value.
	 * @param int   $fallback The fallback for missing/non-numeric values.
	 * @param int   $min      The lowest legal value.
	 * @param int   $max      The highest legal value.
	 *
	 * @return int The clamped integer.
	 */
	public static function clamp_int( $value, int $fallback, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}
