<?php
/**
 * Pins the invariant behind the free+Pro redeclaration fatal: EVERY class this
 * bootstrap declares before its Pro-is-active bail-out must survive that class
 * already being declared from a different file path.
 *
 * PR #1818 fixed the one violation (Ping) and Test_Bootstrap_Pro_Coexistence
 * pins that file specifically. This test pins the CLASS of bug: it discovers the
 * eager set from the live bootstrap, so the next eagerly-required file is swept
 * in automatically — added unguarded, it fails here the same way it would fail
 * on a merchant site with Pro's bundled copy loaded first (a fatal during the
 * plugin include phase, 500 on every route including wp-login.php, before any
 * recovery hook can run).
 *
 * Subprocess for the usual reasons: the bootstrap must run where the classes are
 * not already loaded, and a redeclaration fatal is not catchable.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Eager-class collision test case.
 */
class Test_Bootstrap_Eager_Class_Collision extends WP_UnitTestCase {
	/**
	 * Temp directory holding duplicate copies of the eager class files.
	 *
	 * @var string
	 */
	private $dupe_dir = '';

	/**
	 * Create the temp directory.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->dupe_dir = sys_get_temp_dir() . '/wcpos-eager-collision-' . uniqid();
		wp_mkdir_p( $this->dupe_dir );
	}

	/**
	 * Remove the temp directory.
	 */
	public function tearDown(): void {
		if ( '' !== $this->dupe_dir && is_dir( $this->dupe_dir ) ) {
			foreach ( array_diff( scandir( $this->dupe_dir ), array( '.', '..' ) ) as $entry ) {
				unlink( $this->dupe_dir . '/' . $entry );
			}
			rmdir( $this->dupe_dir );
		}

		parent::tearDown();
	}

	/**
	 * The whole eager set must survive pre-declaration from duplicate paths.
	 */
	public function test_every_eagerly_declared_class_survives_a_duplicate_declaration(): void {
		// Arrange: discover what the bootstrap declares above the bail-out.
		$manifest = $this->discover_eager_classes();
		$this->assertNotEmpty(
			$manifest,
			'Discovery found no eagerly declared classes, but the ping fast path requires at least Ping. If the bootstrap genuinely declares nothing before the bail-out any more, this test and its harness can be retired together.'
		);
		$this->assertArrayHasKey(
			'WCPOS\\WooCommercePOS\\API\\V2\\Ping',
			$manifest,
			'Sanity: Ping must be in the discovered eager set — if discovery cannot see it, it cannot protect anything else either.'
		);

		// Arrange: duplicate every eager file, standing in for Pro's bundled copy.
		list( $manifest_file, $duplicates ) = $this->write_duplicate_manifest( $manifest );

		// Act: load the duplicates first, then the bootstrap — Pro's load order.
		$result = $this->run_harness( 'collide', $manifest_file );

		// Assert.
		$this->assertStringNotContainsString(
			'Call to undefined function',
			$result['output'],
			'The harness stubs are out of date, not the bootstrap: it now calls a WordPress function that tests/fixtures/bootstrap/eager-class-collision-harness.php does not define. Add the stub there.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/Cannot (re)?declare class/',
			$result['output'],
			'The bootstrap re-declared a class that was already loaded from another path. On a site running free + Pro, Pro\'s bundled copy declares it first and this is a fatal during the plugin include phase — 500 on every route including wp-login.php, with no recovery hook reachable. Guard the require with class_exists( ..., false ), the way the Ping require is guarded.'
		);
		$this->assertSame( 0, $result['exit_code'], 'Bootstrap fataled with duplicates pre-loaded: ' . $result['output'] );
		$this->assertStringContainsString( 'HARNESS_COMPLETED', $result['output'] );

		// Assert: it must DEFER to the earlier copy, not shadow or reload it.
		foreach ( $duplicates as $class => $duplicate ) {
			$this->assertStringContainsString(
				'RESOLVED=' . $class . '|' . $duplicate,
				$result['output'],
				"{$class} should still be the copy loaded first (Pro's, in production); the bootstrap must skip its own require."
			);
		}
	}

	/**
	 * Run the bootstrap with Pro active and collect what it declares.
	 *
	 * @return array<string, string> FQCN => original declaring file.
	 */
	private function discover_eager_classes(): array {
		$result = $this->run_harness( 'discover' );
		$this->assertSame( 0, $result['exit_code'], 'Discovery run failed: ' . $result['output'] );
		$this->assertStringContainsString( 'HARNESS_COMPLETED', $result['output'] );

		$manifest = array();
		foreach ( explode( "\n", $result['output'] ) as $line ) {
			if ( 0 === strpos( $line, 'DECLARED=' ) ) {
				list( $class, $file ) = explode( '|', substr( $line, \strlen( 'DECLARED=' ) ), 2 );
				$manifest[ $class ]   = $file;
			}
		}

		return $manifest;
	}

	/**
	 * Copy each eager file to the temp dir and write the collide manifest.
	 *
	 * @param array<string, string> $manifest FQCN => original declaring file.
	 *
	 * @return array{0: string, 1: array<string, string>} Manifest file path, and FQCN => duplicate file.
	 */
	private function write_duplicate_manifest( array $manifest ): array {
		$lines       = array();
		$duplicates  = array();
		$by_original = array();
		foreach ( $manifest as $class => $original ) {
			/*
			 * ONE duplicate per source file, keyed by resolved original path. A file
			 * declaring several symbols yields several manifest rows pointing at the
			 * SAME duplicate; copying it once per symbol would make the harness
			 * require two distinct copies and redeclare before the bootstrap runs —
			 * failing the test against a correctly guarded bootstrap.
			 */
			$original_real = (string) realpath( $original );
			if ( ! isset( $by_original[ $original_real ] ) ) {
				$duplicate = $this->dupe_dir . '/' . md5( $original_real ) . '.php';
				copy( $original, $duplicate );

				// realpath both sides: ReflectionClass::getFileName() reports resolved
				// paths, and sys_get_temp_dir() is a symlink on macOS (/tmp -> /private/tmp).
				$duplicate = (string) realpath( $duplicate );
				$this->assertNotSame(
					$original_real,
					$duplicate,
					'The duplicate must be a distinct file, otherwise require_once dedupes and the collision never happens.'
				);
				$by_original[ $original_real ] = $duplicate;
			}

			$lines[]              = $class . '|' . $by_original[ $original_real ];
			$duplicates[ $class ] = $by_original[ $original_real ];
		}

		$manifest_file = $this->dupe_dir . '/manifest.txt';
		file_put_contents( $manifest_file, implode( "\n", $lines ) . "\n" );

		return array( $manifest_file, $duplicates );
	}

	/**
	 * Run the collision harness in a clean PHP process.
	 *
	 * @param string $mode          "discover" or "collide".
	 * @param string $manifest_file Manifest path (collide mode only).
	 *
	 * @return array{output: string, exit_code: int}
	 */
	private function run_harness( string $mode, string $manifest_file = '' ): array {
		$command = sprintf(
			'%s %s %s %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( \dirname( __DIR__ ) . '/fixtures/bootstrap/eager-class-collision-harness.php' ),
			escapeshellarg( \dirname( \dirname( __DIR__ ) ) . '/woocommerce-pos.php' ),
			escapeshellarg( $mode ),
			'' !== $manifest_file ? escapeshellarg( $manifest_file ) : "''"
		);

		$output    = array();
		$exit_code = 0;
		exec( $command, $output, $exit_code );

		return array(
			'output'    => implode( "\n", $output ),
			'exit_code' => $exit_code,
		);
	}
}
