<?php
/**
 * Replays WordPress's plugin include phase for a site running free + Pro.
 *
 * Run as a subprocess by Test_Bootstrap_Pro_Coexistence so a redeclaration fatal
 * is observable as an exit code instead of taking the test runner down with it.
 *
 * argv[1] = path to a duplicate of includes/API/V2/Ping.php, standing in for the
 *           copy Pro ships at vendor/wcpos/woocommerce-pos/. It must be a real
 *           second file: require_once dedupes on resolved path, so a symlink back
 *           to our own copy is exactly the dev-only arrangement that hides this.
 * argv[2] = path to the free plugin's woocommerce-pos.php.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- throwaway harness, not shipped code.

$wcpos_duplicate_ping = $argv[1];
$wcpos_free_bootstrap = $argv[2];

/*
 * Stubs for the handful of WordPress functions the free bootstrap touches before
 * it bails out on a Pro-active site. Deliberately minimal: if the bootstrap ever
 * needs more than this before bailing, that is itself worth failing on.
 */
function plugin_basename( $file ) {
	$parts = explode( '/', str_replace( '\\', '/', $file ) );

	return $parts[ \count( $parts ) - 2 ] . '/' . end( $parts );
}
function plugin_dir_path( $file ) {
	return \dirname( $file ) . '/';
}
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( \dirname( $file ) ) . '/';
}
function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}
function is_multisite() {
	return false;
}
function get_site_option( $option, $default_value = false ) {
	return $default_value;
}
function sanitize_text_field( $value ) {
	return $value;
}
function wp_unslash( $value ) {
	return $value;
}
function get_option( $option, $default_value = false ) {
	if ( 'active_plugins' === $option ) {
		// Only needs to report Pro as active so the free bootstrap takes its
		// bail-out branch. The include order below is fixed by this harness, not
		// derived from this list — the guard under test is order-independent.
		return array(
			'woocommerce-pos-pro/woocommerce-pos-pro.php',
			'woocommerce-pos/woocommerce-pos.php',
		);
	}

	return $default_value;
}

// Pro is included first and declares the class from its bundled copy.
require_once $wcpos_duplicate_ping;

// Then WordPress includes the free plugin. This is the line that used to fatal.
require_once $wcpos_free_bootstrap;

$wcpos_reflection = new ReflectionClass( 'WCPOS\WooCommercePOS\API\V2\Ping' );

echo 'PING_DECLARED_IN=', $wcpos_reflection->getFileName(), "\n";
echo "BOOTSTRAP_SURVIVED\n";
