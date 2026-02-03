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
 */
class i18n { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	private const CDN_URL = 'https://cdn.jsdelivr.net/gh/wcpos/translations@v%s/translations/php/%s/woocommerce-pos-%s.l10n.php';
	private const TRANSIENT_KEY = 'wcpos_translation_version';

	/**
	 * Load translations from jsDelivr.
	 */
	public function __construct() {
		$this->load_translations();
	}

	/**
	 * Load translations directly from plugin's languages folder.
	 * Downloads from jsDelivr if not cached or version changed.
	 */
	private function load_translations(): void {
		$locale = determine_locale();

		// Skip English.
		if ( 'en_US' === $locale || empty( $locale ) ) {
			return;
		}

		$file = PLUGIN_PATH . 'languages/woocommerce-pos-' . $locale . '.l10n.php';

		// Check if we need to download/update.
		$needs_download = false;
		if ( ! file_exists( $file ) ) {
			$needs_download = true;
		} else {
			$cached_version = get_transient( self::TRANSIENT_KEY . '_' . $locale );
			if ( VERSION !== $cached_version ) {
				$needs_download = true;
			}
		}

		if ( $needs_download ) {
			$downloaded = $this->download_translation( $locale, $file );
			if ( $downloaded ) {
				set_transient( self::TRANSIENT_KEY . '_' . $locale, VERSION, WEEK_IN_SECONDS );
			}
		}

		// Load the translation file if it exists.
		if ( file_exists( $file ) ) {
			load_textdomain( 'woocommerce-pos', $file );
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
	private function download_translation( string $locale, string $file ): bool {
		$url = sprintf( self::CDN_URL, VERSION, $locale, $locale );

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
