<?php
/**
 * On-demand receipt fonts, stored outside the plugin directory.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Templates\Frontend;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Local atomic file writes; expected IO failures are logged once per pack.

/** Installs font packs for Dompdf and GD. */
class Font_Pack_Loader {
	/** One base URL per pack so a heavy pack (CJK) can later live in another repo; %s is Frontend::cdn_ref(). */
	const PACKS = array( 'dejavu' => 'https://cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@%s/fonts/packs/dejavu/' );
	/** A dead CDN or read-only uploads must not be re-probed on every receipt. */
	const FAILED_TTL = HOUR_IN_SECONDS;
	/** Avoid overlapping installs while allowing an interrupted request to expire. */
	const LOCK_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Font storage directory.
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public function dir(): string {
		return wp_upload_dir()['basedir'] . '/wcpos/fonts';
	}

	/**
	 * Packs enabled for receipts.
	 *
	 * @return string[] Pack names.
	 */
	public static function packs(): array {
		/**
		 * Filters the font packs so a site can drop or add packs.
		 *
		 * @since 1.11.0
		 * @param string[] $packs Enabled pack names.
		 * @hook woocommerce_pos_font_packs
		 */
		return apply_filters( 'woocommerce_pos_font_packs', array_keys( self::PACKS ) );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Exceptions are caught here, not exposed.
	/**
	 * Install a pack once, caching expected failures.
	 *
	 * @param string $pack Pack name.
	 * @return bool Whether the pack is installed.
	 */
	public function ensure( string $pack ): bool {
		if ( ! isset( self::PACKS[ $pack ] ) ) {
			return false;
		}
		$dir      = $this->dir();
		$receipt  = $dir . '/' . $pack . '.json';
		$manifest = is_file( $receipt ) ? json_decode( (string) @file_get_contents( $receipt ), true ) : null;
		if ( ! empty( $manifest['files'] ) ) {
			$missing = array_filter(
				array_keys( $manifest['files'] ),
				static function ( string $file ) use ( $dir ): bool {
					return ! is_file( $dir . '/' . $file );
				}
			);
			if ( empty( $missing ) ) {
				return true;
			}
		}
		$failed = 'wcpos_font_pack_failed_' . $pack;
		$lock   = 'wcpos_font_pack_lock_' . $pack;
		if ( get_transient( $failed ) || get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, self::LOCK_TTL );
		$written = array();
		try {
			$local    = $this->local_pack_dir( $pack );
			$local    = '' !== $local && is_file( $local . '/pack.json' ) ? $local : '';
			$base     = sprintf( self::PACKS[ $pack ], Frontend::cdn_ref() );
			$json     = $this->read( $local, $base, 'pack.json' );
			$manifest = json_decode( $json, true );
			if ( ! is_array( $manifest['files'] ?? null ) || empty( $manifest['files'] ) || ! is_array( $manifest['families'] ?? null ) ) {
				throw new \RuntimeException( 'Invalid manifest' );
			}
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new \RuntimeException( 'Cannot create font directory' );
			}
			foreach ( $manifest['files'] as $file => $hash ) {
				if ( basename( $file ) !== $file || ! in_array( pathinfo( $file, PATHINFO_EXTENSION ), array( 'ttf', 'ufm' ), true ) ) {
					throw new \RuntimeException( 'Invalid font filename' );
				}
				$bytes = $this->read( $local, $base, $file );
				if ( hash( 'sha256', $bytes ) !== $hash ) {
					throw new \RuntimeException( 'Hash mismatch: ' . $file );
				}
				$this->write( $dir . '/' . $file, $bytes );
				$written[] = $dir . '/' . $file;
			}
			$this->write( $receipt, $json );
			$written[] = $receipt;
			$families  = array();
			foreach ( glob( $dir . '/*.json' ) as $path ) {
				if ( basename( $path ) !== 'installed-fonts.json' ) {
					$installed = json_decode( (string) @file_get_contents( $path ), true );
					$families  = array_merge( $families, $installed['families'] ?? array() );
				}
			}
			$this->write( $dir . '/installed-fonts.json', (string) wp_json_encode( $families ) );
			return true;
		} catch ( \RuntimeException $e ) {
			foreach ( $written as $path ) {
				@unlink( $path );
			}
			set_transient( $failed, 1, self::FAILED_TTL );
			Logger::log( 'Font pack ' . $pack . ': ' . $e->getMessage() );
			return false;
		} finally {
			delete_transient( $lock );
		}
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

	/**
	 * Ensure all enabled packs, without short-circuiting after a failure.
	 *
	 * @return bool Whether every pack is installed.
	 */
	public function ensure_all(): bool {
		$success = true;
		foreach ( self::packs() as $pack ) {
			$success = $this->ensure( $pack ) && $success;
		}
		return $success;
	}

	/**
	 * Resolve an installed font file.
	 *
	 * @param string $basename Font filename including extension.
	 * @return string Readable path, or an empty string.
	 */
	public function font_path( string $basename ): string {
		$path = $this->dir() . '/' . $basename;
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Checkout source directory; absent in plugin builds.
	 *
	 * @param string $pack Pack name.
	 * @return string Local directory.
	 */
	protected function local_pack_dir( string $pack ): string {
		return PLUGIN_PATH . 'fonts/packs/' . $pack;
	}

	/**
	 * Read a local file or fetch it from the pack's CDN.
	 *
	 * @param string $local Local directory, or empty for CDN.
	 * @param string $base CDN base URL.
	 * @param string $file Filename.
	 * @return string File bytes.
	 * @throws \RuntimeException On a read or HTTP failure.
	 */
	private function read( string $local, string $base, string $file ): string {
		if ( '' !== $local ) {
			$bytes = @file_get_contents( $local . '/' . $file );
			if ( false === $bytes ) {
				throw new \RuntimeException( 'Cannot read ' . $file );
			}
			return $bytes;
		}
		$response = wp_remote_get( $base . $file, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'Download failed: ' . $file . ' (' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) . ')' );
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Publish bytes with a temporary file in the same directory.
	 *
	 * @param string $path Destination path.
	 * @param string $bytes File bytes.
	 * @throws \RuntimeException On a write failure.
	 */
	private function write( string $path, string $bytes ): void {
		$temp = $path . '.tmp';
		if ( strlen( $bytes ) !== @file_put_contents( $temp, $bytes ) || ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			throw new \RuntimeException( 'Cannot write ' . basename( $path ) );
		}
	}
}
