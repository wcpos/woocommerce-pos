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

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Local atomic file writes; expected IO failures are logged once per pack.

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
	 * Pack sources: pack name → base URL containing `%s` for the CDN lane ref.
	 *
	 * @return array<string, string> Sources.
	 */
	public static function sources(): array {
		/**
		 * Filters the font pack sources so a site can add a pack (for example a
		 * CJK face hosted elsewhere) or replace where a pack is fetched from.
		 *
		 * @since 1.11.0
		 * @param array<string, string> $sources Pack name → base URL with `%s` for the lane ref.
		 * @hook woocommerce_pos_font_pack_sources
		 */
		return apply_filters( 'woocommerce_pos_font_pack_sources', self::PACKS );
	}

	/**
	 * Packs enabled for receipts.
	 *
	 * @return string[] Pack names.
	 */
	public static function packs(): array {
		/**
		 * Filters the enabled font packs so a site can drop one it does not need.
		 * Packs must have a source (see `woocommerce_pos_font_pack_sources`).
		 *
		 * @since 1.11.0
		 * @param string[] $packs Enabled pack names.
		 * @hook woocommerce_pos_font_packs
		 */
		return apply_filters( 'woocommerce_pos_font_packs', array_keys( self::sources() ) );
	}

	/**
	 * Install a pack once, caching expected failures.
	 *
	 * Every file is staged under a unique temporary name and verified before
	 * anything is renamed into place, so a failed or concurrent install never
	 * leaves a partial font behind and never removes a valid one.
	 *
	 * @param string $pack    Pack name.
	 * @param bool   $refresh Re-read the source manifest and reinstall when its version changed.
	 * @return bool Whether the pack is installed.
	 * @throws \RuntimeException Caught within the method; every failure becomes a false return.
	 */
	public function ensure( string $pack, bool $refresh = false ): bool {
		$sources = self::sources();
		if ( ! isset( $sources[ $pack ] ) ) {
			return false;
		}
		$dir       = $this->dir();
		$receipt   = $dir . '/' . $pack . '.json';
		$installed = $this->installed_manifest( $pack );
		if ( null !== $installed && ! $refresh ) {
			return true;
		}
		$failed = 'wcpos_font_pack_failed_' . $pack;
		$lock   = 'wcpos_font_pack_lock_' . $pack;
		if ( get_transient( $failed ) || get_transient( $lock ) ) {
			return null !== $installed;
		}
		set_transient( $lock, 1, self::LOCK_TTL );
		$staged    = array();
		$published = 0;
		try {
			$local    = $this->local_pack_dir( $pack );
			$local    = '' !== $local && is_file( $local . '/pack.json' ) ? $local : '';
			$base     = sprintf( $sources[ $pack ], Frontend::cdn_ref() );
			$json     = $this->read( $local, $base, 'pack.json' );
			$manifest = json_decode( $json, true );
			if ( ! is_array( $manifest['files'] ?? null ) || empty( $manifest['files'] ) || ! is_array( $manifest['families'] ?? null ) ) {
				throw new \RuntimeException( 'Invalid manifest' );
			}
			if ( null !== $installed && ( $installed['version'] ?? null ) === ( $manifest['version'] ?? null ) ) {
				return true;
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
				$staged[ $dir . '/' . $file ] = $this->stage( $dir . '/' . $file, $bytes );
			}
			// The receipt lands last: an interrupted rename set leaves the old
			// receipt (or none), so the next ensure() repairs the missing files.
			$staged[ $receipt ] = $this->stage( $receipt, $json );
			foreach ( $staged as $path => $temp ) {
				if ( ! @rename( $temp, $path ) ) {
					throw new \RuntimeException( 'Cannot write ' . esc_html( basename( $path ) ) );
				}
				unset( $staged[ $path ] );
				++$published;
			}
			$this->write_map( $dir );
			return true;
		} catch ( \RuntimeException $e ) {
			foreach ( $staged as $temp ) {
				@unlink( $temp );
			}
			if ( $published > 0 ) {
				// Some files were replaced before the failure: without its receipt the pack
				// no longer passes the installed check with mixed versions, and the next
				// ensure() repairs it whole instead of rendering with mismatched metrics.
				@unlink( $receipt );
			}
			set_transient( $failed, 1, self::FAILED_TTL );
			Logger::log( 'Font pack ' . $pack . ': ' . $e->getMessage() );
			return null !== $installed;
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Ensure all enabled packs, without short-circuiting after a failure.
	 *
	 * @param bool $refresh Reinstall packs whose source version changed (plugin upgrades).
	 * @return bool Whether every pack is installed.
	 * @throws \RuntimeException Caught within the method; a map write failure becomes a false return.
	 */
	public function ensure_all( bool $refresh = false ): bool {
		$success = true;
		foreach ( self::packs() as $pack ) {
			$success = $this->ensure( $pack, $refresh ) && $success;
		}
		try {
			// Installs of different packs hold separate locks and can race on the
			// shared map; rebuilding it from the receipts on disk self-heals that on
			// the next call, and writes nothing when it is already current.
			$this->write_map( $this->dir() );
		} catch ( \RuntimeException $e ) {
			Logger::log( 'Font packs: ' . $e->getMessage() );
			return false;
		}
		return $success;
	}

	/**
	 * Rebuild Dompdf's user font map from every installed pack receipt.
	 *
	 * @param string $dir Font directory.
	 * @throws \RuntimeException On a write failure.
	 */
	private function write_map( string $dir ): void {
		$families = array();
		foreach ( glob( $dir . '/*.json' ) as $path ) {
			if ( 'installed-fonts.json' !== basename( $path ) ) {
				$manifest = json_decode( (string) @file_get_contents( $path ), true );
				$families = array_merge( $families, $manifest['families'] ?? array() );
			}
		}
		if ( array() === $families ) {
			return;
		}
		$map  = $dir . '/installed-fonts.json';
		$json = (string) wp_json_encode( $families );
		if ( @file_get_contents( $map ) === $json ) {
			return;
		}
		if ( ! @rename( $this->stage( $map, $json ), $map ) ) {
			throw new \RuntimeException( 'Cannot write installed-fonts.json' );
		}
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
	 * The installed manifest when every file it lists is present, else null.
	 *
	 * @param string $pack Pack name.
	 * @return array|null Manifest.
	 */
	private function installed_manifest( string $pack ): ?array {
		$dir      = $this->dir();
		$receipt  = $dir . '/' . $pack . '.json';
		$manifest = is_file( $receipt ) ? json_decode( (string) @file_get_contents( $receipt ), true ) : null;
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return null;
		}
		foreach ( array_keys( $manifest['files'] ) as $file ) {
			if ( ! is_file( $dir . '/' . $file ) ) {
				return null;
			}
		}
		return $manifest;
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
				throw new \RuntimeException( 'Cannot read ' . esc_html( $file ) );
			}
			return $bytes;
		}
		$response = wp_remote_get( $base . $file, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'Download failed: ' . esc_html( $file ) . ' (' . esc_html( (string) ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) ) . ')' );
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Write bytes to a uniquely named temporary file beside the destination.
	 *
	 * @param string $path  Destination path.
	 * @param string $bytes File bytes.
	 * @return string Temporary file path, ready to rename into place.
	 * @throws \RuntimeException On a write failure.
	 */
	private function stage( string $path, string $bytes ): string {
		$temp = $path . '.' . uniqid( '', true ) . '.tmp';
		if ( strlen( $bytes ) !== @file_put_contents( $temp, $bytes ) ) {
			@unlink( $temp );
			throw new \RuntimeException( 'Cannot write ' . esc_html( basename( $path ) ) );
		}
		return $temp;
	}
}
