<?php
/**
 * Polyfills for functions accidentally prefixed in vendored dependencies.
 *
 * The authoritative fix lives in php-scoper/scoper.inc.php (exclude-functions),
 * which stops the scoper from prefixing these calls in the first place. This
 * shim only protects sites still running a vendor_prefixed build produced
 * before that fix; do not add new symbols here — exclude them in the scoper
 * config instead.
 *
 * @package WCPOS\Vendor
 */

namespace WCPOS\Vendor; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- matches prefixed vendor namespace.

if ( ! \function_exists( __NAMESPACE__ . '\\http_get_last_response_headers' ) ) {
	/**
	 * Proxy PHP's response-header helper for prefixed vendor code.
	 *
	 * @return array<int, string>
	 */
	function http_get_last_response_headers(): array {
		if ( \function_exists( 'http_get_last_response_headers' ) ) {
			$headers = \http_get_last_response_headers();

			return \is_array( $headers ) ? $headers : array();
		}

		return array();
	}
}

if ( ! \function_exists( __NAMESPACE__ . '\\http_clear_last_response_headers' ) ) {
	/**
	 * Proxy PHP's response-header reset helper for prefixed vendor code.
	 */
	function http_clear_last_response_headers(): void {
		if ( \function_exists( 'http_clear_last_response_headers' ) ) {
			\http_clear_last_response_headers();
		}
	}
}
