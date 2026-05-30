<?php
/**
 * Renders HTML to PDF bytes via the prefixed Dompdf library.
 *
 * Thin wrapper around WCPOS\Vendor\Dompdf\Dompdf. Remote/PHP/JS are disabled for
 * safety (images must be embedded as data URIs); Dompdf's writable font cache and
 * temp dir are pointed at a WCPOS-owned subdirectory of the system temp so nothing
 * is written into the read-only, committed vendor_prefixed/ tree.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\Vendor\Dompdf\Dompdf;
use WCPOS\Vendor\Dompdf\Options;

/**
 * Pdf_Renderer class.
 */
class Pdf_Renderer {
	/**
	 * Render an HTML document to PDF bytes.
	 *
	 * @param string $html HTML document to render.
	 * @param array  $opts Optional: 'paper' (size name or [x0,y0,x1,y1]), 'orientation'
	 *                     ('portrait'|'landscape'), 'default_font'.
	 *
	 * @return string The PDF document bytes (begins with '%PDF-').
	 */
	public function render_html( string $html, array $opts = array() ): string {
		$temp_dir = $this->writable_dir();

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'isJavascriptEnabled', false );
		$options->set( 'defaultFont', isset( $opts['default_font'] ) ? (string) $opts['default_font'] : 'dejavu sans' );
		// Keep Dompdf's bundled fonts as the font source (default fontDir), but
		// direct its writable caches at a WCPOS-owned temp dir.
		$options->set( 'fontCache', $temp_dir );
		$options->set( 'tempDir', $temp_dir );
		// chroot only gates Dompdf's file:// local-URI access (e.g. <img src> /
		// @import to local paths); Dompdf still loads its bundled fonts from its
		// own rootDir/fontDir, so confining chroot to $temp_dir does not break
		// font rendering. With isRemoteEnabled false and images embedded as data
		// URIs, no local file access is needed anyway.
		$options->set( 'chroot', array( $temp_dir ) );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );

		$paper       = isset( $opts['paper'] ) ? $opts['paper'] : 'A4';
		$orientation = isset( $opts['orientation'] ) ? (string) $opts['orientation'] : 'portrait';
		$dompdf->setPaper( $paper, $orientation );

		$dompdf->render();

		return (string) $dompdf->output();
	}

	/**
	 * Resolve a writable directory for Dompdf's font cache and temp files.
	 *
	 * @return string Absolute path to a writable WCPOS-owned temp directory.
	 */
	private function writable_dir(): string {
		$dir = rtrim( get_temp_dir(), '/\\' ) . '/wcpos-dompdf';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Restrict the freshly created cache dir to the owner; it holds
			// rendered font caches and temp PDFs that need not be world-readable.
			@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return $dir;
	}
}
