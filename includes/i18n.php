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
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;

/**
 * I18n class.
 *
 * Can be extended by pro plugin with different configuration.
 */
class i18n { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	private const CDN_BASE_URL = 'https://cdn.jsdelivr.net/gh/wcpos/translations@%s/translations/php/%s/%s-%s.l10n.php';
	private const MISSING_LOCALE_CACHE_TTL = DAY_IN_SECONDS;

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
	 * Load translations from jsDelivr.
	 *
	 * @param string|null $text_domain    Optional text domain override.
	 * @param string|null $version        Optional version override.
	 * @param string|null $languages_path Optional languages path override.
	 */
	public function __construct( ?string $text_domain = null, ?string $version = null, ?string $languages_path = null ) {
		$this->text_domain    = $text_domain ?? 'woocommerce-pos';
		$this->version        = $version ?? TRANSLATION_VERSION;
		$this->languages_path = $languages_path ?? PLUGIN_PATH . 'languages/';
		$this->transient_key  = 'wcpos_i18n_' . $this->text_domain;

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

		$last_candidate_index = count( $locale_candidates ) - 1;
		foreach ( $locale_candidates as $index => $candidate_locale ) {
			$file       = $this->languages_path . $this->text_domain . '-' . $candidate_locale . '.l10n.php';
			$downloaded = $this->download_translation( $candidate_locale, $file, $index < $last_candidate_index );

			if ( $downloaded ) {
				set_transient( $this->transient_key . '_' . $candidate_locale, $this->version, WEEK_IN_SECONDS );
				delete_transient( $this->get_missing_locale_transient_key( $requested_locale ) );
				$this->load_translation_file( $candidate_locale, $file );

				return;
			}
		}

		set_transient( $this->get_missing_locale_transient_key( $requested_locale ), $this->version, self::MISSING_LOCALE_CACHE_TTL );

		if ( $stale_file && $stale_locale ) {
			$this->load_translation_file( $stale_locale, $stale_file );

			return;
		}

		Logger::log( sprintf( 'i18n: No translation file available for %s (%s)', $this->text_domain, $requested_locale ) );
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
		$this->maybe_convert_file_format( $file );

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
	 * Download a translation file from jsDelivr.
	 *
	 * @param string $locale            The locale code (e.g., de_DE).
	 * @param string $file              The target file path.
	 * @param bool   $suppress_404_logs Suppress 404 logging for fallback attempts.
	 *
	 * @return bool Whether the download was successful.
	 */
	protected function download_translation( string $locale, string $file, bool $suppress_404_logs = false ): bool {
		$url = sprintf( self::CDN_BASE_URL, $this->version, $locale, $this->text_domain, $locale );

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

		// Ensure the directory exists.
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Use WP_Filesystem for writing.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Verify WP_Filesystem initialized successfully.
		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			Logger::log( sprintf( 'i18n: Failed to write %s translation - WP_Filesystem not available', $locale ) );

			return false;
		}

		$written = $wp_filesystem->put_contents( $file, $body, FS_CHMOD_FILE );
		if ( ! $written ) {
			Logger::log( sprintf( 'i18n: Failed to write %s translation to %s', $locale, $file ) );
		}

		return $written;
	}
}
