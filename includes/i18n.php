<?php
/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that its ready for translation.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 * I18n class.
 */
class i18n { // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	/**
	 * Load the plugin text domain for translation.
	 */
	public function construct() {
		load_plugin_textdomain( 'woocommerce-pos', false, PLUGIN_PATH . '/languages/' );
	}
}
