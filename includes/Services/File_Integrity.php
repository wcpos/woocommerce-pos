<?php
/**
 * Offline checksum check of the installed plugin files.
 *
 * Compares the shipped files with WordPress.org's published checksums, or
 * with checksums.json at the plugin root when present (Pro and other builds
 * not served by WordPress.org). Runs a minute after this plugin is updated,
 * once a day, and from Site Health — never on a normal request.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use const WCPOS\WooCommercePOS\PLUGIN_FILE;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * File integrity check.
 */
class File_Integrity {
	/**
	 * Cron hook for the daily and post-update checks.
	 */
	const CRON_HOOK = 'wcpos_file_integrity_check';

	/**
	 * Option holding the last result.
	 */
	const RESULT_OPTION = 'woocommerce_pos_file_integrity';

	/**
	 * WordPress.org checksum endpoint: slug, version.
	 */
	const CHECKSUMS_URL = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';

	/**
	 * Seconds to wait for WordPress.org. Cron and Site Health must not hang on it.
	 */
	const REMOTE_TIMEOUT = 3;

	/**
	 * Seconds after an update before checking, so an in-flight copy has finished.
	 */
	const POST_UPDATE_DELAY = 60;

	/**
	 * Paths listed in a report or Site Health before truncating.
	 */
	const MAX_LISTED = 20;

	/**
	 * Composer-generated files that embed a per-build hash or commit. The GitHub
	 * release zip and the WordPress.org build of one version differ only in
	 * these (checked for 1.10.27), so their content is never compared; they
	 * still count when missing.
	 */
	const BUILD_STAMPED = array(
		'vendor/autoload.php',
		'vendor/composer/autoload_real.php',
		'vendor/composer/autoload_static.php',
		'vendor/composer/installed.php',
	);

	/**
	 * A stored result older than this is refreshed in the background.
	 */
	const STALE_AFTER = 2 * DAY_IN_SECONDS;

	/**
	 * A daily run reuses a result this fresh: every site of a network schedules
	 * its own daily event, but the files (and the stored result) are shared.
	 */
	const REUSE_WITHIN = 20 * HOUR_IN_SECONDS;

	/** Register the cron listener, the update trigger and the Site Health test. */
	public static function register(): void {
		add_action(
			self::CRON_HOOK,
			static function ( $context = 'daily' ): void {
				self::run( (string) $context );
			}
		);
		add_action( 'upgrader_process_complete', array( self::class, 'on_upgrade' ), 10, 2 );
		add_filter( 'site_status_tests', array( self::class, 'site_status_tests' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Schedule a check shortly after this plugin is updated or replaced by upload.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param mixed $data     Upgrade details.
	 */
	public static function on_upgrade( $upgrader, $data ): void {
		if ( ! \is_array( $data ) || 'plugin' !== ( $data['type'] ?? '' ) ) {
			return;
		}
		$plugins = isset( $data['plugins'] ) && \is_array( $data['plugins'] ) ? $data['plugins'] : array();
		if ( isset( $data['plugin'] ) ) {
			$plugins[] = $data['plugin'];
		}
		$ours = \in_array( PLUGIN_FILE, $plugins, true );
		if ( ! $ours && 'install' === ( $data['action'] ?? '' ) && \is_object( $upgrader ) && isset( $upgrader->result['destination_name'] ) ) {
			$ours = \dirname( PLUGIN_FILE ) === $upgrader->result['destination_name'];
		}
		if ( $ours ) {
			wp_schedule_single_event( time() + self::POST_UPDATE_DELAY, self::CRON_HOOK, array( 'post_update' ) );
		}
	}

	/**
	 * Check, store the result, and report a new failure.
	 *
	 * @param string     $context Why the check ran: daily, post_update or site_health.
	 * @param array|null $target  Root, version and slug (test seam).
	 *
	 * @return array The result.
	 */
	public static function run( string $context = 'daily', ?array $target = null ): array {
		$previous = self::stored( $target );
		if ( 'daily' === $context && null === $target && null !== $previous && ( $previous['checked'] ?? 0 ) > time() - self::REUSE_WITHIN ) {
			return $previous;
		}
		$result = self::check( $target );
		self::store( $result, $target );

		$failed = \in_array( $result['status'], array( 'files_missing', 'files_modified' ), true );
		$same   = null !== $previous && array_intersect_key( $previous, array_flip( array( 'status', 'missing', 'modified', 'version' ) ) ) === array_intersect_key( $result, array_flip( array( 'status', 'missing', 'modified', 'version' ) ) );
		if ( $failed && ! $same ) {
			$paths = array_merge( $result['missing'], $result['modified'] );
			Error_Reporter::instance()->report_integrity(
				$result['status'],
				$paths[0],
				\sprintf( 'WCPOS %s file check (%s): %d missing, %d modified: %s', $result['version'], $context, \count( $result['missing'] ), \count( $result['modified'] ), implode( ', ', \array_slice( $paths, 0, self::MAX_LISTED ) ) )
			);
		}

		return $result;
	}

	/**
	 * Compare the installed files with the published checksums.
	 *
	 * @param array|null $target Root, version and slug; defaults to the running install.
	 *
	 * @return array{status:string, missing:string[], modified:string[], version:string, checked:int}
	 */
	public static function check( ?array $target = null ): array {
		$target = $target ?? self::target();
		$result = array(
			'status'   => 'unknown',
			'missing'  => array(),
			'modified' => array(),
			'version'  => (string) $target['version'],
			'checked'  => time(),
		);

		// A git checkout (or worktree, where .git is a file) is a development copy.
		if ( file_exists( $target['root'] . '.git' ) ) {
			return $result;
		}
		$files = self::manifest( $target['root'], $result['version'], $target['slug'] );
		if ( null === $files ) {
			return $result;
		}

		$result           = array_merge( $result, self::compare( $target['root'], $files ) );
		$result['status'] = $result['missing'] ? 'files_missing' : ( $result['modified'] ? 'files_modified' : 'ok' );

		return $result;
	}

	/**
	 * Compare files on disk with a manifest. Files not in the manifest are ignored.
	 *
	 * @param string $root  Plugin root, with a trailing slash.
	 * @param array  $files Path => array( 'sha256' => hash|hash[], 'md5' => hash|hash[] ).
	 *
	 * @return array{missing:string[], modified:string[]}
	 */
	public static function compare( string $root, array $files ): array {
		$missing  = array();
		$modified = array();
		foreach ( $files as $path => $sums ) {
			$path = (string) $path;
			if ( false !== strpos( $path, '..' ) || ! \is_array( $sums ) ) {
				continue;
			}
			$file = $root . ltrim( $path, '/' );
			if ( ! is_file( $file ) ) {
				$missing[] = $path;
				continue;
			}
			if ( \in_array( $path, self::BUILD_STAMPED, true ) ) {
				continue;
			}
			$algo     = isset( $sums['sha256'] ) ? 'sha256' : 'md5';
			$expected = (array) ( $sums[ $algo ] ?? array() );
			if ( $expected && ! \in_array( hash_file( $algo, $file ), $expected, true ) ) {
				$modified[] = $path;
			}
		}

		return array(
			'missing'  => $missing,
			'modified' => $modified,
		);
	}

	/**
	 * Add the Site Health test.
	 *
	 * @param array $tests Site Health tests.
	 *
	 * @return array
	 */
	public static function site_status_tests( $tests ) {
		$tests['direct']['wcpos_file_integrity'] = array(
			'label' => __( 'WCPOS file integrity', 'woocommerce-pos' ),
			'test'  => array( self::class, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health result, read from the last check so the page never waits on a
	 * download or on hashing. A missing or stale result queues a check.
	 *
	 * @return array
	 */
	public static function site_health_test(): array {
		$result = self::stored();
		if ( null === $result || ( $result['checked'] ?? 0 ) < time() - self::STALE_AFTER ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( 'site_health' ) );
		}
		if ( null === $result ) {
			$result = array(
				'status'   => 'pending',
				'missing'  => array(),
				'modified' => array(),
			);
		}
		$test = array(
			'test'        => 'wcpos_file_integrity',
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'WCPOS', 'woocommerce-pos' ),
				'color' => 'blue',
			),
			'label'       => __( 'WCPOS files match the published checksums', 'woocommerce-pos' ),
			'description' => '<p>' . esc_html__( 'Every WCPOS plugin file matches the checksums published for this version.', 'woocommerce-pos' ) . '</p>',
			'actions'     => '',
		);

		if ( 'pending' === $result['status'] ) {
			$test['label']       = __( 'WCPOS files have not been checked yet', 'woocommerce-pos' );
			$test['description'] = '<p>' . esc_html__( 'A check of the WCPOS plugin files has been queued. Reload this page in a minute to see the result.', 'woocommerce-pos' ) . '</p>';
		} elseif ( 'unknown' === $result['status'] ) {
			$test['label']       = __( 'WCPOS files could not be verified', 'woocommerce-pos' );
			$test['description'] = '<p>' . esc_html__( 'No checksums are published for this version of WCPOS, so its files were not compared. Development copies and older builds that do not ship checksums.json have none.', 'woocommerce-pos' ) . '</p>';
		} elseif ( 'ok' !== $result['status'] ) {
			$paths               = array_merge( $result['missing'], $result['modified'] );
			$items               = implode( '', array_map( static fn ( $path ) => '<li><code>' . esc_html( $path ) . '</code></li>', \array_slice( $paths, 0, self::MAX_LISTED ) ) );
			// A missing file breaks loading; a modified one may be a deliberate edit.
			$test['status']      = 'files_missing' === $result['status'] ? 'critical' : 'recommended';
			$test['badge']['color'] = 'critical' === $test['status'] ? 'red' : 'orange';
			$test['label']       = __( 'Some WCPOS files are missing or modified', 'woocommerce-pos' );
			$test['description'] = '<p>' . esc_html(
				\sprintf(
					/* translators: 1: number of missing files, 2: number of modified files. */
					__( '%1$d files are missing and %2$d files differ from the published version. An interrupted update can cause this, and it can make WCPOS fail to load.', 'woocommerce-pos' ),
					\count( $result['missing'] ),
					\count( $result['modified'] )
				)
			) . '</p><ul>' . $items . '</ul>';
			$test['actions']     = '<p>' . esc_html__( 'Reinstall the plugin to restore its files.', 'woocommerce-pos' ) . '</p>';
		}

		return $test;
	}

	/**
	 * The stored result for a plugin folder and version. Results live in one
	 * network-wide option, keyed by folder: the sites of a network share files,
	 * but one network can run free on some sites and Pro on others.
	 *
	 * @param array|null $target Root, version and slug; defaults to the running install.
	 *
	 * @return array|null The result, or null when that folder and version were never checked.
	 */
	public static function stored( ?array $target = null ): ?array {
		$target  = $target ?? self::target();
		$results = get_site_option( self::RESULT_OPTION, array() );
		$result  = \is_array( $results ) ? ( $results[ md5( $target['root'] ) ] ?? null ) : null;

		return \is_array( $result ) && ( $result['version'] ?? '' ) === (string) $target['version'] ? $result : null;
	}

	/**
	 * Store a result for a plugin folder.
	 *
	 * @param array      $result Result of check().
	 * @param array|null $target Root, version and slug; defaults to the running install.
	 */
	public static function store( array $result, ?array $target = null ): void {
		$target                            = $target ?? self::target();
		$results                           = get_site_option( self::RESULT_OPTION, array() );
		$results                           = \is_array( $results ) ? $results : array();
		$results[ md5( $target['root'] ) ] = $result;
		update_site_option( self::RESULT_OPTION, $results );
	}

	/**
	 * The installed plugin: Pro's root when Pro is running (its manifest covers the
	 * bundled copy), else this plugin, whose checksums live on WordPress.org.
	 *
	 * @return array{root:string, version:string, slug:string|null}
	 */
	private static function target(): array {
		if ( \defined( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) && \defined( 'WCPOS\WooCommercePOSPro\VERSION' ) ) {
			return array(
				'root'    => (string) \constant( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ),
				'version' => (string) \constant( 'WCPOS\WooCommercePOSPro\VERSION' ),
				'slug'    => null,
			);
		}

		return array(
			'root'    => PLUGIN_PATH,
			'version' => VERSION,
			'slug'    => PLUGIN_NAME,
		);
	}

	/**
	 * Load the manifest: checksums.json at the root, else WordPress.org (cached).
	 *
	 * @param string      $root    Plugin root.
	 * @param string      $version Installed version.
	 * @param string|null $slug    WordPress.org slug, or null when not served from there.
	 *
	 * @return array|null File checksums, or null when unavailable.
	 */
	private static function manifest( string $root, string $version, ?string $slug ): ?array {
		if ( is_readable( $root . 'checksums.json' ) ) {
			$data = json_decode( (string) file_get_contents( $root . 'checksums.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			return \is_array( $data['files'] ?? null ) ? $data['files'] : null;
		}
		if ( null === $slug ) {
			return null;
		}

		$key   = 'wcpos_checksums_' . md5( $slug . $version );
		$files = get_transient( $key );
		if ( ! \is_array( $files ) ) {
			$response = wp_safe_remote_get( \sprintf( self::CHECKSUMS_URL, $slug, rawurlencode( $version ) ), array( 'timeout' => self::REMOTE_TIMEOUT ) );
			$data     = 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
			$files    = \is_array( $data['files'] ?? null ) ? $data['files'] : array();
			// Published checksums never change; a miss is retried within the hour.
			set_transient( $key, $files, $files ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
		}

		return $files ? $files : null;
	}
}
