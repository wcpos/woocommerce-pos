<?php
/**
 * Pins the invariant behind the free+Pro redeclaration fatal (PR #1818 / Pro #502):
 * every class this bootstrap declares BEFORE its Pro-is-active bail-out must
 * survive that class already having been declared from a DIFFERENT file path,
 * because that is exactly what Pro's bundled free copy does on a both-active site.
 *
 * Run as a subprocess by Test_Bootstrap_Pro_Coexistence: the bootstrap must
 * execute where the classes are not already loaded, and a redeclaration fatal is
 * not catchable in-process.
 *
 * argv[1] = path to the free plugin's woocommerce-pos.php
 * argv[2] = mode:
 *   discover — require the bootstrap with Pro active (so it returns at the
 *              bail-out) and print one "FQCN|declaring-file" line per class it
 *              declared from inside the plugin. This is the live inventory of
 *              the dangerous set; today it is exactly {API\V2\Ping}.
 *   collide  — argv[3] is a manifest file of "FQCN|duplicate-file" lines. Load
 *              every duplicate FIRST (Pro's position in the load order), then
 *              require the bootstrap. An unguarded eager require fatals here,
 *              exactly as it fatalled on merchant sites.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- throwaway harness, not shipped code.

// realpath so the declared-in-plugin filter below compares like with like —
// ReflectionClass::getFileName() always returns a resolved absolute path.
$wcpos_bootstrap = realpath( $argv[1] );
$wcpos_mode      = $argv[2];

/*
 * WordPress stubs for the code above the bail-out. Deliberately minimal: if the
 * bootstrap grows a new WP call up there, this harness dies with "Call to
 * undefined function" and the test reports the stubs as stale — add the stub.
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
		// Pro active: the bootstrap must return at its bail-out, so only the
		// code ABOVE it — the dangerous set — executes.
		$plugins = array(
			'woocommerce-pos/woocommerce-pos.php',
			'woocommerce-pos-pro/woocommerce-pos-pro.php',
		);
		sort( $plugins );

		return $plugins;
	}

	return $default_value;
}

if ( 'collide' === $wcpos_mode ) {
	$wcpos_manifest = file( $argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $wcpos_manifest as $wcpos_line ) {
		list( , $wcpos_duplicate ) = explode( '|', $wcpos_line, 2 );
		require_once $wcpos_duplicate;
	}
}

$wcpos_before = get_declared_classes();

require_once $wcpos_bootstrap;

$wcpos_plugin_dir = \dirname( $wcpos_bootstrap );
foreach ( array_diff( get_declared_classes(), $wcpos_before ) as $wcpos_class ) {
	$wcpos_file = ( new ReflectionClass( $wcpos_class ) )->getFileName();
	if ( \is_string( $wcpos_file ) && 0 === strpos( $wcpos_file, $wcpos_plugin_dir . '/' ) ) {
		echo 'DECLARED=', $wcpos_class, '|', $wcpos_file, "\n";
	}
}

if ( 'collide' === $wcpos_mode ) {
	foreach ( $wcpos_manifest as $wcpos_line ) {
		list( $wcpos_class, $wcpos_duplicate ) = explode( '|', $wcpos_line, 2 );
		echo 'RESOLVED=', $wcpos_class, '|', ( new ReflectionClass( $wcpos_class ) )->getFileName(), "\n";
	}
}

echo "HARNESS_COMPLETED\n";
