<?php
/**
 * Thermal HTML receipt renderer.
 *
 * Renders a thermal (receipt-printer) template to HTML for the browser print
 * surface. Thermal template content is stored raw — it is exempt from wp_kses
 * because it is XML markup for printers, not HTML — so it must NEVER be executed
 * as PHP. This renderer runs the content through the thermal pipeline
 * (Mustache render -> XML AST -> HTML), which escapes receipt data and discards
 * anything that is not recognised thermal markup (PHP processing instructions
 * included). The include-based Legacy_Php_Renderer must never receive thermal
 * content.
 *
 * @package WCPOS\WooCommercePOS\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Templates\Renderers;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
use WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer;
use WC_Abstract_Order;

/**
 * Thermal_Html_Renderer class.
 */
class Thermal_Html_Renderer implements Receipt_Renderer_Interface {
	/**
	 * Render a thermal template to HTML.
	 *
	 * @param array                  $template     Template metadata/content.
	 * @param WC_Abstract_Order|null $order        Order object, or null for sample-data preview.
	 * @param array                  $receipt_data Canonical receipt payload (unused; the thermal pipeline rebuilds its own data).
	 */
	public function render( array $template, ?WC_Abstract_Order $order, array $receipt_data ): void {
		// The thermal pipeline builds its receipt data from a concrete order.
		if ( ! $order instanceof WC_Abstract_Order ) {
			echo '<!-- Thermal receipt preview requires an order -->';
			return;
		}

		try {
			$ast  = ( new Thermal_Renderer() )->build_ast( $template, $order );
			$html = ( new Html_Thermal_Emitter() )->emit(
				$ast,
				array( 'paper_width_px' => $this->paper_width_px( $template ) )
			);
		} catch ( \Throwable $e ) {
			// Malformed thermal markup (e.g. a raw PHP payload with no <receipt>
			// root) throws during parsing. Fail closed with a harmless comment
			// rather than surfacing the raw content.
			echo '<!-- Thermal receipt could not be rendered -->';
			return;
		}

		// $html is built by the thermal emitter, which escapes receipt data.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emitter output is self-generated thermal markup with escaped values.
	}

	/**
	 * Resolve the template's paper width in CSS pixels (96dpi).
	 *
	 * Template metadata declares the physical roll ('58mm' / '80mm'); fall back
	 * to 80mm when absent or out of range so the emitter can scale the character
	 * grid to fit the page.
	 *
	 * @param array $template Template metadata/content.
	 *
	 * @return float Paper width in CSS px.
	 */
	private function paper_width_px( array $template ): float {
		$raw = isset( $template['paper_width'] ) ? (string) $template['paper_width'] : '';
		$mm  = (float) $raw; // Leading-number cast: '58mm' -> 58.0.

		if ( $mm < 25.0 || $mm > 250.0 ) {
			$mm = 80.0;
		}

		return round( $mm * 96 / 25.4, 2 );
	}
}
