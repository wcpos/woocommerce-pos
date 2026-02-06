<?php
/**
 * PHPStan bootstrap file for namespace constants.
 *
 * PHPStan cannot resolve dynamic define() calls using __NAMESPACE__.
 * This file extracts versions from actual plugin files to stay in sync automatically.
 *
 * @package WCPOS\WooCommercePOS
 */

/**
 * Extract version from a WordPress plugin file header.
 *
 * @param string $file Path to plugin file.
 * @return string Version number or empty string if not found.
 */
function extract_plugin_version( string $file ): string {
	if ( ! file_exists( $file ) ) {
		return '';
	}

	$content = file_get_contents( $file );
	if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $content, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

// Extract version from actual plugin file.
$version = extract_plugin_version( __DIR__ . '/woocommerce-pos.php' ) ?: '1.8.7';

// Free plugin constants - automatically extracted from plugin file.
\define( 'WCPOS\WooCommercePOS\VERSION', $version );
\define( 'WCPOS\WooCommercePOS\TRANSLATION_VERSION', '2026.2.0' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_NAME', 'woocommerce-pos' );
\define( 'WCPOS\WooCommercePOS\SHORT_NAME', 'wcpos' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_FILE', 'woocommerce-pos/woocommerce-pos.php' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_PATH', '/path/to/woocommerce-pos/' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_URL', 'https://example.com/wp-content/plugins/woocommerce-pos/' );
\define( 'WCPOS\WooCommercePOS\WC_MIN_VERSION', '5.3' );
\define( 'WCPOS\WooCommercePOS\PHP_MIN_VERSION', '7.4' );
\define( 'WCPOS\WooCommercePOS\MIN_PRO_VERSION', $version );
