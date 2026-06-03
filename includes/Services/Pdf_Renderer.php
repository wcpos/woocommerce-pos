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
	 * Tall probe page height used when fitting continuous-roll receipt PDFs.
	 */
	private const FIT_HEIGHT_PROBE_PT = 14000.0;

	/**
	 * Bottom breathing room added to the fitted receipt PDF page.
	 */
	private const FIT_HEIGHT_MARGIN_PT = 12.0;

	/**
	 * Binary-search render passes for the smallest single-page height.
	 */
	private const FIT_HEIGHT_SEARCH_STEPS = 18;

	/**
	 * Render an HTML document to PDF bytes.
	 *
	 * @param string $html HTML document to render.
	 * @param array  $opts Optional: 'paper' (size name or [x0,y0,x1,y1]), 'orientation'
	 *                     ('portrait'|'landscape'), 'default_font', 'fit_height'.
	 *
	 * @return string The PDF document bytes (begins with '%PDF-').
	 */
	public function render_html( string $html, array $opts = array() ): string {
		$paper = isset( $opts['paper'] ) ? $opts['paper'] : 'A4';
		if ( ! empty( $opts['fit_height'] ) && \is_array( $paper ) ) {
			$opts['paper'] = $this->fit_height_paper( $html, $opts, $paper );
		}

		return (string) $this->build( $html, $opts )->output();
	}

	/**
	 * Build and render a Dompdf instance.
	 *
	 * @param string $html HTML document to render.
	 * @param array  $opts Render options.
	 *
	 * @return mixed Rendered Dompdf instance.
	 */
	private function build( string $html, array $opts ) {
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

		$this->render_dompdf( $dompdf );

		return $dompdf;
	}

	/**
	 * Render a Dompdf instance while ignoring PHP 8.5 vendor deprecations.
	 *
	 * @param mixed $dompdf Dompdf instance.
	 */
	private function render_dompdf( $dompdf ): void {
		$previous_handler = null;
		$previous_handler = set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$previous_handler ): bool {
				if (
					0 !== ( $errno & ( E_DEPRECATED | E_USER_DEPRECATED ) )
					&& false !== strpos( str_replace( '\\', '/', $errfile ), '/vendor_prefixed/' )
				) {
					return true;
				}

				if ( \is_callable( $previous_handler ) ) {
					return (bool) \call_user_func( $previous_handler, $errno, $errstr, $errfile, $errline );
				}

				return false;
			}
		);

		try {
			$dompdf->render();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Fit a custom paper box to the smallest height that keeps content on one page.
	 *
	 * The vendored Dompdf frame tree does not expose reliable post-render content
	 * frames for this path, so use the documented Canvas page count as the signal.
	 *
	 * @param string $html  HTML document to render.
	 * @param array  $opts  Render options.
	 * @param array  $paper Custom paper box [x0,y0,x1,y1].
	 *
	 * @return array Fitted custom paper box.
	 */
	private function fit_height_paper( string $html, array $opts, array $paper ): array {
		$probe_paper = array( $paper[0], $paper[1], $paper[2], self::FIT_HEIGHT_PROBE_PT );
		$probe_opts  = $opts;
		unset( $probe_opts['fit_height'] );
		$probe_opts['paper'] = $probe_paper;

		$high = self::FIT_HEIGHT_PROBE_PT;
		$low  = 1.0;

		$probe = $this->build( $html, $probe_opts );
		if ( $probe->getCanvas()->get_page_count() > 1 ) {
			return $probe_paper;
		}

		for ( $i = 0; $i < self::FIT_HEIGHT_SEARCH_STEPS; $i++ ) {
			$mid       = ( $low + $high ) / 2;
			$test_opts = $probe_opts;
			$test_opts['paper'] = array( $paper[0], $paper[1], $paper[2], $mid );

			if ( $this->build( $html, $test_opts )->getCanvas()->get_page_count() <= 1 ) {
				$high = $mid;
			} else {
				$low = $mid;
			}
		}

		return array( $paper[0], $paper[1], $paper[2], ceil( $high ) + self::FIT_HEIGHT_MARGIN_PT );
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
