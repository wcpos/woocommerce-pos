<?php
/**
 * Define the internationalization functionality.
 *
 * Loads translations from jsDelivr CDN, downloading on-demand to the plugin's
 * languages folder. This bypasses WordPress.org translations entirely.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Logger;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;

/**
 * I18n class.
 *
 * Can be extended by pro plugin with different configuration.
 */
class i18n { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	private const CDN_BASE_URL = 'https://cdn.jsdelivr.net/gh/wcpos/translations@%s/translations/php/%s/%s-%s.l10n.php';
	private const MISSING_LOCALE_CACHE_TTL = DAY_IN_SECONDS;
	private const WRITE_FAILED_CACHE_TTL = HOUR_IN_SECONDS;
	private const DOWNLOAD_LOCK_TTL = 30;

	/**
	 * Text domain for the plugin.
	 *
	 * @var string
	 */
	protected string $text_domain = 'woocommerce-pos';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected string $version;

	/**
	 * Path to the plugin's languages folder.
	 *
	 * @var string
	 */
	protected string $languages_path;

	/**
	 * Transient key prefix for caching.
	 *
	 * @var string
	 */
	protected string $transient_key = 'wcpos_i18n_version';

	/**
	 * Most recent HTTP status code from translation download attempt.
	 *
	 * Null means the failure was not an HTTP status response (network/transport/write error).
	 *
	 * @var int|null
	 */
	protected ?int $last_download_status_code = null;

	/**
	 * Whether the last download attempt failed due to filesystem write errors.
	 *
	 * @var bool
	 */
	protected bool $last_write_failed = false;

	/**
	 * Load translations from jsDelivr.
	 *
	 * @param string|null $text_domain    Optional text domain override.
	 * @param string|null $version        Optional version override.
	 * @param string|null $languages_path Optional languages path override.
	 */
	public function __construct( ?string $text_domain = null, ?string $version = null, ?string $languages_path = null ) {
		$this->text_domain    = $text_domain ?? 'woocommerce-pos';
		$this->version        = $version ?? TRANSLATION_VERSION;
		$this->transient_key  = 'wcpos_i18n_' . $this->text_domain;
		$this->languages_path = $languages_path ?? $this->resolve_languages_path();

		$this->load_translations();
	}

	/**
	 * Load translations directly from plugin's languages folder.
	 * Downloads from jsDelivr if not cached or version changed.
	 */
	protected function load_translations(): void {
		$requested_locale = determine_locale();

		// Skip English.
		if ( 'en_US' === $requested_locale || empty( $requested_locale ) ) {
			return;
		}

		$locale_candidates = $this->get_locale_candidates( $requested_locale );
		$stale_file        = null;
		$stale_locale      = null;

		// Prefer an up-to-date local file, including base-language fallback.
		foreach ( $locale_candidates as $candidate_locale ) {
			$file           = $this->languages_path . $this->text_domain . '-' . $candidate_locale . '.l10n.php';
			$cached_version = get_transient( $this->transient_key . '_' . $candidate_locale );

			if ( file_exists( $file ) && $this->version === $cached_version ) {
				delete_transient( $this->get_missing_locale_transient_key( $requested_locale ) );
				$this->load_translation_file( $candidate_locale, $file );

				return;
			}

			if ( file_exists( $file ) && null === $stale_file ) {
				$stale_file   = $file;
				$stale_locale = $candidate_locale;
			}
		}

		// Avoid repeated fetch attempts when we already know this locale is missing for this version.
		if ( get_transient( $this->get_missing_locale_transient_key( $requested_locale ) ) === $this->version ) {
			if ( $stale_file && $stale_locale ) {
				$this->load_translation_file( $stale_locale, $stale_file );
			}

			return;
		}

		// Avoid repeated download attempts when filesystem is not writable.
		if ( get_transient( $this->get_write_failed_transient_key() ) === $this->version ) {
			if ( $stale_file && $stale_locale ) {
				$this->load_translation_file( $stale_locale, $stale_file );
			}

			return;
		}

		// Prevent thundering herd: if another request is already downloading, skip.
		$download_lock_key = $this->get_download_lock_transient_key( $requested_locale );
		if ( get_transient( $download_lock_key ) ) {
			if ( $stale_file && $stale_locale ) {
				$this->load_translation_file( $stale_locale, $stale_file );
			}

			return;
		}

		// Acquire download lock before attempting HTTP requests.
		set_transient( $download_lock_key, true, self::DOWNLOAD_LOCK_TTL );

		try {
			$last_candidate_index = count( $locale_candidates ) - 1;
			$all_candidates_404   = true;
			foreach ( $locale_candidates as $index => $candidate_locale ) {
				$file       = $this->languages_path . $this->text_domain . '-' . $candidate_locale . '.l10n.php';
				$downloaded = $this->download_translation( $candidate_locale, $file, $index < $last_candidate_index );

				if ( $downloaded ) {
					// Recompute file path — download_translation() may have switched to fallback path.
					$file = $this->languages_path . $this->text_domain . '-' . $candidate_locale . '.l10n.php';
					set_transient( $this->transient_key . '_' . $candidate_locale, $this->version, WEEK_IN_SECONDS );
					delete_transient( $this->get_missing_locale_transient_key( $requested_locale ) );
					delete_transient( $this->get_write_failed_transient_key() );
					$this->load_translation_file( $candidate_locale, $file );

					return;
				}

				if ( 404 !== $this->last_download_status_code ) {
					$all_candidates_404 = false;
				}
			}

			if ( $all_candidates_404 ) {
				set_transient( $this->get_missing_locale_transient_key( $requested_locale ), $this->version, self::MISSING_LOCALE_CACHE_TTL );
			} elseif ( $this->last_write_failed ) {
				set_transient( $this->get_write_failed_transient_key(), $this->version, self::WRITE_FAILED_CACHE_TTL );
			}

			if ( $stale_file && $stale_locale ) {
				$this->load_translation_file( $stale_locale, $stale_file );

				return;
			}

			Logger::log( sprintf( 'i18n: No translation file available for %s (%s)', $this->text_domain, $requested_locale ) );
		} finally {
			// Release download lock — runs even if an exception is thrown.
			delete_transient( $download_lock_key );
		}
	}

	/**
	 * Get locale candidates in order of preference.
	 *
	 * For regional locales (e.g., da_DK), return both the full locale and the
	 * base language fallback (da).
	 *
	 * @param string $locale Requested locale.
	 *
	 * @return string[]
	 */
	protected function get_locale_candidates( string $locale ): array {
		$candidates = array( $locale );

		if ( false !== strpos( $locale, '_' ) ) {
			$base_locale = explode( '_', $locale )[0];
			if ( ! empty( $base_locale ) ) {
				$candidates[] = $base_locale;
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Load an existing translation file.
	 *
	 * @param string $locale Locale code for the file.
	 * @param string $file   Path to the l10n PHP file.
	 */
	protected function load_translation_file( string $locale, string $file ): void {
		try {
			$this->maybe_convert_file_format( $file );
		} catch ( \ParseError $e ) {
			// File is corrupt — delete it and clear the version transient so it re-downloads.
			Logger::log( sprintf( 'i18n: Corrupt translation file deleted (%s): %s', $file, $e->getMessage() ) );
			wp_delete_file( $file );
			delete_transient( $this->transient_key . '_' . $locale );

			return;
		}

		// Pass the .mo path — WordPress internally looks for .l10n.php first.
		$mofile = $this->languages_path . $this->text_domain . '-' . $locale . '.mo';
		load_textdomain( $this->text_domain, $mofile );
	}

	/**
	 * Build the transient key used for missing-locale caching.
	 *
	 * @param string $locale Requested locale.
	 *
	 * @return string
	 */
	protected function get_missing_locale_transient_key( string $locale ): string {
		return $this->transient_key . '_missing_' . $locale;
	}

	/**
	 * Get the fallback languages path using the uploads directory.
	 *
	 * Used when the primary languages path (WP_LANG_DIR/plugins/) is not writable.
	 * The uploads directory is writable on any functioning WordPress install.
	 *
	 * @return string
	 */
	protected function get_fallback_languages_path(): string {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
	}

	/**
	 * Build the transient key used for write-failure caching.
	 *
	 * @return string
	 */
	protected function get_write_failed_transient_key(): string {
		return $this->transient_key . '_write_failed';
	}

	/**
	 * Build the transient key used for download-in-progress locking.
	 *
	 * @param string $locale Requested locale.
	 *
	 * @return string
	 */
	protected function get_download_lock_transient_key( string $locale ): string {
		return $this->transient_key . '_downloading_' . $locale;
	}

	/**
	 * Determine the languages path to use.
	 *
	 * Checks if a previous session fell back to the uploads directory and
	 * returns that path if so. Otherwise returns the standard WordPress
	 * languages/plugins/ directory.
	 *
	 * @return string
	 */
	protected function resolve_languages_path(): string {
		$active = get_transient( $this->transient_key . '_active_path' );
		if ( 'uploads' === $active ) {
			return $this->get_fallback_languages_path();
		}

		return WP_LANG_DIR . '/plugins/';
	}

	/**
	 * Ensure .l10n.php file uses WordPress 6.5+ format with 'messages' key.
	 *
	 * CDN files use a flat array format, but WordPress expects:
	 *   array( 'messages' => array( 'key' => 'translation', ... ) )
	 *
	 * @param string $file The .l10n.php file path.
	 */
	protected function maybe_convert_file_format( string $file ): void {
		$data = include $file;

		if ( ! is_array( $data ) || isset( $data['messages'] ) ) {
			return;
		}

		// Wrap flat translations array in WordPress expected format.
		$wrapped = "<?php\nreturn array(\n\t'messages' => " . var_export( $data, true ) . ",\n);\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generating a PHP translation file.

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			$wp_filesystem->put_contents( $file, $wrapped, FS_CHMOD_FILE );
		}
	}

	/**
	 * Write translation content to a file using WP_Filesystem.
	 *
	 * @param string $file The target file path.
	 * @param string $body The file content to write.
	 *
	 * @return bool Whether the write was successful.
	 */
	protected function write_translation_file( string $file, string $body ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			return false;
		}

		if ( ! $wp_filesystem->put_contents( $file, $body, FS_CHMOD_FILE ) ) {
			return false;
		}

		// Verify the write was complete (catches partial/truncated writes).
		$written_size = $wp_filesystem->size( $file );
		if ( false === $written_size || strlen( $body ) !== $written_size ) {
			Logger::log( sprintf( 'i18n: Write verification failed — expected %d bytes, got %s', strlen( $body ), var_export( $written_size, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Logging diagnostic info.
			wp_delete_file( $file );

			return false;
		}

		return true;
	}

	/**
	 * Download a translation file from jsDelivr.
	 *
	 * Tries writing to the primary languages path first. If that fails,
	 * falls back to the uploads directory. If both fail, sets
	 * $last_write_failed so the caller can cache the failure.
	 *
	 * @param string $locale            The locale code (e.g., de_DE).
	 * @param string $file              The target file path.
	 * @param bool   $suppress_404_logs Suppress 404 logging for fallback attempts.
	 *
	 * @return bool Whether the download and write was successful.
	 */
	protected function download_translation( string $locale, string $file, bool $suppress_404_logs = false ): bool {
		$url = sprintf( self::CDN_BASE_URL, $this->version, $locale, $this->text_domain, $locale );
		$this->last_download_status_code = null;
		$this->last_write_failed         = false;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::log( sprintf( 'i18n: Failed to download %s translation - HTTP error: %s', $locale, $response->get_error_message() ) );

			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->last_download_status_code = $status_code;

			if ( ! ( $suppress_404_logs && 404 === $status_code ) ) {
				Logger::log( sprintf( 'i18n: Failed to download %s translation - HTTP %d from %s', $locale, $status_code, $url ) );
			}

			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			Logger::log( sprintf( 'i18n: Failed to download %s translation - empty response body from %s', $locale, $url ) );

			return false;
		}

		// Validate the response is a PHP translation file (catches truncated downloads).
		if ( 0 !== strpos( $body, '<?php' ) || false === strpos( $body, 'return' ) ) {
			Logger::log( sprintf( 'i18n: Downloaded %s translation is not valid PHP — possible truncated download from %s', $locale, $url ) );

			return false;
		}

		// Try writing to primary path.
		if ( $this->write_translation_file( $file, $body ) ) {
			return true;
		}

		// Primary write failed — try uploads fallback.
		$fallback_path = $this->get_fallback_languages_path();
		if ( $fallback_path !== $this->languages_path ) {
			$fallback_file = $fallback_path . basename( $file );
			if ( $this->write_translation_file( $fallback_file, $body ) ) {
				Logger::log( sprintf( 'i18n: Primary path not writable, using fallback for %s translations: %s', $locale, $fallback_path ) );
				$this->languages_path = $fallback_path;
				set_transient( $this->transient_key . '_active_path', 'uploads', MONTH_IN_SECONDS );

				return true;
			}
		}

		// Both paths failed (or already at fallback and it failed).
		$this->last_write_failed = true;
		Logger::log( sprintf( 'i18n: Failed to write %s translation to any writable location', $locale ) );

		return false;
	}
}
