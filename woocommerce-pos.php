<?php
/**
 * Plugin Name:       WooCommerce POS
 * Plugin URI:        https://wordpress.org/plugins/woocommerce-pos/
 * Description:       A simple front-end for taking WooCommerce orders at the Point of Sale. Requires <a href="http://wordpress.org/plugins/woocommerce/">WooCommerce</a>.
 * Version:           1.6.3
 * Author:            kilbot
 * Author URI:        http://wcpos.com
 * Text Domain:       woocommerce-pos
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Tested up to:      6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC tested up to:   9.0
 * WC requires at least: 5.3
 *
 * @see      http://wcpos.com
 * @package  WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

// Define plugin constants.
const VERSION     = '1.6.3';
const PLUGIN_NAME = 'woocommerce-pos';
const SHORT_NAME  = 'wcpos';
\define( __NAMESPACE__ . '\PLUGIN_FILE', plugin_basename( __FILE__ ) ); // 'woocommerce-pos/woocommerce-pos.php'
\define( __NAMESPACE__ . '\PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
\define( __NAMESPACE__ . '\PLUGIN_URL', trailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

// Minimum requirements.
const WC_MIN_VERSION  = '5.3';
const PHP_MIN_VERSION = '7.4';
const MIN_PRO_VERSION = '1.5.0';

// Load .env flags (for development).
function wcpos_load_env( $file ) {
	if ( ! file_exists( $file ) ) {
		return;
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		if ( strpos( trim( $line ), '#' ) === 0 ) {
			continue;
		}

		list($name, $value) = explode( '=', $line, 2 );
		$name = trim( $name );
		$value = trim( $value );

		if ( ! array_key_exists( $name, $_SERVER ) && ! array_key_exists( $name, $_ENV ) ) {
			putenv( sprintf( '%s=%s', $name, $value ) );
			$_ENV[ $name ] = $value;
		}
	}
}

// Autoload vendor and prefixed libraries.
function wcpos_load_autoloaders() {
	$vendor_autoload = __DIR__ . '/vendor/autoload.php';
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
if ( ! class_exists( \WCPOS\WooCommercePOS\Activator::class ) || ! class_exists( \WCPOS\WooCommercePOS\Deactivator::class ) ) {
	add_action(
		'admin_notices',
		function (): void {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The WooCommerce POS plugin failed to load correctly.', 'woocommerce-pos' ); ?></p>
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

// Declare HPOS compatible.
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
