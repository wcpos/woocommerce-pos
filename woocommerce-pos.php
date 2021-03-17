<?php
/**
 * Plugin Name:       WooCommerce POS
 * Plugin URI:        https://wordpress.org/plugins/woocommerce-pos/
 * Description:       A simple front-end for taking WooCommerce orders at the Point of Sale. Requires <a href="http://wordpress.org/plugins/woocommerce/">WooCommerce</a>.
 * Version:           0.5.0-beta
 * Author:            kilbot
 * Author URI:        http://wcpos.com
 * Text Domain:       woocommerce-pos
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:       /languages
 * WC tested up to:   3.5
 * WC requires at least: 2.3.7
 *
 * @package   WooCommerce POS
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

/**
 * Define plugin constants.
 */
define( __NAMESPACE__ . '\VERSION', '0.5.0-beta' );
define( __NAMESPACE__ . '\PLUGIN_NAME', 'woocommerce-pos' );
define( __NAMESPACE__ . '\PLUGIN_FILE', plugin_basename( __FILE__ ) ); // 'woocommerce-pos/woocommerce-pos.php'
define( __NAMESPACE__ . '\PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( __NAMESPACE__ . '\PLUGIN_URL', trailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

/**
 * Autoloader
 */
require_once 'vendor/autoload.php';

/**
 * Activate plugin
 */
new Activator();

/**
 * Deactivate plugin
 */
new Deactivator();
