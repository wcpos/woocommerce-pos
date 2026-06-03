<?php
/**
 * Template PDF Service.
 *
 * Renders any receipt template to PDF bytes. Thermal templates are built into a
 * thermal AST and emitted to receipt HTML via Html_Thermal_Emitter; all other
 * engines (logicless, legacy-php) run their matching renderer and capture the
 * echoed HTML via output buffering. The resulting HTML is rasterized to PDF with
 * Pdf_Renderer (Dompdf).
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer;
use WC_Abstract_Order;

/**
 * Template_Pdf_Service class.
 */
class Template_Pdf_Service {

	/**
	 * Render a receipt template for an order into PDF bytes.
	 *
	 * @param array             $template Template metadata/content (must include 'engine').
	 * @param WC_Abstract_Order $order    The order to render.
	 *
	 * @return string The PDF document bytes (begins with '%PDF-').
	 */
	public function render( array $template, WC_Abstract_Order $order ): string {
		$engine = isset( $template['engine'] ) ? (string) $template['engine'] : '';

		if ( 'thermal' === $engine ) {
			$ast  = ( new Thermal_Renderer() )->build_ast( $template, $order );
			$html = ( new Html_Thermal_Emitter() )->emit( $ast );

			// Narrow continuous-roll receipt page: ~80mm wide (226.77pt). Render on
			// a tall probe page, then let Pdf_Renderer fit the height to the content
			// so downloaded PDFs and PrintNode PDFs avoid blank tails/overflow pages.
			return ( new Pdf_Renderer() )->render_html(
				$html,
				array(
					'paper'        => array( 0, 0, 226.77, 100000.0 ),
					'default_font' => 'dejavu sans mono',
					'fit_height'   => true,
				)
			);
		}

		$html = $this->render_html_engine( $engine, $template, $order );

		// Non-thermal templates render to a standard A4 portrait page (Pdf_Renderer default).
		return ( new Pdf_Renderer() )->render_html( $html );
	}

	/**
	 * Run an echo-based renderer (logicless / legacy-php) and capture its HTML.
	 *
	 * @param string            $engine   The template engine.
	 * @param array             $template Template metadata/content.
	 * @param WC_Abstract_Order $order    The order to render.
	 *
	 * @return string The captured receipt HTML.
	 */
	private function render_html_engine( string $engine, array $template, WC_Abstract_Order $order ): string {
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$renderer     = ( new Receipt_Renderer_Factory() )->create( $engine );

		ob_start();
		try {
			$renderer->render( $template, $order, $receipt_data );
		} finally {
			$html = ob_get_clean();
		}

		return false === $html ? '' : $html;
	}
}
