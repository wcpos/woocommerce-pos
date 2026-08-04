<?php
/**
 * Audit of function calls prefixed by php-scoper in vendor_prefixed.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WP_UnitTestCase;

/**
 * Guards against php-scoper prefixing PHP functions it does not recognise.
 *
 * The scoper rewrites calls to functions missing from its internal symbol
 * table into the WCPOS\Vendor namespace. When the unknown function is a PHP
 * native added after the scoper's symbol table was built (e.g. PHP 8.4's
 * http_get_last_response_headers()/http_clear_last_response_headers() called
 * by Dompdf), the prefixed call has no definition and fatals at runtime
 * unless a shim exists in includes/Vendor_Prefixed_Polyfills.php.
 *
 * This audit scans every built file for fully-qualified WCPOS\Vendor function
 * calls and asserts each one resolves, so a scoper regression fails CI at the
 * next test run instead of fataling on customer sites.
 */
class Vendor_Prefixed_Call_Audit_Test extends WP_UnitTestCase {
	/**
	 * Every \WCPOS\Vendor\*() function call in vendor_prefixed must resolve.
	 */
	public function test_all_prefixed_function_calls_resolve_to_defined_functions(): void {
		// Arrange.
		$vendor_prefixed_dir = \dirname( __DIR__, 2 ) . '/vendor_prefixed';
		$this->assertDirectoryExists( $vendor_prefixed_dir );

		// Function calls are a single lowercase segment directly followed by an
		// open paren; class references (PascalCase, sub-namespaces, ::) never
		// match. The source literal is \WCPOS\Vendor\name(.
		$call_pattern = '/\\\\WCPOS\\\\Vendor\\\\([a-z_][a-z0-9_]*)\s*\(/';

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $vendor_prefixed_dir, FilesystemIterator::SKIP_DOTS )
		);

		// Act.
		$unresolved = array();
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );
			if ( false === $content || ! preg_match_all( $call_pattern, $content, $matches ) ) {
				continue;
			}

			foreach ( array_unique( $matches[1] ) as $function ) {
				if ( ! \function_exists( 'WCPOS\\Vendor\\' . $function ) ) {
					$relative_path             = substr( $file->getPathname(), \strlen( $vendor_prefixed_dir ) + 1 );
					$unresolved[ $function ][] = $relative_path;
				}
			}
		}

		// Assert.
		$this->assertSame(
			array(),
			$unresolved,
			'php-scoper prefixed function calls that resolve to no definition. ' .
			'The scoper did not recognise these functions (usually PHP natives newer ' .
			'than its symbol table) and rewrote them into WCPOS\Vendor, where nothing ' .
			'defines them — they will fatal at runtime. Either stop the scoper from ' .
			'prefixing them (php-scoper/scoper.inc.php) or add shims to ' .
			'includes/Vendor_Prefixed_Polyfills.php. Unresolved: ' . wp_json_encode( $unresolved )
		);
	}
}
