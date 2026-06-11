<?php
/**
 * Dev harness: render small HTML snippets through Pdf_Renderer to compare
 * Dompdf behaviors (status-pill dot variants, encoding handling).
 *
 * phpcs:ignoreFile
 */

use WCPOS\WooCommercePOS\Services\Pdf_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pill = static function ( string $label, string $dot ): string {
	return '<div style="margin: 8px 0;"><span style="font-size: 9px; color: #999;">' . $label . '</span><br>'
		. '<div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #f3f4f6; color: #1f2937; border-radius: 999px; font-weight: 700; font-size: 10px; letter-spacing: 0.10em; text-transform: uppercase;">'
		. $dot . ' COMPLETED</div></div>';
};

$html = '<div style="box-sizing: border-box; font-family: sans-serif; font-size: 13px; padding: 24px;">'
	. $pill( 'A: empty span 6x6 (current)', '<span style="width: 6px; height: 6px; border-radius: 50%; background: #6b7280;"></span>' )
	. $pill( 'B: nbsp content', '<span style="display:inline-block; width: 6px; height: 6px; border-radius: 50%; background: #6b7280; font-size:1px; line-height:1px;">&nbsp;</span>' )
	. $pill( 'C: bullet glyph', '<span style="color: #6b7280;">&#9679;</span>' )
	. $pill( 'D: no valign, overflow hidden', '<span style="display:inline-block; width: 6px; height: 6px; border-radius: 50%; background: #6b7280; overflow: hidden;"></span>' )
	. '<div style="margin-top: 16px;">EM-DASH literal: — | entity: &mdash; | accents: ARTÍCULO Página £ €</div>'
	. '</div>';

$pdf = ( new Pdf_Renderer() )->render_html( $html, array( 'receipt_layout' => true ) );
file_put_contents( __DIR__ . '/out/snippets.pdf', $pdf );
WP_CLI::success( 'snippets.pdf written (' . strlen( $pdf ) . ' bytes)' );
