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
	 * Dompdf flex/grid compatibility stylesheet.
	 *
	 * Dompdf has no CSS Flexbox or Grid layout engine — it silently maps
	 * `display:flex` and `display:grid` to `block`, which collapses receipt
	 * rows built as columns (labels and values run together, multi-column
	 * blocks stack vertically). This stylesheet re-expresses those containers
	 * as tables, which Dompdf lays out correctly, so the downloaded PDF matches
	 * the on-screen receipt. It targets both inline-style flex/grid (thermal
	 * emitter output and the HTML gallery templates) and the class-based flex
	 * used by the bundled legacy receipt template. `!important` is required to
	 * win over the templates' inline `display` declarations.
	 *
	 * Injected only into the PDF render path; gallery templates and live
	 * previews are never modified.
	 */
	private const FLEX_GRID_SHIM = '<style>'
		// 1. Multi-column LAYOUT containers (header, footer, page grids) become a
		// single-level table. Leaf label/value rows are handled by rule 2 instead
		// of nesting, because stacked anonymous tables crash Dompdf's cellmap.
		. '[style*="display: flex"],[style*="display:flex"],'
		. '[style*="display: grid"],[style*="display:grid"]'
		. '{display:table !important;width:100% !important;border-spacing:0 !important}'
		. '[style*="gap: 22px"],[style*="gap:22px"]'
		. '{border-collapse:separate !important;border-spacing:22px 0 !important}'
		. '[style*="gap: 24px"],[style*="gap:24px"]'
		. '{border-collapse:separate !important;border-spacing:24px 0 !important}'
		. '[style*="gap: 28px"],[style*="gap:28px"]'
		. '{border-collapse:separate !important;border-spacing:28px 0 !important}'
		. '[style*="display: flex"]>*,[style*="display:flex"]>*,'
		. '[style*="display: grid"]>*,[style*="display:grid"]>*'
		. '{display:table-cell !important;vertical-align:top !important}'
		. '[style*="flex: 0 0 auto"],[style*="flex:0 0 auto"]'
		. '{width:1% !important;white-space:nowrap !important}'
		. '[style*="flex: 0 0 92px"],[style*="flex:0 0 92px"]'
		. '{width:92px !important}'
		. '[style*="flex: 0 0 280px"],[style*="flex:0 0 280px"]'
		. '{width:280px !important}'
		. '[style*="grid-template-columns: 1fr 220px"]>*:last-child,'
		. '[style*="grid-template-columns:1fr 220px"]>*:last-child'
		. '{width:220px !important}'
		. '[style*="grid-template-columns: 1fr 320px"]>*:last-child,'
		. '[style*="grid-template-columns:1fr 320px"]>*:last-child'
		. '{width:320px !important}'
		. '[style*="grid-template-columns: 2fr 1fr"]>*:first-child,'
		. '[style*="grid-template-columns:2fr 1fr"]>*:first-child'
		. '{width:66.666% !important}'
		. '[style*="grid-template-columns: 2fr 1fr"]>*:last-child,'
		. '[style*="grid-template-columns:2fr 1fr"]>*:last-child'
		. '{width:33.333% !important}'
		. '[style*="grid-template-columns: 1fr 1fr 1fr"]>*,'
		. '[style*="grid-template-columns:1fr 1fr 1fr"]>*'
		. '{width:33.333% !important}'
		// 2. Label/value rows (justify-content:space-between) stay block-level and
		// float the value to the right edge — no nested table. These rules are
		// declared after rule 1, so they win the cascade at equal specificity (and
		// the :last-child rule is also higher specificity).
		. '[style*="justify-content: space-between"],[style*="justify-content:space-between"]'
		. '{display:block !important;width:auto !important}'
		. '[style*="justify-content: space-between"]>*,[style*="justify-content:space-between"]>*'
		. '{display:inline !important}'
		. '[style*="justify-content: space-between"]>*:last-child,'
		. '[style*="justify-content:space-between"]>*:last-child'
		. '{display:block !important;float:right !important;text-align:right !important}'
		// 3. flex-end wrappers (e.g. the order barcode) right-align their child
		// without a table, so they do not nest inside a grid cell table.
		. '[style*="justify-content: flex-end"],[style*="justify-content:flex-end"]'
		. '{display:block !important;width:auto !important;text-align:right !important}'
		. '[style*="justify-content: flex-end"]>*,[style*="justify-content:flex-end"]>*'
		. '{display:inline-block !important}'
		// 4. Legacy receipt.php uses class-based flex. Header is a layout table;
		// totals/payment rows use the same float-the-value approach as rule 2.
		. '.receipt-header{display:table !important;width:100% !important;border-spacing:0 !important}'
		. '.receipt-header>*{display:table-cell !important;vertical-align:top !important}'
		. '.totals-row,.payment-row,.payment-sub,.card .row'
		. '{display:block !important;width:auto !important}'
		. '.totals-row>*,.payment-row>*,.payment-sub>*,.card .row>*{display:inline !important}'
		. '.totals-row>*:last-child,.payment-row>*:last-child,'
		. '.payment-sub>*:last-child,.card .row>*:last-child'
		. '{display:block !important;float:right !important;text-align:right !important}'
		. '</style>';

	/**
	 * Render an HTML document to PDF bytes.
	 *
	 * @param string $html HTML document to render.
	 * @param array  $opts Optional: 'paper' (size name or [x0,y0,x1,y1]), 'orientation'
	 *                     ('portrait'|'landscape'), 'default_font', 'fit_height',
	 *                     'flex_grid_shim' (inject the receipt flex/grid compat CSS).
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
		// Opt-in: the shim encodes receipt-layout knowledge (including legacy
		// template class names), so it is only applied when the caller asks for it
		// (receipt rendering) rather than for every generic HTML document.
		if ( ! empty( $opts['flex_grid_shim'] ) ) {
			$html = $this->inject_flex_grid_shim( $html );
		}

		return $this->embed_local_images( $html );
	}

	/**
	 * Prepend the flex/grid compatibility stylesheet to the receipt HTML.
	 *
	 * Inserted just before `</head>` when the HTML is a full document, otherwise
	 * prepended to the fragment (Dompdf wraps loose markup in html/body itself).
	 * Placing the stylesheet last in the head lets its `!important` rules win the
	 * cascade over template styles.
	 *
	 * @param string $html The receipt HTML.
	 *
	 * @return string The HTML with the compatibility stylesheet injected.
	 */
	private function inject_flex_grid_shim( string $html ): string {
		$head_close = stripos( $html, '</head>' );
		if ( false !== $head_close ) {
			return substr_replace( $html, self::FLEX_GRID_SHIM, $head_close, 0 );
		}

		return self::FLEX_GRID_SHIM . $html;
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
				$data_uri = $this->image_src_to_data_uri( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
				if ( null === $data_uri ) {
					return $matches[0];
				}

				return $matches[1] . esc_attr( $data_uri ) . $matches[3];
			},
			$html
		);
	}

	/**
	 * Convert a local image source to a data URI.
	 *
	 * @param string $src Image source.
	 *
	 * @return string|null Data URI, or null when the source is not embeddable.
	 */
	private function image_src_to_data_uri( string $src ): ?string {
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return null;
		}

		$path = $this->local_image_path_from_src( $src );
		if ( null === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return null;
		}

		$bytes = file_get_contents( $path );
		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		$mime = $this->image_mime_type( $path );
		if ( null === $mime ) {
			return null;
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Resolve an image src to a safe local filesystem path.
	 *
	 * @param string $src Image source.
	 *
	 * @return string|null Local path, or null when the src is external/unknown.
	 */
	private function local_image_path_from_src( string $src ): ?string {
		$src = trim( $src );

		if ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) && \defined( 'ABSPATH' ) ) {
			$path = wp_normalize_path( ABSPATH . ltrim( $src, '/' ) );
			return $this->is_allowed_local_image_path( $path ) ? $path : null;
		}

		$mappings = $this->local_url_path_mappings();
		foreach ( $mappings as $url_base => $path_base ) {
			if ( 0 !== strpos( $src, $url_base ) ) {
				continue;
			}

			$relative = ltrim( substr( $src, \strlen( $url_base ) ), '/\\' );
			$path     = wp_normalize_path( trailingslashit( $path_base ) . $relative );

			return $this->is_allowed_local_image_path( $path ) ? $path : null;
		}

		return null;
	}

	/**
	 * Build URL-to-path mappings for local WordPress assets.
	 *
	 * @return array<string,string>
	 */
	private function local_url_path_mappings(): array {
		$uploads = wp_upload_dir();
		$plugin  = dirname( __DIR__, 2 );

		$mappings = array(
			isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
			content_url()                                          => \defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			plugins_url( '', $plugin . '/woocommerce-pos.php' )    => $plugin,
		);

		$normalized = array();
		foreach ( $mappings as $url => $path ) {
			if ( '' === $url || '' === $path ) {
				continue;
			}

			$normalized[ trailingslashit( $url ) ] = wp_normalize_path( $path );
		}

		uksort(
			$normalized,
			static function ( string $a, string $b ): int {
				return \strlen( $b ) <=> \strlen( $a );
			}
		);

		return $normalized;
	}

	/**
	 * Check that a resolved path stays within known local asset roots.
	 *
	 * @param string $path Resolved path.
	 *
	 * @return bool
	 */
	private function is_allowed_local_image_path( string $path ): bool {
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return false;
		}

		$real_path = wp_normalize_path( $real_path );
		foreach ( $this->allowed_local_image_roots() as $root ) {
			if ( 0 === strpos( $real_path, trailingslashit( $root ) ) || $real_path === $root ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Allowed local image roots.
	 *
	 * @return string[]
	 */
	private function allowed_local_image_roots(): array {
		$uploads = wp_upload_dir();
		$roots   = array(
			isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
			\defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			dirname( __DIR__, 2 ),
		);

		return array_values(
			array_filter(
				array_map(
					static function ( string $root ): string {
						$real = realpath( $root );
						return false === $real ? '' : wp_normalize_path( $real );
					},
					$roots
				)
			)
		);
	}

	/**
	 * Determine a supported image MIME type from path.
	 *
	 * @param string $path Local image path.
	 *
	 * @return string|null MIME type.
	 */
	private function image_mime_type( string $path ): ?string {
		$type = wp_check_filetype( $path );
		$mime = isset( $type['type'] ) ? (string) $type['type'] : '';

		if ( '' === $mime ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$mime      = array(
				'gif'  => 'image/gif',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'svg'  => 'image/svg+xml',
				'webp' => 'image/webp',
			)[ $extension ] ?? '';
		}

		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}

		return $mime;
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
