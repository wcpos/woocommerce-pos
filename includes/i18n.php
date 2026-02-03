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

/**
 * I18n class.
 *
 * Can be extended by pro plugin with different configuration.
 */
class i18n { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	private const CDN_BASE_URL = 'https://cdn.jsdelivr.net/gh/wcpos/translations@v%s/translations/php/%s/%s-%s.l10n.php';

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
		$this->version        = $version ?? VERSION;
		$this->languages_path = $languages_path ?? PLUGIN_PATH . 'languages/';
		$this->transient_key  = 'wcpos_i18n_' . $this->text_domain;

		$this->load_translations();
	}

	/**
	 * Load translations directly from plugin's languages folder.
	 * Downloads from jsDelivr if not cached or version changed.
	 */
	protected function load_translations(): void {
		$locale = determine_locale();

		// Skip English.
		if ( 'en_US' === $locale || empty( $locale ) ) {
			return;
		}

		$file = $this->languages_path . $this->text_domain . '-' . $locale . '.l10n.php';

		// Check if we need to download/update.
		$needs_download = false;
		if ( ! file_exists( $file ) ) {
			$needs_download = true;
		} else {
			$cached_version = get_transient( $this->transient_key . '_' . $locale );
			if ( $this->version !== $cached_version ) {
				$needs_download = true;
			}
		}

		if ( $needs_download ) {
			$downloaded = $this->download_translation( $locale, $file );
			if ( $downloaded ) {
				set_transient( $this->transient_key . '_' . $locale, $this->version, WEEK_IN_SECONDS );
			}
		}

		// Load the translation file if it exists.
		if ( file_exists( $file ) ) {
			load_textdomain( $this->text_domain, $file );
		}
	}

	/**
	 * Download a translation file from jsDelivr.
	 *
	 * @param string $locale The locale code (e.g., de_DE).
	 * @param string $file   The target file path.
	 *
	 * @return bool Whether the download was successful.
	 */
	protected function download_translation( string $locale, string $file ): bool {
		$url = sprintf( self::CDN_BASE_URL, $this->version, $locale, $this->text_domain, $locale );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
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
			return false;
		}

		return $wp_filesystem->put_contents( $file, $body, FS_CHMOD_FILE );
	}
}
