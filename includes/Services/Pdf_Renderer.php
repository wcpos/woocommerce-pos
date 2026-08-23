<?php
/**
 * Renders HTML to PDF bytes via the prefixed Dompdf library.
 *
 * Thin wrapper around WCPOS\Vendor\Dompdf\Dompdf. Remote/PHP/JS are disabled for
 * safety; local WordPress/plugin images are embedded as data URIs before Dompdf
 * sees the HTML. Dompdf's writable font cache and temp dir are pointed at a
 * WCPOS-owned subdirectory of the system temp so nothing is written into the
 * read-only, committed vendor_prefixed/ tree.
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
	 *                     ('portrait'|'landscape'), 'default_font', 'fit_height',
	 *                     'receipt_layout' (rewrite receipt flex/grid markup for Dompdf
	 *                     and lift the root padding into @page margins).
	 *
	 * @return string The PDF document bytes (begins with '%PDF-').
	 */
	public function render_html( string $html, array $opts = array() ): string {
		$html = $this->prepare_html_for_render( $html, $opts );

		$paper = isset( $opts['paper'] ) ? $opts['paper'] : 'A4';
		if ( ! empty( $opts['fit_height'] ) && \is_array( $paper ) ) {
			$opts['paper'] = $this->fit_height_paper( $html, $opts, $paper );
		}

		return (string) $this->build( $html, $opts )->output();
	}

	/**
	 * Prepare receipt HTML for Dompdf's locked-down render environment.
	 *
	 * @param string $html HTML document to render.
	 * @param array  $opts Render options.
	 *
	 * @return string Prepared HTML.
	 */
	private function prepare_html_for_render( string $html, array $opts ): string {
		// Opt-in: the rewrite encodes receipt-layout knowledge (including legacy
		// template class names), so it is only applied when the caller asks for it
		// (receipt rendering) rather than for every generic HTML document.
		if ( ! empty( $opts['receipt_layout'] ) ) {
			$preprocessor = new Pdf_Layout_Preprocessor();
			$html         = $preprocessor->process( $html );

			// The preprocessor owns full-document detection so the two sides
			// can never disagree about which treatment an input received.
			if ( $preprocessor->is_full_document() ) {
				// Full HTML documents (the legacy-php receipt template) keep
				// their own <head> stylesheet, charset and Dompdf's default
				// page margins; the preprocessor rewrote their flex containers
				// (inline-styled and known legacy classes) in place.
				return $this->embed_local_images( $html );
			}

			// Match the browser preview: the template's own root padding is
			// the only whitespace around the receipt, so it replaces Dompdf's
			// default 1.2cm page margin (and keeps later pages consistent
			// with page one).
			$margins = $preprocessor->get_page_margins_pt();

			$page_style = '<style>@page { margin: '
				. implode(
					' ',
					array_map(
						static function ( float $pt ): string {
							return self::css_number( $pt ) . 'pt';
						},
						$margins
					)
				)
				. '; } body { margin: 0; padding: 0; }</style>';

			// Receipts are UTF-8 fragments with no charset declaration;
			// without one Dompdf sniffs the encoding and mostly-ASCII
			// receipts with a stray multibyte character (e.g. an em dash)
			// get mis-decoded.
			$charset_meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';

			$html = $this->inject_head_styles( $html, $charset_meta . $page_style );
		}

		return $this->embed_local_images( $html );
	}

	/**
	 * Format a float for CSS output, immune to LC_NUMERIC comma locales.
	 *
	 * @param float $value The value to format.
	 *
	 * @return string The formatted number.
	 */
	private static function css_number( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Prepend compatibility stylesheets to the receipt HTML.
	 *
	 * Inserted just before `</head>` when the HTML is a full document, otherwise
	 * prepended to the fragment (Dompdf wraps loose markup in html/body itself).
	 * Placing the stylesheet last in the head lets its `!important` rules win the
	 * cascade over template styles.
	 *
	 * @param string $html   The receipt HTML.
	 * @param string $styles The <style> block(s) to inject.
	 *
	 * @return string The HTML with the stylesheets injected.
	 */
	private function inject_head_styles( string $html, string $styles ): string {
		$head_close = stripos( $html, '</head>' );
		if ( false !== $head_close ) {
			return substr_replace( $html, $styles, $head_close, 0 );
		}

		return $styles . $html;
	}

	/**
	 * Embed local WordPress image URLs as data URIs.
	 *
	 * Dompdf remote loading and local file access are intentionally disabled, so
	 * receipt logos and bundled assets must be inlined. Only URLs that resolve to
	 * known local WordPress/plugin paths are embedded; external URLs are left
	 * untouched.
	 *
	 * @param string $html HTML document.
	 *
	 * @return string HTML with local image sources embedded.
	 */
	private function embed_local_images( string $html ): string {
		return (string) preg_replace_callback(
			'/(<img\b[^>]*\bsrc\s*=\s*["\'])([^"\']+)(["\'][^>]*>)/i',
			function ( array $matches ): string {
				$data_uri = ( new Local_Image_Resolver() )->data_uri( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
				if ( null === $data_uri ) {
					return $matches[0];
				}

				return $matches[1] . esc_attr( $data_uri ) . $matches[3];
			},
			$html
		);
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
