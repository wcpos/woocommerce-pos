<?php
/**
 * Pins that the free bootstrap survives being loaded alongside Pro.
 *
 * Pro bundles the free plugin at vendor/wcpos/woocommerce-pos/ and declares
 * WCPOS\WooCommercePOS\API\V2\Ping from there. WordPress keeps active_plugins
 * sorted, so 'woocommerce-pos-pro/...' is included BEFORE 'woocommerce-pos/...'
 * and Pro wins the race to declare that class on every site running both.
 *
 * An unguarded `require_once __DIR__ . '/includes/API/V2/Ping.php'` in our
 * bootstrap is therefore a site-killer: it is a different path on disk, so
 * require_once does not dedupe, and PHP fatals during the plugin include phase.
 * That is before our own Pro-is-active bail-out and before Pro's plugins_loaded
 * fallback that deactivates this plugin, so nothing recovers it — every route
 * 500s, wp-login.php included, and the merchant cannot reach the admin to undo it.
 *
 * This runs in a subprocess for two reasons: a redeclaration fatal is not
 * catchable, and the class is already loaded in the test process, so the only way
 * to exercise the real two-files-on-disk arrangement is a clean interpreter.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Free + Pro coexistence bootstrap test case.
 */
class Test_Bootstrap_Pro_Coexistence extends WP_UnitTestCase {
	/**
	 * Temp directory holding the stand-in for Pro's bundled copy.
	 *
	 * @var string
	 */
	private $vendor_dir = '';

	/**
	 * Create a real second copy of Ping.php, mimicking Pro's vendored free core.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->vendor_dir = sys_get_temp_dir() . '/wcpos-pro-coexistence-' . uniqid();
		$target           = $this->vendor_dir . '/vendor/wcpos/woocommerce-pos/includes/API/V2';

		wp_mkdir_p( $target );
		copy( $this->plugin_dir() . '/includes/API/V2/Ping.php', $target . '/Ping.php' );
	}

	/**
	 * Remove the temp copy.
	 */
	public function tearDown(): void {
		if ( '' !== $this->vendor_dir && is_dir( $this->vendor_dir ) ) {
			$this->rrmdir( $this->vendor_dir );
		}

		parent::tearDown();
	}

	/**
	 * The bootstrap must not redeclare Ping when Pro already declared it.
	 */
	public function test_bootstrap_with_pro_loaded_first_does_not_fatal(): void {
		// Arrange.
		$duplicate_ping = $this->vendor_dir . '/vendor/wcpos/woocommerce-pos/includes/API/V2/Ping.php';
		$this->assertFileExists( $duplicate_ping, 'Harness needs a second copy of Ping.php on disk.' );
		$this->assertNotSame(
			realpath( $duplicate_ping ),
			realpath( $this->plugin_dir() . '/includes/API/V2/Ping.php' ),
			'The two copies must be distinct files, otherwise require_once dedupes and the test proves nothing.'
		);

		// Act.
		$result = $this->run_harness( $duplicate_ping );

		// Assert.
		$this->assertStringNotContainsString(
			'Call to undefined function',
			$result['output'],
			'The harness stubs are out of date, not the guard: the bootstrap now calls a WordPress function that tests/fixtures/bootstrap/pro-coexistence-harness.php does not define. Add the stub there. This is NOT a redeclaration regression.'
		);
		/*
		 * Match the class name, not the prose: PHP words this fatal differently by
		 * version ("Cannot declare class ..., because the name is already in use" on
		 * 8.3, "Cannot redeclare class ..." on 8.5), and the test matrix includes an
		 * "experimental" PHP lane that tracks whatever is newest. Asserting on one
		 * wording silently turns this into a no-op on the other.
		 */
		$this->assertDoesNotMatchRegularExpression(
			'/Cannot (re)?declare class \S*Ping/',
			$result['output'],
			'The free bootstrap redeclared Ping on top of the copy Pro already loaded. On a site running free + Pro this is a fatal during the plugin include phase, which white-screens every route including wp-login.php.'
		);
		$this->assertSame( 0, $result['exit_code'], 'Free bootstrap fataled alongside Pro: ' . $result['output'] );
		$this->assertStringContainsString( 'BOOTSTRAP_SURVIVED', $result['output'] );
	}

	/**
	 * Free must defer to the already-declared class rather than shadowing it.
	 */
	public function test_bootstrap_defers_to_the_copy_pro_already_declared(): void {
		// Arrange.
		$duplicate_ping = $this->vendor_dir . '/vendor/wcpos/woocommerce-pos/includes/API/V2/Ping.php';

		// Act.
		$result = $this->run_harness( $duplicate_ping );

		// Assert.
		$this->assertStringContainsString(
			'PING_DECLARED_IN=' . $duplicate_ping,
			$result['output'],
			'Ping should still be the copy Pro loaded; the free bootstrap must skip its own require, not race it.'
		);
	}

	/**
	 * Run the include-phase harness in a clean PHP process.
	 *
	 * @param string $duplicate_ping Path to the stand-in for Pro's bundled copy.
	 *
	 * @return array{output: string, exit_code: int}
	 */
	private function run_harness( string $duplicate_ping ): array {
		$command = sprintf(
			'%s %s %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( \dirname( __DIR__ ) . '/fixtures/bootstrap/pro-coexistence-harness.php' ),
			escapeshellarg( $duplicate_ping ),
			escapeshellarg( $this->plugin_dir() . '/woocommerce-pos.php' )
		);

		$output    = array();
		$exit_code = 0;
		exec( $command, $output, $exit_code );

		return array(
			'output'    => implode( "\n", $output ),
			'exit_code' => $exit_code,
		);
	}

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_dir(): string {
		return \dirname( \dirname( __DIR__ ) );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function rrmdir( string $dir ): void {
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}
}
