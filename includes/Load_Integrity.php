<?php
/**
 * Load-time integrity defence.
 *
 * WordPress swaps a plugin's directory out from under in-flight requests during
 * an update: the old tree is renamed aside for 200+ ms (longer when rename()
 * falls back to copy_dir()), and an upload "Replace current" deletes it before
 * copying. Composer's classmap then include()s a file that is not there and the
 * request dies with "Class not found". This class is the fallback for that
 * window and the classifier that says what happened when the fallback misses.
 *
 * It runs exactly when other WCPOS files may be missing, so it is loaded at
 * boot (Composer `files`) and never autoloaded. The loader itself needs nothing
 * but PHP; reporting goes through Error_Reporter on a best-effort basis.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 * Fallback autoloader and missing-class classifier.
 */
class Load_Integrity {
	/**
	 * The only namespace handled. The trailing separator keeps WooCommercePOSPro out.
	 */
	const PREFIX = 'WCPOS\\WooCommercePOS\\';

	/**
	 * One wait per request before retrying a shipped class's missing or torn file,
	 * in microseconds. Core's rename-aside leaves the directory absent for ~200 ms.
	 */
	const RETRY_WAIT_US = 250000;

	/**
	 * WordPress ignores a .maintenance file older than ten minutes (wp_is_maintenance_mode()).
	 */
	const MAINTENANCE_TTL = 600;

	/**
	 * The includes/ directory of the copy that registered the fallback.
	 *
	 * @var string|null
	 */
	private static $base_dir = null;

	/**
	 * Whether this request has already spent its one retry wait.
	 *
	 * @var bool
	 */
	private static $waited = false;

	/**
	 * Missing classes already reported this request, so the fatal reporter skips them.
	 *
	 * @var array<string, true>
	 */
	private static $reported = array();

	/**
	 * Append the fallback after Composer. Idempotent; the first copy to register wins.
	 *
	 * @param string $includes_dir The includes/ directory of the calling copy.
	 */
	public static function register( string $includes_dir ): void {
		if ( null !== self::$base_dir ) {
			return;
		}
		self::$base_dir = rtrim( str_replace( '\\', '/', $includes_dir ), '/' ) . '/';
		spl_autoload_register( array( self::class, 'autoload' ), true, false );
		// vendor/autoload.php also loads outside WordPress (PHPUnit, tooling).
		if ( \function_exists( 'add_filter' ) ) {
			add_filter( 'wp_php_error_args', array( self::class, 'fatal_error_args' ), 10, 2 );
		}
	}

	/**
	 * SPL entry point. Free until a WCPOS class misses every earlier loader.
	 *
	 * @param string $class_name Class being loaded.
	 */
	public static function autoload( string $class_name ): void {
		self::load( $class_name, (string) self::$base_dir );
	}

	/**
	 * Include the PSR-4 file for a class. Nothing is cached, so a class that misses
	 * now still loads if its file appears later in the request. A class Composer
	 * shipped gets one retry after a wait; a speculative class_exists() probe for a
	 * class that never existed does not pay for it.
	 *
	 * @param string $class_name Class being loaded.
	 * @param string $base_dir   includes/ directory, with a trailing slash.
	 *
	 * @return bool Whether the class is now declared.
	 */
	public static function load( string $class_name, string $base_dir ): bool {
		$file = self::file_for( $class_name, $base_dir );
		if ( null === $file ) {
			return false;
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( 1 === $attempt ) {
				if ( self::$waited || ! self::is_shipped( $class_name ) ) {
					break;
				}
				self::$waited = true;
				usleep( self::RETRY_WAIT_US );
				clearstatcache( true, $file );
			}
			try {
				if ( is_file( $file ) ) {
					include $file;
				}
			} catch ( \Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// A torn file mid-copy: treat as a miss and retry.
			}
			if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a Composer class map loaded this request lists the class.
	 *
	 * @param string $class_name Class name.
	 */
	private static function is_shipped( string $class_name ): bool {
		if ( ! class_exists( \Composer\Autoload\ClassLoader::class, false ) || ! method_exists( \Composer\Autoload\ClassLoader::class, 'getRegisteredLoaders' ) ) {
			return false;
		}
		foreach ( \Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader ) {
			if ( isset( $loader->getClassMap()[ $class_name ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map a WCPOS class to its PSR-4 file.
	 *
	 * @param string $class_name Class name.
	 * @param string $base_dir   includes/ directory, with a trailing slash.
	 *
	 * @return string|null File path, or null for a class outside the prefix.
	 */
	public static function file_for( string $class_name, string $base_dir ): ?string {
		$class_name = ltrim( $class_name, '\\' );
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}
		return $base_dir . str_replace( '\\', '/', substr( $class_name, \strlen( self::PREFIX ) ) ) . '.php';
	}

	/**
	 * Extract the WCPOS class from a "Class not found" message (PHP 7 or 8 quoting).
	 *
	 * @param string $message Error message.
	 *
	 * @return string|null The missing class, or null for any other error.
	 */
	public static function missing_class( string $message ): ?string {
		if ( preg_match( '/(?:Class|Interface|Trait|Enum) ["\']\\\\?(WCPOS\\\\WooCommercePOS\\\\[A-Za-z0-9_\\\\]+)["\'] not found/', $message, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Probe the filesystem for update debris. Stat calls only; failure path only.
	 *
	 * @param string      $class_name  The missing class.
	 * @param string|null $abspath     WordPress root (test seam).
	 * @param string|null $content_dir wp-content directory (test seam).
	 *
	 * @return array{path:string, file_exists_now:bool, maintenance_age:int|null, temp_backup:bool, upgrade_dir:bool, updater_lock:bool, disk_version:string}
	 */
	public static function signals( string $class_name, ?string $abspath = null, ?string $content_dir = null ): array {
		$base        = self::$base_dir ?? PLUGIN_PATH . 'includes/';
		$file        = (string) self::file_for( $class_name, $base );
		$abspath     = trailingslashit( $abspath ?? ABSPATH );
		$content_dir = trailingslashit( $content_dir ?? WP_CONTENT_DIR );
		$slug        = self::slug( $base );

		clearstatcache();
		$maintenance = is_file( $abspath . '.maintenance' ) ? filemtime( $abspath . '.maintenance' ) : false;
		$main_file   = \dirname( $base ) . '/woocommerce-pos.php';
		$disk        = is_readable( $main_file ) ? get_file_data( $main_file, array( 'Version' => 'Version' ), 'plugin' ) : array();

		return array(
			'path'            => 'includes/' . substr( $file, \strlen( $base ) ),
			'file_exists_now' => is_file( $file ),
			'maintenance_age' => false === $maintenance ? null : max( 0, time() - $maintenance ),
			'temp_backup'     => is_dir( $content_dir . 'upgrade-temp-backup/plugins/' . $slug ),
			'upgrade_dir'     => ! empty( glob( $content_dir . 'upgrade/' . $slug . '*', GLOB_NOSORT ) ),
			'updater_lock'    => false !== get_option( 'auto_updater.lock', false ),
			'disk_version'    => (string) ( $disk['Version'] ?? '' ),
		);
	}

	/**
	 * Name what most likely happened.
	 *
	 * @param array $signals Output of signals().
	 *
	 * @return string mid_update, swapped_mid_request or files_missing.
	 */
	public static function classify( array $signals ): string {
		$fresh_maintenance = null !== $signals['maintenance_age'] && $signals['maintenance_age'] < self::MAINTENANCE_TTL;
		if ( $signals['file_exists_now'] || $fresh_maintenance || $signals['temp_backup'] || $signals['upgrade_dir'] || $signals['updater_lock'] ) {
			return 'mid_update';
		}
		if ( '' !== $signals['disk_version'] && VERSION !== $signals['disk_version'] ) {
			return 'swapped_mid_request';
		}
		return 'files_missing';
	}

	/**
	 * Classify a missing class and report it once per request.
	 *
	 * @param string $class_name The missing class.
	 *
	 * @return array{classification:string, path:string} What was found.
	 */
	public static function report( string $class_name ): array {
		$signals = self::signals( $class_name );
		$result  = array(
			'classification' => self::classify( $signals ),
			'path'           => $signals['path'],
		);
		if ( ! isset( self::$reported[ $class_name ] ) ) {
			self::$reported[ $class_name ] = true;
			try {
				Services\Error_Reporter::instance()->report_integrity( $result['classification'], $result['path'], 'Class "' . $class_name . '" not found' );
			} catch ( \Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// The reporter's own file may be the one missing.
			}
		}
		return $result;
	}

	/**
	 * Whether report() already sent this class this request.
	 *
	 * @param string $class_name Class name.
	 */
	public static function was_reported( string $class_name ): bool {
		return isset( self::$reported[ $class_name ] );
	}

	/**
	 * A retryable 503 for a WCPOS class missing because of an update, or null for
	 * any other error. Files that are simply missing stay an error: retrying
	 * would never fix them.
	 *
	 * @param \Error $error Error thrown inside a WCPOS route.
	 *
	 * @return \WP_REST_Response|null
	 */
	public static function rest_unavailable( \Error $error ): ?\WP_REST_Response {
		$class_name = self::missing_class( $error->getMessage() );
		if ( null === $class_name ) {
			return null;
		}
		$found = self::report( $class_name );
		if ( ! self::is_transient( $found['classification'] ) ) {
			return null;
		}
		$response = new \WP_REST_Response(
			array(
				'code'    => 'wcpos_updating',
				'message' => __( 'WCPOS is being updated on this site. Please try again in a few seconds.', 'woocommerce-pos' ),
				'data'    => array(
					'status'         => 503,
					'classification' => $found['classification'],
				),
			),
			503
		);
		$response->header( 'Retry-After', '5' );
		return $response;
	}

	/**
	 * Turn WordPress's fatal-error page into a retryable 503 for a WCPOS REST
	 * request that died on a WCPOS class an update moved aside. This covers the
	 * stages before dispatch (authentication, CORS, permission checks). The fatal
	 * itself is untouched and still reported at shutdown.
	 *
	 * @param array $args  wp_die() arguments.
	 * @param array $error error_get_last() of the fatal.
	 *
	 * @return array
	 */
	public static function fatal_error_args( $args, $error ) {
		$uri        = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$class_name = \is_array( $error ) ? self::missing_class( (string) ( $error['message'] ?? '' ) ) : null;
		if ( \is_array( $args ) && null !== $class_name && preg_match( '#wcpos/v\d#', $uri ) && self::is_transient( self::classify( self::signals( $class_name ) ) ) ) {
			$args['response'] = 503;
			if ( ! headers_sent() ) {
				header( 'Retry-After: 5' );
			}
		}
		return $args;
	}

	/**
	 * Whether a classification is an update in flight, which a retry outlasts.
	 *
	 * @param string $classification Classification.
	 */
	private static function is_transient( string $classification ): bool {
		return 'files_missing' !== $classification;
	}

	/**
	 * The plugin directory slug that owns a copy: woocommerce-pos, or Pro's slug for its vendored copy.
	 *
	 * @param string $base includes/ directory.
	 */
	private static function slug( string $base ): string {
		$plugins = trailingslashit( str_replace( '\\', '/', WP_PLUGIN_DIR ) );
		if ( 0 === strpos( $base, $plugins ) ) {
			return strtok( substr( $base, \strlen( $plugins ) ), '/' );
		}
		return PLUGIN_NAME;
	}
}
