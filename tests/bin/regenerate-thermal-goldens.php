<?php
/**
 * Regenerate the thermal emitter golden fixtures.
 *
 * Run from the plugin root with a plain PHP CLI — no WordPress, no Docker and
 * no PHPUnit are required, because the emitters, the markup parser and
 * Print_Job_Service::normalize_drawer_connector() are all pure PHP:
 *
 *   php tests/bin/regenerate-thermal-goldens.php
 *
 * Review the resulting `git diff` under tests/fixtures/thermal/golden/ before
 * committing: an intentional behaviour change should show up there, and a
 * refactor that was meant to be byte-neutral should produce no diff at all.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 'This script must be run from the command line.' . PHP_EOL );
}

$wcpos_plugin_dir = \dirname( __DIR__, 2 );

/*
 * A deliberately local autoloader: vendor/autoload.php resolves $baseDir to the
 * checkout composer was installed in, which is the wrong tree when this script
 * is run from a git worktree.
 */
spl_autoload_register(
	static function ( string $class ) use ( $wcpos_plugin_dir ): void {
		$map = array(
			'WCPOS\\WooCommercePOS\\Tests\\' => $wcpos_plugin_dir . '/tests/includes/',
			'WCPOS\\WooCommercePOS\\'        => $wcpos_plugin_dir . '/includes/',
		);

		foreach ( $map as $prefix => $dir ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}
			$path = $dir . str_replace( '\\', '/', substr( $class, \strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}

			return;
		}
	}
);

$wcpos_written = WCPOS\WooCommercePOS\Tests\Templates\Thermal\Thermal_Golden_Corpus::write_all();

echo 'Wrote ' . \count( $wcpos_written ) . ' thermal golden fixtures.' . PHP_EOL;
