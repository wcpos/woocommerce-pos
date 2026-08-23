<?php
/**
 * Local image resolution for receipt rendering.
 *
 * Receipt templates carry logos as ordinary WordPress URLs, but nothing that
 * renders a receipt may fetch a URL: the PDF path runs with Dompdf's remote
 * access disabled, and the cloud-print raster runs inside a printer's job fetch,
 * where an outbound HTTP call would stall the print.
 *
 * So local URLs are resolved to bytes on disk instead. Resolution is deliberately
 * narrow — only the uploads directory, wp-content and this plugin's own directory
 * are reachable, and every candidate path is realpath()-checked against those
 * roots so a crafted `../` src cannot read outside them.
 *
 * Extracted from Pdf_Renderer, which still uses it for Dompdf's data-URI
 * embedding, so both renderers resolve images the same way.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Local_Image_Resolver class.
 */
class Local_Image_Resolver {

	/**
	 * Read the bytes an image src points at.
	 *
	 * Accepts a data URI (decoded in place) or a local WordPress URL/path.
	 * Remote URLs resolve to '' — they are never fetched.
	 *
	 * @param string $src Image source.
	 *
	 * @return string The image bytes, or '' when the src is not resolvable.
	 */
	public function bytes( string $src ): string {
		$src = trim( $src );
		if ( '' === $src ) {
			return '';
		}

		if ( 1 === preg_match( '#^data:image/[a-z.+-]+;base64,#i', $src ) ) {
			$decoded = base64_decode( (string) substr( $src, (int) strpos( $src, ',' ) + 1 ), true );

			return false === $decoded ? '' : $decoded;
		}

		$path = $this->local_path( $src );
		if ( null === $path ) {
			return '';
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read, not HTTP.

		return false === $bytes ? '' : $bytes;
	}

	/**
	 * Convert a local image source to a data URI.
	 *
	 * @param string $src Image source.
	 *
	 * @return string|null Data URI, or null when the source is not embeddable.
	 */
	public function data_uri( string $src ): ?string {
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return null;
		}

		$path = $this->local_path( $src );
		if ( null === $path ) {
			return null;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read, not HTTP.
		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		$mime = $this->mime_type( $path );
		if ( null === $mime ) {
			return null;
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Resolve an image src to a readable local filesystem path.
	 *
	 * @param string $src Image source.
	 *
	 * @return string|null Local path, or null when the src is external/unknown.
	 */
	public function local_path( string $src ): ?string {
		$src = trim( $src );
		$src = explode( '#', $src, 2 )[0];
		$src = explode( '?', $src, 2 )[0];

		if ( '' === $src ) {
			return null;
		}

		$path = null;
		if ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) && \defined( 'ABSPATH' ) ) {
			$path = wp_normalize_path( ABSPATH . ltrim( $src, '/' ) );
		} else {
			foreach ( $this->url_path_mappings() as $url_base => $path_base ) {
				if ( 0 !== strpos( $src, $url_base ) ) {
					continue;
				}

				$relative = ltrim( substr( $src, \strlen( $url_base ) ), '/\\' );
				$path     = wp_normalize_path( trailingslashit( $path_base ) . $relative );
				break;
			}
		}

		if ( null === $path || ! $this->is_allowed_path( $path ) ) {
			return null;
		}

		return ( is_readable( $path ) && is_file( $path ) ) ? $path : null;
	}

	/**
	 * The image MIME type of a local file, when it is one we render.
	 *
	 * @param string $path Local file path.
	 *
	 * @return string|null
	 */
	public function mime_type( string $path ): ?string {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		switch ( $extension ) {
			case 'png':
				return 'image/png';
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			case 'gif':
				return 'image/gif';
			case 'webp':
				return 'image/webp';
			default:
				return null;
		}
	}

	/**
	 * Build URL-to-path mappings for local WordPress assets.
	 *
	 * Longest URL base first, so a nested mapping wins over its parent.
	 *
	 * @return array<string,string>
	 */
	private function url_path_mappings(): array {
		$uploads = wp_upload_dir();
		$plugin  = \dirname( __DIR__, 2 );

		$mappings = array(
			$uploads['baseurl']                                => $uploads['basedir'],
			content_url()                                      => \defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			plugins_url( '', $plugin . '/woocommerce-pos.php' ) => $plugin,
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
	private function is_allowed_path( string $path ): bool {
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return false;
		}

		$real_path = wp_normalize_path( $real_path );
		foreach ( $this->allowed_roots() as $root ) {
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
	private function allowed_roots(): array {
		$uploads = wp_upload_dir();
		$roots   = array(
			$uploads['basedir'],
			\defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			\dirname( __DIR__, 2 ),
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
}
