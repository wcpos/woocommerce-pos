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

		$wp_overnight_pdf = $this->maybe_render_wp_overnight_pdf( $template, $order );
		if ( null !== $wp_overnight_pdf ) {
			return $wp_overnight_pdf;
		}

		if ( 'thermal' === $engine ) {
			$paper_width_pt = $this->thermal_paper_width_pt( $template );

			$ast  = ( new Thermal_Renderer() )->build_ast( $template, $order );
			$html = ( new Html_Thermal_Emitter() )->emit(
				$ast,
				array(
					// CSS pixels (96dpi) for the paper width, so the emitter can
					// scale the monospace character grid to fit the page.
					'paper_width_px' => $paper_width_pt * 4 / 3,
				)
			);

			// Narrow continuous-roll receipt page at the template's declared paper
			// width (58/80mm). Render on a tall probe page, then let Pdf_Renderer
			// fit the height to the content so downloaded PDFs and PrintNode PDFs
			// avoid blank tails/overflow pages.
			return ( new Pdf_Renderer() )->render_html(
				$html,
				array(
					'paper'          => array( 0, 0, $paper_width_pt, 14000.0 ),
					'default_font'   => 'dejavu sans mono',
					'fit_height'     => true,
					'receipt_layout' => true,
				)
			);
		}

		$html = $this->render_html_engine( $engine, $template, $order );

		// Non-thermal templates render to a standard A4 portrait page (Pdf_Renderer default).
		return ( new Pdf_Renderer() )->render_html( $html, array( 'receipt_layout' => true ) );
	}

	/**
	 * Resolve a thermal template's paper width in points.
	 *
	 * Template metadata declares the physical roll ('58mm' / '80mm'); fall back
	 * to 80mm when absent so templates without metadata keep the previous page
	 * size.
	 *
	 * @param array $template Template metadata/content.
	 *
	 * @return float Paper width in pt.
	 */
	private function thermal_paper_width_pt( array $template ): float {
		$raw = isset( $template['paper_width'] ) ? (string) $template['paper_width'] : '';
		$mm  = (float) $raw; // Leading-number cast: '58mm' → 58.0.

		if ( $mm < 25.0 || $mm > 250.0 ) {
			$mm = 80.0;
		}

		return round( $mm * 72 / 25.4, 2 );
	}

	/**
	 * Render WP Overnight integration templates using the plugin's native PDF bytes.
	 *
	 * @param array             $template Template metadata/content.
	 * @param WC_Abstract_Order $order    The order to render.
	 *
	 * @return string|null Native PDF bytes for WP Overnight templates, null for all other templates.
	 * @throws \RuntimeException When the WP Overnight PDF document cannot be generated.
	 */
	private function maybe_render_wp_overnight_pdf( array $template, WC_Abstract_Order $order ): ?string {
		$document_type = $this->wp_overnight_document_type( $template );
		if ( null === $document_type ) {
			return null;
		}

		$document = apply_filters( 'woocommerce_pos_wp_overnight_pdf_document', null, $document_type, $order );
		if ( null === $document && \function_exists( 'wcpdf_get_document' ) ) {
			$document = wcpdf_get_document( $document_type, $order, true );
		}

		if ( ! $document || ! \is_callable( array( $document, 'get_pdf' ) ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: WP Overnight document type. */
						__( 'WP Overnight %s PDF could not be generated.', 'woocommerce-pos' ),
						$document_type
					)
				)
			);
		}

		$pdf = $document->get_pdf();
		if ( ! \is_string( $pdf ) || '' === $pdf || 0 !== strpos( $pdf, '%PDF-' ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: WP Overnight document type. */
						__( 'WP Overnight %s PDF could not be generated.', 'woocommerce-pos' ),
						$document_type
					)
				)
			);
		}

		return $pdf;
	}

	/**
	 * Map WCPOS virtual template IDs to WP Overnight document types.
	 *
	 * @param array $template Template metadata/content.
	 *
	 * @return string|null invoice|packing-slip for WP Overnight templates, null otherwise.
	 */
	private function wp_overnight_document_type( array $template ): ?string {
		$id = isset( $template['id'] ) ? (string) $template['id'] : '';

		if ( 'wp-overnight-invoice' === $id ) {
			return 'invoice';
		}

		if ( 'wp-overnight-packing-slip' === $id ) {
			return 'packing-slip';
		}

		return null;
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
