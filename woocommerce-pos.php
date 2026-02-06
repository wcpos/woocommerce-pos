<?php
/**
 * Plugin Name:       WCPOS – Point of Sale for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/woocommerce-pos/
 * Description:       A simple front-end for taking WooCommerce orders at the Point of Sale. Requires <a href="http://wordpress.org/plugins/woocommerce/">WooCommerce</a>.
 * Version:           1.8.8
 * Author:            kilbot
 * Author URI:        http://wcpos.com
 * Text Domain:       woocommerce-pos
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC tested up to:   10.0
 * WC requires at least: 5.3.
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

// Define plugin constants (use define() with checks to avoid conflicts when Pro plugin is active).
if ( ! \defined( __NAMESPACE__ . '\VERSION' ) ) {
	\define( __NAMESPACE__ . '\VERSION', '1.8.8' );
}
if ( ! \defined( __NAMESPACE__ . '\TRANSLATION_VERSION' ) ) {
	\define( __NAMESPACE__ . '\TRANSLATION_VERSION', '2026.2.2' );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_NAME' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_NAME', 'woocommerce-pos' );
}
if ( ! \defined( __NAMESPACE__ . '\SHORT_NAME' ) ) {
	\define( __NAMESPACE__ . '\SHORT_NAME', 'wcpos' );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_FILE' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_FILE', plugin_basename( __FILE__ ) ); // 'woocommerce-pos/woocommerce-pos.php'
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_PATH' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
}
if ( ! \defined( __NAMESPACE__ . '\PLUGIN_URL' ) ) {
	\define( __NAMESPACE__ . '\PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
}

// Minimum requirements.
if ( ! \defined( __NAMESPACE__ . '\WC_MIN_VERSION' ) ) {
	\define( __NAMESPACE__ . '\WC_MIN_VERSION', '5.3' );
}
if ( ! \defined( __NAMESPACE__ . '\PHP_MIN_VERSION' ) ) {
	\define( __NAMESPACE__ . '\PHP_MIN_VERSION', '7.4' );
}
if ( ! \defined( __NAMESPACE__ . '\MIN_PRO_VERSION' ) ) {
	\define( __NAMESPACE__ . '\MIN_PRO_VERSION', '1.8.7' );
}

// If Pro plugin is active, bail out early to avoid conflicts.
// Pro includes all free plugin functionality, so there's no need to initialize both.
// We check the option directly since Pro may not have loaded yet (alphabetical order).
$wcpos_pro_plugin_file = 'woocommerce-pos-pro/woocommerce-pos-pro.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- namespaced file scope.
$wcpos_active_plugins  = get_option( 'active_plugins', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- namespaced file scope.
$wcpos_pro_is_active   = \in_array( $wcpos_pro_plugin_file, $wcpos_active_plugins, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- namespaced file scope.

// Also check network-activated plugins on multisite.
if ( ! $wcpos_pro_is_active && is_multisite() ) {
	$wcpos_network_plugins = get_site_option( 'active_sitewide_plugins', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- namespaced file scope.
	$wcpos_pro_is_active = isset( $wcpos_network_plugins[ $wcpos_pro_plugin_file ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- namespaced file scope.
}

if ( $wcpos_pro_is_active ) {
	return;
}

/**
 * Load .env flags for development.
 *
 * @param string $file The path to the .env file.
 */
function wcpos_load_env( $file ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- namespaced.
	if ( ! file_exists( $file ) ) {
		return;
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		if ( 0 === strpos( trim( $line ), '#' ) ) {
			continue;
		}

		list($name, $value) = explode( '=', $line, 2 );
		$name               = trim( $name );
		$value              = trim( $value );

		if ( ! \array_key_exists( $name, $_SERVER ) && ! \array_key_exists( $name, $_ENV ) ) {
			putenv( \sprintf( '%s=%s', $name, $value ) );
			$_ENV[ $name ] = $value;
		}
	}
}

/**
 * Autoload vendor and prefixed libraries.
 */
function wcpos_load_autoloaders(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- namespaced.
	$vendor_autoload          = __DIR__ . '/vendor/autoload.php';
	$vendor_prefixed_autoload = __DIR__ . '/vendor_prefixed/autoload.php';

	if ( file_exists( $vendor_autoload ) ) {
		require_once $vendor_autoload;
	}
	if ( file_exists( $vendor_prefixed_autoload ) ) {
		require_once $vendor_prefixed_autoload;
	}
}

wcpos_load_autoloaders();

// Environment variables.
wcpos_load_env( __DIR__ . '/.env' );

// Error handling for autoload failure.
if ( ! class_exists( Activator::class ) || ! class_exists( Deactivator::class ) ) {
	add_action(
		'admin_notices',
		function (): void {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The WCPOS plugin failed to load correctly.', 'woocommerce-pos' ); ?></p>
			</div>
			<?php
		}
	);

	return; // Exit early if classes are not found.
}

// Activate plugin.
new Activator();

// Deactivate plugin.
new Deactivator();

// Declare WooCommerce feature compatibility.
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_instance_caching', __FILE__, true );
		}
	}
);

// Caching can cause all sorts of issues with the POS, so we attempt to disable caching for POS templates.
add_action(
	'plugins_loaded',
	function (): void {
		// Check request URI as early as possible.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		if ( preg_match( '#^/(wcpos-login|wcpos-checkout)(/|$)#i', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ) {
			// 1) Hard kill all LSCache features (cache + optimisation).
			if ( ! \defined( 'LITESPEED_DISABLE_ALL' ) ) {
				\define( 'LITESPEED_DISABLE_ALL', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- third-party constant.
			}

			// 2) Belt-and-braces: mark the response non-cacheable for older LSCache versions.
			if ( ! \defined( 'LSCACHE_NO_CACHE' ) ) {
				\define( 'LSCACHE_NO_CACHE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- third-party constant.
			}

			// 3) Disable W3 Total Cache minify
			if ( ! \defined( 'DONOTMINIFY' ) ) {
				\define( 'DONOTMINIFY', 'true' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- third-party constant.
			}

			// 4) Disable WP Super Cache
			if ( ! \defined( 'DONOTCACHEPAGE' ) ) {
				\define( 'DONOTCACHEPAGE', 'true' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- third-party constant.
			}
		}
	},
	0
);
