<?php
/**
 * Regenerate the thermal emitter golden fixtures.
 *
 * Run it through wp-env, from the plugin root:
 *
 *   pnpm run goldens:thermal
 *
 * The emitters, the markup parser and Print_Job_Service::normalize_drawer_connector()
 * are all pure PHP, so nothing here needs WordPress — but the goldens are committed
 * artefacts, and the suite that asserts them byte-for-byte runs inside wp-env. Generate
 * them anywhere else and the host's PHP build becomes an input to a file in git: the
 * text metrics reach mbstring through mb_str_split() and mb_ord(), so a different
 * mbstring could bake a local layout into the fixture and hand the next person a
 * phantom diff. Same runtime for generating and asserting, per the repo rule in
 * AGENTS.md that PHP tests run through Docker/wp-env with no local fallback.
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

$woocommerce_pos_plugin_dir = \dirname( __DIR__, 2 );

/*
 * A deliberately local autoloader: vendor/autoload.php resolves $baseDir to the
 * checkout composer was installed in, which is the wrong tree when this script
 * is run from a git worktree, and the wrong path again inside the wp-env
 * container, where the plugin is bind-mounted under wp-content/plugins/. Both
 * are handled by deriving every path from __DIR__ instead, so keep this
 * autoloader rather than switching to vendor/autoload.php.
 */
spl_autoload_register(
	static function ( string $class ) use ( $woocommerce_pos_plugin_dir ): void {
		$map = array(
			'WCPOS\\WooCommercePOS\\Tests\\' => $woocommerce_pos_plugin_dir . '/tests/includes/',
			'WCPOS\\WooCommercePOS\\'        => $woocommerce_pos_plugin_dir . '/includes/',
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

$woocommerce_pos_written = WCPOS\WooCommercePOS\Tests\Templates\Thermal\Thermal_Golden_Corpus::write_all();

echo 'Wrote ' . \count( $woocommerce_pos_written ) . ' thermal golden fixtures.' . PHP_EOL;
