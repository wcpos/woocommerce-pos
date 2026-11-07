<?php
/**
 * Tests that the admin bundles are enqueued with an mtime-busted version.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use ReflectionClass;
use WCPOS\WooCommercePOS\Admin;
use WCPOS\WooCommercePOS\Admin\Analytics;
use WCPOS\WooCommercePOS\Admin\Consent;
use WCPOS\WooCommercePOS\Admin\Settings;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Dev sites deploy every merge under the same plugin version and serve static
 * files with a 30-day cache header. A bundle versioned by VERSION alone is
 * therefore served stale from the browser cache until the next version bump.
 * Every admin bundle carries the file's mtime in its version instead.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Admin_Asset_Versions extends WP_UnitTestCase {
	private const MTIME = 1700000000;

	/**
	 * Files this test created because the checkout had no built assets.
	 *
	 * @var string[]
	 */
	private array $created = array();

	/**
	 * Original mtimes of assets that already existed, restored in tearDown so the cache key a
	 * build produced is not replaced by the fixture timestamp.
	 *
	 * @var array<string, int>
	 */
	private array $original_mtimes = array();

	/**
	 * The DEVELOPMENT flag as the test found it, if set.
	 *
	 * @var string|null
	 */
	private ?string $development = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Keep the consent-gated PostHog calls off the network.
		add_filter( 'pre_http_request', '__return_empty_array' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );

		// The enqueues read $_ENV['DEVELOPMENT'] to pick build/ over assets/; pin the release path.
		$this->development = isset( $_ENV['DEVELOPMENT'] ) ? sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) : null;
		unset( $_ENV['DEVELOPMENT'] );

		// Fresh registries so earlier tests' registrations cannot satisfy the assertions.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->created as $path ) {
			unlink( $path );
		}
		foreach ( $this->original_mtimes as $path => $mtime ) {
			touch( $path, $mtime );
		}
		$this->created         = array();
		$this->original_mtimes = array();
		if ( null !== $this->development ) {
			$_ENV['DEVELOPMENT'] = $this->development;
		}
		remove_filter( 'pre_http_request', '__return_empty_array' );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.

		parent::tearDown();
	}

	/**
	 * The settings stylesheet and bundle both carry the file mtime.
	 */
	public function test_settings_assets_are_versioned_by_file_mtime(): void {
		// Arrange.
		$this->pin_mtime( 'assets/css/settings.css' );
		$this->pin_mtime( 'assets/js/settings.js' );

		// Act.
		$this->without_constructor( Settings::class )->enqueue_assets();

		// Assert.
		$this->assertSame( VERSION . '.' . self::MTIME, wp_styles()->registered[ PLUGIN_NAME . '-settings-styles' ]->ver );
		$this->assertSame( VERSION . '.' . self::MTIME, wp_scripts()->registered[ PLUGIN_NAME . '-settings' ]->ver );
	}

	/**
	 * The analytics bundle carries the file mtime.
	 */
	public function test_analytics_bundle_is_versioned_by_file_mtime(): void {
		// Arrange.
		$this->pin_mtime( 'assets/js/analytics.js' );

		// Act.
		$this->without_constructor( Analytics::class )->enqueue_assets();

		// Assert.
		$this->assertSame( VERSION . '.' . self::MTIME, wp_scripts()->registered[ PLUGIN_NAME . '-analytics' ]->ver );
	}

	/**
	 * The api-fetch `_method` shim carries the file mtime.
	 */
	public function test_api_fetch_shim_is_versioned_by_file_mtime(): void {
		// Arrange.
		$this->pin_mtime( 'assets/js/api-fetch-method-param.js' );

		// Act.
		( new Admin() )->register_scripts();

		// Assert.
		$this->assertSame( VERSION . '.' . self::MTIME, wp_scripts()->registered[ Admin::API_FETCH_METHOD_PARAM_HANDLE ]->ver );
	}

	/**
	 * The consent stylesheet and bundle both carry the file mtime.
	 */
	public function test_consent_assets_are_versioned_by_file_mtime(): void {
		// Arrange.
		$this->pin_mtime( 'assets/css/consent.css' );
		$this->pin_mtime( 'assets/js/consent.js' );
		$settings                     = SettingsService::instance()->get_settings( 'general' );
		$settings['tracking_consent'] = 'undecided';
		SettingsService::instance()->save_settings( 'general', $settings );

		// Act.
		$this->without_constructor( Consent::class )->maybe_enqueue( 'plugins.php' );

		// Assert.
		$this->assertSame( VERSION . '.' . self::MTIME, wp_styles()->registered[ PLUGIN_NAME . '-consent-styles' ]->ver );
		$this->assertSame( VERSION . '.' . self::MTIME, wp_scripts()->registered[ PLUGIN_NAME . '-consent' ]->ver );
	}

	/**
	 * Make sure the asset exists and give it a known mtime.
	 *
	 * @param string $relative Path under the plugin directory.
	 */
	private function pin_mtime( string $relative ): void {
		$path = PLUGIN_PATH . $relative;
		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( \dirname( $path ) );
			file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
			$this->created[] = $path;
		} else {
			$this->original_mtimes[ $path ] = (int) filemtime( $path );
		}
		touch( $path, self::MTIME );
		clearstatcache( true, $path );
	}

	/**
	 * Build a screen handler without its constructor's hook registrations and global side effects.
	 *
	 * @param class-string $class The handler class.
	 */
	private function without_constructor( string $class ): object {
		return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}
}
