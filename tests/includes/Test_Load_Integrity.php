<?php
/**
 * Tests the load-time integrity defence: the fallback autoloader, the
 * failure-path classifier and the REST 503 boundary.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Load_Integrity;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Load integrity test case.
 *
 * @covers \WCPOS\WooCommercePOS\Load_Integrity
 */
class Test_Load_Integrity extends WP_UnitTestCase {
	/**
	 * Files and directories created by a test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $created = array();

	/**
	 * REST server in place before the test swapped in a fresh one.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $saved_server = null;

	/**
	 * API instance registered into a fresh REST server.
	 *
	 * @var API|null
	 */
	private $api = null;

	/**
	 * Original mtimes of shipped files a test touched, restored in tearDown.
	 *
	 * @var array<string, int>
	 */
	private $touched = array();

	/**
	 * Remove fixtures, deepest first.
	 */
	public function tearDown(): void {
		foreach ( $this->touched as $file => $mtime ) {
			touch( $file, $mtime );
		}
		$this->touched = array();
		if ( null !== $this->api ) {
			$GLOBALS['wp_rest_server'] = $this->saved_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring core's REST server.
			$this->api                 = null;
		}
		foreach ( array_reverse( $this->created ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			} elseif ( is_dir( $path ) ) {
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			}
		}
		$this->created = array();
		parent::tearDown();
	}

	/**
	 * The fallback is on the SPL stack, after Composer's loader.
	 */
	public function test_fallback_is_registered_after_composer(): void {
		$stack    = spl_autoload_functions();
		$fallback = array_search( array( Load_Integrity::class, 'autoload' ), $stack, true );
		$composer = null;
		foreach ( $stack as $index => $loader ) {
			if ( \is_array( $loader ) && $loader[0] instanceof \Composer\Autoload\ClassLoader ) {
				$composer = $index;
				break;
			}
		}

		$this->assertIsInt( $fallback );
		$this->assertIsInt( $composer );
		$this->assertGreaterThan( $composer, $fallback );
	}

	/**
	 * A class Composer already missed (and cached as missing) loads once its file appears.
	 */
	public function test_fallback_loads_a_class_after_composer_missed_it(): void {
		// Arrange: Composer's PSR-4 lookup misses while the file is absent.
		$name  = 'Load_Integrity_Fixture_' . str_replace( '.', '', uniqid( '', true ) );
		$class = 'WCPOS\\WooCommercePOS\\' . $name;
		$file  = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'includes/' . $name . '.php';
		$this->assertFalse( class_exists( $class ) );

		// Act: the file lands mid-request, as an in-place update swaps it in.
		$this->write( $file, "<?php\nnamespace WCPOS\\WooCommercePOS;\nclass {$name} {}\n" );

		// Assert.
		$this->assertTrue( class_exists( $class ) );
	}

	/**
	 * Only the free plugin's own prefix is handled; Pro's namespace is not swallowed.
	 */
	public function test_fallback_resolves_only_the_free_prefix(): void {
		$base = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'includes/';

		$this->assertSame( $base . 'Services/Auth.php', Load_Integrity::file_for( 'WCPOS\\WooCommercePOS\\Services\\Auth', $base ) );
		$this->assertNull( Load_Integrity::file_for( 'WCPOS\\WooCommercePOSPro\\Services\\Auth', $base ) );
		$this->assertNull( Load_Integrity::file_for( 'Automattic\\WooCommerce\\Container', $base ) );
	}

	/**
	 * A torn file (parse error) is contained and stays retryable.
	 */
	public function test_fallback_contains_a_torn_file_and_retries_later(): void {
		// Arrange.
		$dir   = $this->temp_dir();
		$name  = 'Torn_' . str_replace( '.', '', uniqid( '', true ) );
		$class = 'WCPOS\\WooCommercePOS\\' . $name;
		$this->write( $dir . $name . '.php', "<?php\nnamespace WCPOS\\WooCommercePOS;\nclass {$name} {" );

		// Act: the torn file must not throw.
		$this->assertFalse( Load_Integrity::load( $class, $dir ) );

		// The copy finishes; the same class now loads.
		$this->write( $dir . $name . '.php', "<?php\nnamespace WCPOS\\WooCommercePOS;\nclass {$name} {}\n" );

		// Assert.
		$this->assertTrue( Load_Integrity::load( $class, $dir ) );
		$this->assertTrue( class_exists( $class, false ) );
	}

	/**
	 * A shipped class (in a Composer class map) whose file is missing waits once per
	 * request; a class no class map lists never waits.
	 */
	public function test_only_a_shipped_class_waits_and_only_once_per_request(): void {
		// Arrange.
		$dir    = $this->temp_dir();
		$class  = 'WCPOS\\WooCommercePOS\\Shipped_' . str_replace( '.', '', uniqid( '', true ) );
		$loader = new \Composer\Autoload\ClassLoader( $dir ); // A vendor dir is what lists it in getRegisteredLoaders().
		$loader->addClassMap( array( $class => $dir . 'gone.php' ) );
		$loader->register( false );
		$waited = new \ReflectionProperty( Load_Integrity::class, 'waited' );
		$waited->setAccessible( true );
		$waited->setValue( null, false );

		try {
			// Act.
			$start      = microtime( true );
			$unlisted   = Load_Integrity::load( 'WCPOS\\WooCommercePOS\\Never_Listed', $dir );
			$t_unlisted = microtime( true ) - $start;
			$start      = microtime( true );
			$first      = Load_Integrity::load( $class, $dir );
			$t_first    = microtime( true ) - $start;
			$start      = microtime( true );
			$second     = Load_Integrity::load( $class, $dir );
			$t_second   = microtime( true ) - $start;
		} finally {
			$loader->unregister();
		}

		// Assert.
		$this->assertFalse( $unlisted );
		$this->assertFalse( $first );
		$this->assertFalse( $second );
		$this->assertLessThan( 0.1, $t_unlisted );
		$this->assertGreaterThanOrEqual( Load_Integrity::RETRY_WAIT_US / 1e6, $t_first );
		$this->assertLessThan( 0.1, $t_second );
	}

	/**
	 * An unrelated Error from an included file is not swallowed.
	 */
	public function test_fallback_rethrows_an_unrelated_error_from_the_file(): void {
		$dir   = $this->temp_dir();
		$name  = 'Throws_' . str_replace( '.', '', uniqid( '', true ) );
		$this->write( $dir . $name . '.php', "<?php\nnamespace WCPOS\\WooCommercePOS;\nthrow new \\TypeError( 'boom' );\n" );

		$this->expectException( \TypeError::class );
		Load_Integrity::load( 'WCPOS\\WooCommercePOS\\' . $name, $dir );
	}

	/**
	 * Missing-class messages are recognised in both PHP 7 and PHP 8 form, ours only.
	 */
	public function test_missing_class_parses_wcpos_messages_only(): void {
		$this->assertSame( 'WCPOS\\WooCommercePOS\\Services\\Session_Registry', Load_Integrity::missing_class( 'Class "WCPOS\\WooCommercePOS\\Services\\Session_Registry" not found' ) );
		$this->assertSame( 'WCPOS\\WooCommercePOS\\Sync\\Cors', Load_Integrity::missing_class( "Uncaught Error: Class 'WCPOS\\WooCommercePOS\\Sync\\Cors' not found in /x/Rest_Cors.php:206" ) );
		$this->assertSame( 'WCPOS\\WooCommercePOS\\Interfaces\\Foo', Load_Integrity::missing_class( 'Interface "WCPOS\\WooCommercePOS\\Interfaces\\Foo" not found' ) );
		$this->assertNull( Load_Integrity::missing_class( 'Class "WCPOS\\WooCommercePOSPro\\Foo" not found' ) );
		$this->assertNull( Load_Integrity::missing_class( 'Class "WC_Order" not found' ) );
		$this->assertNull( Load_Integrity::missing_class( 'Call to undefined method WCPOS\\WooCommercePOS\\API::nope()' ) );
	}

	/**
	 * Each classification follows from the simulated signals.
	 *
	 * @dataProvider classification_provider
	 *
	 * @param array  $signals  Simulated signals.
	 * @param string $expected Expected classification.
	 */
	public function test_classify_returns_each_class_for_its_signals( array $signals, string $expected ): void {
		$this->assertSame( $expected, Load_Integrity::classify( array_merge( $this->quiet_signals(), $signals ) ) );
	}

	/**
	 * Signals for each classification.
	 *
	 * @return array<string, array{array, string}>
	 */
	public function classification_provider(): array {
		return array(
			'nothing at all'          => array( array(), 'files_missing' ),
			'file just written'       => array( array( 'fresh_file' => true ), 'mid_update' ),
			'fresh maintenance file'  => array( array( 'maintenance_age' => 30 ), 'mid_update' ),
			'stale maintenance file'  => array( array( 'maintenance_age' => 3600 ), 'files_missing' ),
			'temp backup dir'         => array( array( 'temp_backup' => true ), 'mid_update' ),
			'upgrade working dir'     => array( array( 'upgrade_dir' => true ), 'mid_update' ),
			'auto updater lock'       => array( array( 'updater_lock' => true ), 'mid_update' ),
			'version swapped'         => array( array( 'disk_version' => '9.9.9' ), 'swapped_mid_request' ),
			'update beats the swap'   => array(
				array(
					'disk_version' => '9.9.9',
					'temp_backup'  => true,
				),
				'mid_update',
			),
			'unreadable main file'    => array( array( 'disk_version' => '' ), 'files_missing' ),
		);
	}

	/**
	 * The stat probes see real update debris on disk.
	 */
	public function test_signals_read_update_debris_from_disk(): void {
		// Arrange.
		$abspath = $this->temp_dir();
		$content = $this->temp_dir();
		$this->write( $abspath . '.maintenance', '<?php $upgrading = 0;' );
		$this->mkdir( $content . 'upgrade-temp-backup' );
		$this->mkdir( $content . 'upgrade-temp-backup/plugins' );
		$this->mkdir( $content . 'upgrade-temp-backup/plugins/woocommerce-pos' );
		$this->mkdir( $content . 'upgrade' );
		$this->mkdir( $content . 'upgrade/woocommerce-pos.1.10.99' );
		update_option( 'auto_updater.lock', time() );

		// Act.
		$signals = Load_Integrity::signals( $this->includes() . 'Nope/Missing.php', $abspath, $content );
		delete_option( 'auto_updater.lock' );

		// Assert.
		$this->assertFalse( $signals['fresh_file'] );
		$this->assertIsInt( $signals['maintenance_age'] );
		$this->assertLessThan( 60, $signals['maintenance_age'] );
		$this->assertTrue( $signals['temp_backup'] );
		$this->assertTrue( $signals['upgrade_dir'] );
		$this->assertTrue( $signals['updater_lock'] );
		$this->assertSame( \WCPOS\WooCommercePOS\VERSION, $signals['disk_version'] );
		$this->assertSame( 'includes/Nope/Missing.php', $signals['path'] );
	}

	/**
	 * Debris a failed update left behind days ago is not an update in progress.
	 */
	public function test_signals_ignore_stale_update_debris(): void {
		// Arrange.
		$abspath = $this->temp_dir();
		$content = $this->temp_dir();
		$old     = time() - DAY_IN_SECONDS;
		$this->mkdir( $content . 'upgrade-temp-backup' );
		$this->mkdir( $content . 'upgrade-temp-backup/plugins' );
		$this->mkdir( $content . 'upgrade-temp-backup/plugins/woocommerce-pos' );
		$this->mkdir( $content . 'upgrade' );
		$this->mkdir( $content . 'upgrade/woocommerce-pos.1.10.99' );
		touch( $content . 'upgrade-temp-backup/plugins/woocommerce-pos', $old );
		touch( $content . 'upgrade/woocommerce-pos.1.10.99', $old );
		update_option( 'auto_updater.lock', $old );
		$this->write( $abspath . 'Old.php', '<?php' );
		touch( $abspath . 'Old.php', $old );

		// Act.
		$signals = Load_Integrity::signals( $abspath . 'Old.php', $abspath, $content );
		delete_option( 'auto_updater.lock' );

		// Assert: the file exists but was not just written either.
		$this->assertSame( 'files_missing', Load_Integrity::classify( $signals ) );
	}

	/**
	 * A WCPOS file that failed to parse (torn mid-copy) is an affected file; others are not.
	 */
	public function test_affected_file_covers_missing_classes_and_torn_wcpos_files(): void {
		$base = $this->includes();

		$this->assertSame( $base . 'Services/Auth.php', Load_Integrity::affected_file( 'Class "WCPOS\\WooCommercePOS\\Services\\Auth" not found', '/x/y.php', false ) );
		$this->assertSame( $base . 'Services/Auth.php', Load_Integrity::affected_file( 'syntax error, unexpected end of file', $base . 'Services/Auth.php', true ) );
		$this->assertNull( Load_Integrity::affected_file( 'syntax error, unexpected end of file', '/elsewhere/plugin.php', true ) );
		$this->assertNull( Load_Integrity::affected_file( 'Undefined index: x', $base . 'Services/Auth.php', false ) );
	}

	/**
	 * The REST boundary turns a missing WCPOS class into a retryable 503.
	 */
	public function test_rest_dispatch_returns_503_for_a_missing_wcpos_class(): void {
		// Arrange: the file was just written, as an update copying it in.
		$this->touch_shipped( 'Services/Session_Registry.php' );
		$api     = $this->api();
		$route   = $this->wcpos_route();
		$handler = array(
			'callback' => static function () {
				throw new \Error( 'Class "WCPOS\\WooCommercePOS\\Services\\Session_Registry" not found' );
			},
		);

		// Act.
		$response = $api->rest_dispatch_wcpos_callback( null, new WP_REST_Request( 'GET', $route ), $route, $handler );

		// Assert.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( '5', $response->get_headers()['Retry-After'] ?? null );
		$this->assertSame( 'wcpos_updating', $response->get_data()['code'] ?? null );
	}

	/**
	 * Any other Error, including another vendor's missing class, is rethrown.
	 *
	 * @dataProvider foreign_error_provider
	 *
	 * @param \Throwable $error Error thrown by the route callback.
	 */
	public function test_rest_dispatch_rethrows_every_other_error( \Throwable $error ): void {
		$api     = $this->api();
		$route   = $this->wcpos_route();
		$handler = array(
			'callback' => static function () use ( $error ) {
				throw $error;
			},
		);

		$this->expectException( \get_class( $error ) );
		$api->rest_dispatch_wcpos_callback( null, new WP_REST_Request( 'GET', $route ), $route, $handler );
	}

	/**
	 * Errors the boundary must not swallow.
	 *
	 * @return array<string, array{\Throwable}>
	 */
	public function foreign_error_provider(): array {
		return array(
			'woocommerce class' => array( new \Error( 'Class "Automattic\\WooCommerce\\Foo" not found' ) ),
			'pro class'         => array( new \Error( 'Class "WCPOS\\WooCommercePOSPro\\Foo" not found' ) ),
			'type error'        => array( new \TypeError( 'Argument #1 must be of type int' ) ),
			'exception'         => array( new \RuntimeException( 'boom' ) ),
		);
	}

	/**
	 * A class whose file is simply gone (no update in sight) is not dressed up as a retry.
	 */
	public function test_rest_dispatch_rethrows_a_class_missing_without_an_update(): void {
		$api     = $this->api();
		$route   = $this->wcpos_route();
		$handler = array(
			'callback' => static function () {
				throw new \Error( 'Class "WCPOS\\WooCommercePOS\\Nope\\Never_Shipped" not found' );
			},
		);

		$this->expectException( \Error::class );
		$api->rest_dispatch_wcpos_callback( null, new WP_REST_Request( 'GET', $route ), $route, $handler );
	}

	/**
	 * A fatal before dispatch (auth, CORS) on a WCPOS REST request becomes a 503 page.
	 */
	public function test_fatal_page_is_503_only_for_a_wcpos_rest_request_mid_update(): void {
		$this->touch_shipped( 'Services/Session_Registry.php' );
		$args  = array( 'response' => 500 );
		$error = array( 'message' => 'Uncaught Error: Class "WCPOS\\WooCommercePOS\\Services\\Session_Registry" not found in /x/Auth.php:28' );
		$saved = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved verbatim to restore it.

		$_SERVER['REQUEST_URI'] = '/wp-json/wcpos/v2/sync/products';
		$wcpos                  = Load_Integrity::fatal_error_args( $args, $error );
		$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fwcpos%2Fv2%2Fsync%2Fproducts';
		$plain                  = Load_Integrity::fatal_error_args( $args, $error );
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
		$other_route            = Load_Integrity::fatal_error_args( $args, $error );
		$_SERVER['REQUEST_URI'] = '/wp-json/wcpos/v2/sync/products';
		$other_error            = Load_Integrity::fatal_error_args( $args, array( 'message' => 'Uncaught Error: Class "Foo" not found' ) );
		$_SERVER['REQUEST_URI'] = $saved;

		$this->assertSame( 503, $wcpos['response'] );
		$this->assertSame( 503, $plain['response'] );
		$this->assertSame( 500, $other_route['response'] );
		$this->assertSame( 500, $other_error['response'] );
	}

	/**
	 * An earlier listener's answer stands, and the callback is not run behind it.
	 */
	public function test_rest_dispatch_leaves_an_earlier_answer_alone(): void {
		$api     = $this->api();
		$route   = $this->wcpos_route();
		$ran     = false;
		$handler = array(
			'callback' => static function () use ( &$ran ) {
				$ran = true;
			},
		);
		$earlier = new WP_REST_Response( array( 'halted' => true ), 429 );

		$this->assertSame( $earlier, $api->rest_dispatch_wcpos_callback( $earlier, new WP_REST_Request( 'GET', $route ), $route, $handler ) );
		$this->assertNull( $api->rest_dispatch_wcpos_callback( null, new WP_REST_Request( 'GET', '/wc/v3/products' ), '/wc/v3/products', $handler ) );
		$this->assertFalse( $ran );
	}

	/**
	 * A bootstrap row whose class is missing fails as class-not-found, which the
	 * integrity path recognises, not as a malformed manifest.
	 */
	public function test_hook_manifest_reports_a_missing_class_as_class_not_found(): void {
		$row = array(
			'hook'     => null,
			'callback' => array( 'WCPOS\\WooCommercePOS\\Services\\Gone_Mid_Update', 'register' ),
			'priority' => 10,
			'args'     => 0,
			'reason'   => 'Test.',
			'phase'    => 'post-latch',
		);

		try {
			\WCPOS\WooCommercePOS\Hook_Manifest::validate( array( $row ) );
			$this->fail( 'A missing class was accepted.' );
		} catch ( \Error $error ) {
			$this->assertSame( 'WCPOS\\WooCommercePOS\\Services\\Gone_Mid_Update', Load_Integrity::missing_class( $error->getMessage() ) );
		}
	}

	/**
	 * A WCPOS route the boundary owns still returns its callback's result unchanged.
	 */
	public function test_rest_dispatch_passes_a_normal_result_through(): void {
		$api     = $this->api();
		$route   = $this->wcpos_route();
		$handler = array(
			'callback' => static function () {
				return array( 'ok' => true );
			},
		);

		$this->assertSame( array( 'ok' => true ), $api->rest_dispatch_wcpos_callback( null, new WP_REST_Request( 'GET', $route ), $route, $handler ) );
	}

	/**
	 * A signal set with nothing pointing at an update.
	 *
	 * @return array
	 */
	private function quiet_signals(): array {
		return array(
			'path'            => 'includes/Services/Auth.php',
			'fresh_file'      => false,
			'maintenance_age' => null,
			'temp_backup'     => false,
			'upgrade_dir'     => false,
			'updater_lock'    => false,
			'disk_version'    => \WCPOS\WooCommercePOS\VERSION,
		);
	}

	/**
	 * Build an API whose registry owns its routes: registered into a fresh server,
	 * so no earlier registration already holds the patterns.
	 *
	 * @return API
	 */
	private function api(): API {
		$this->saved_server        = $GLOBALS['wp_rest_server'] ?? null;
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Swapping in a fresh core REST server.
		// First listener on a fresh server, so its registry owns the patterns it adds.
		$register = function (): void {
			$this->api = new API();
		};
		add_action( 'rest_api_init', $register, 0 );
		do_action( 'rest_api_init', $server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		remove_action( 'rest_api_init', $register, 0 );
		return $this->api;
	}

	/**
	 * A route pattern the WCPOS registry maps to a controller with no dispatch override.
	 *
	 * @return string
	 */
	private function wcpos_route(): string {
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( 0 === strpos( $route, '/wcpos/v2/cashier' ) ) {
				return $route;
			}
		}
		$this->fail( 'No /wcpos/v2/cashier route registered.' );
	}

	/**
	 * Mark a shipped file as just written, restoring its mtime in tearDown.
	 *
	 * @param string $relative Path under includes/.
	 */
	private function touch_shipped( string $relative ): void {
		$file = $this->includes() . $relative;
		if ( ! isset( $this->touched[ $file ] ) ) {
			$this->touched[ $file ] = (int) filemtime( $file );
		}
		touch( $file );
	}

	/**
	 * The running copy's includes/ directory.
	 *
	 * @return string
	 */
	private function includes(): string {
		return str_replace( '\\', '/', \WCPOS\WooCommercePOS\PLUGIN_PATH ) . 'includes/';
	}

	/**
	 * Create a temp directory.
	 *
	 * @return string Path with a trailing slash.
	 */
	private function temp_dir(): string {
		$dir = sys_get_temp_dir() . '/wcpos-li-' . str_replace( '.', '', uniqid( '', true ) ) . '/';
		$this->mkdir( $dir );
		return $dir;
	}

	/**
	 * Create a directory, recorded for cleanup.
	 *
	 * @param string $dir Directory.
	 */
	private function mkdir( string $dir ): void {
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		$this->created[] = $dir;
	}

	/**
	 * Write a file, recorded for cleanup.
	 *
	 * @param string $file    File.
	 * @param string $content Content.
	 */
	private function write( string $file, string $content ): void {
		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		clearstatcache( true, $file );
		if ( ! \in_array( $file, $this->created, true ) ) {
			$this->created[] = $file;
		}
	}
}
