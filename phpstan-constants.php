<?php
/**
 * PHPStan bootstrap file for namespace constants.
 *
 * PHPStan cannot resolve dynamic define() calls using __NAMESPACE__.
 * This file declares the constants explicitly for static analysis.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

\define( 'WCPOS\WooCommercePOS\VERSION', '1.8.7' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_NAME', 'woocommerce-pos' );
\define( 'WCPOS\WooCommercePOS\SHORT_NAME', 'wcpos' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_FILE', 'woocommerce-pos/woocommerce-pos.php' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_PATH', '/path/to/woocommerce-pos/' );
\define( 'WCPOS\WooCommercePOS\PLUGIN_URL', 'https://example.com/wp-content/plugins/woocommerce-pos/' );
\define( 'WCPOS\WooCommercePOS\WC_MIN_VERSION', '5.3' );
\define( 'WCPOS\WooCommercePOS\PHP_MIN_VERSION', '7.4' );
\define( 'WCPOS\WooCommercePOS\MIN_PRO_VERSION', '1.8.7' );
