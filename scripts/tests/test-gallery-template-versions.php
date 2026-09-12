#!/usr/bin/env php
<?php
/**
 * Regression tests for scripts/gallery-template-versions.php.
 *
 * The guard is only as good as its parser: if `registry_versions()` silently returns nothing for
 * a key, the guard reports "new template, no bump required" and waves the change through. That
 * failure is invisible — a green check on exactly the change it exists to catch — so the parser
 * is pinned here against the shape the real registry uses.
 *
 * @package WCPOS\WooCommercePOS
 */

declare( strict_types=1 );

require_once __DIR__ . '/../gallery-template-versions.php';

$failures = 0;

/**
 * Assert two values match.
 *
 * @param mixed  $expected The expected value.
 * @param mixed  $actual   The actual value.
 * @param string $label    What is being asserted.
 *
 * @return void
 */
function assert_same( $expected, $actual, string $label ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "  ok  {$label}\n";

		return;
	}
	++$failures;
	fwrite(
		STDERR,
		sprintf(
			"  FAIL  %s\n        expected: %s\n        actual:   %s\n",
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		)
	);
}

// The shape the real registry uses: three-tab top-level keys, nested arrays deeper, and a
// 'version' among many other fields.
$registry = "<?php\nreturn array(\n"
	. "\t\t\t'display-pocket' => array(\n"
	. "\t\t\t\t'title'         => __( 'Pocket', 'woocommerce-pos' ),\n"
	. "\t\t\t\t'type'          => 'display',\n"
	. "\t\t\t\t'version'       => 1,\n"
	. "\t\t\t\t'preview_data'  => null,\n"
	. "\t\t\t),\n"
	. "\t\t\t'thermal-simple-80mm' => array(\n"
	. "\t\t\t\t'title'         => __( 'Simple', 'woocommerce-pos' ),\n"
	. "\t\t\t\t'version'       => 4,\n"
	. "\t\t\t),\n"
	. ");\n";

$versions = registry_versions( $registry );

assert_same( 1, $versions['display-pocket'] ?? null, 'reads the version of the first entry' );
assert_same( 4, $versions['thermal-simple-80mm'] ?? null, 'reads the version of a later entry' );
assert_same( 2, count( $versions ), 'finds exactly the top-level entries' );

// A 'version' nested inside another field must not be mistaken for the entry's own. Taking the
// first match anywhere in the chunk read 99 here instead of 3 — the guard would then compare a
// number nobody edits and wave real changes through.
$nested = "<?php\n"
	. "\t\t\t'thermal-detailed' => array(\n"
	. "\t\t\t\t'preview_data'  => array(\n"
	. "\t\t\t\t\t'currency' => array(\n"
	. "\t\t\t\t\t\t'version' => 99,\n"
	. "\t\t\t\t\t),\n"
	. "\t\t\t\t),\n"
	. "\t\t\t\t'version'       => 3,\n"
	. "\t\t\t),\n";

$nested_versions = registry_versions( $nested );
assert_same( array( 'thermal-detailed' => 3 ), $nested_versions, "ignores a 'version' nested inside another field" );

// An entry whose version cannot be read must be REPORTED as unreadable, not dropped. Dropping it
// made it look identical to a deleted entry, which the guard waves through — so deleting the
// version line was a way to change a template with no bump and a green check.
$unversioned = "<?php\n"
	. "\t\t\t'no-version' => array(\n"
	. "\t\t\t\t'title'         => __( 'Nope', 'woocommerce-pos' ),\n"
	. "\t\t\t),\n";

$unversioned_parsed = registry_versions( $unversioned );
assert_same( true, array_key_exists( 'no-version', $unversioned_parsed ), 'keeps an entry whose version is missing' );
// Not `?? 'absent'` — the null-coalescing operator cannot tell a null value from a missing key,
// which is the very distinction under test.
assert_same( null, $unversioned_parsed['no-version'], 'reports the missing version as null' );

// A preview fixture shares its basename with a template but is never copied into a merchant's
// database. Treating it as a template change would force a bump, and the bump would mark every
// edited copy outdated and silently rewrite every untouched one.
assert_same(
	array( 'templates/gallery/invoice.xml' ),
	array_values( gallery_content_files( array( 'templates/gallery/invoice.xml', 'templates/gallery/preview-data/invoice.json', 'templates/gallery/README.md' ) ) ),
	'keeps only top-level gallery content files'
);

// The real registry must parse, and every key it ships must carry a version — otherwise the guard
// silently treats that template as new and never asks for a bump.
$real = file_get_contents( __DIR__ . '/../../includes/Templates/Gallery_Registry.php' );
if ( false === $real ) {
	fwrite( STDERR, "  FAIL  could not read the real registry\n" );
	++$failures;
} else {
	$real_versions = registry_versions( $real );
	assert_same( true, count( $real_versions ) > 30, 'parses every entry in the real registry' );
	$missing = array();
	foreach ( $real_versions as $key => $version ) {
		if ( $version < 1 ) {
			$missing[] = $key;
		}
	}
	assert_same( array(), $missing, 'every real registry entry has a usable version' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failure(s).\n" );
	exit( 1 );
}

echo "All gallery-template-version guard tests passed.\n";
