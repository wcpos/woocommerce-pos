<?php
/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that its ready for translation.
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
	 * Load the plugin text domain for translation.
	 */
	public function construct() {
		load_plugin_textdomain( 'woocommerce-pos', false, PLUGIN_PATH . '/languages/' );
		add_filter( 'load_translation_file', array( $this, 'load_translation_file' ), 10, 2 );
	}

	/**
	 * Intercept translation file loading to provide on-demand downloads from jsDelivr.
	 *
	 * @param string $file   Path to the translation file.
	 * @param string $domain The text domain.
	 *
	 * @return string
	 */
	public function load_translation_file( string $file, string $domain ): string {
		if ( 'woocommerce-pos' !== $domain ) {
			return $file;
		}

		// Only handle .l10n.php files
		if ( '.l10n.php' !== substr( $file, -9 ) ) {
			return $file;
		}

		// If the file already exists, check if it needs updating
		if ( file_exists( $file ) ) {
			$cached_version = get_transient( self::TRANSIENT_KEY );
			if ( VERSION === $cached_version ) {
				return $file;
			}
		}

		// Extract locale from filename (e.g., woocommerce-pos-de_DE.l10n.php -> de_DE)
		$basename = basename( $file, '.l10n.php' );
		$locale   = str_replace( 'woocommerce-pos-', '', $basename );

		if ( empty( $locale ) || 'en_US' === $locale ) {
			return $file;
		}

		$downloaded = $this->download_translation( $locale, $file );
		if ( $downloaded ) {
			set_transient( self::TRANSIENT_KEY, VERSION, WEEK_IN_SECONDS );
		}

		return $file;
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

		// Ensure the directory exists
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Use WP_Filesystem for writing
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem->put_contents( $file, $body, FS_CHMOD_FILE );
	}
}
