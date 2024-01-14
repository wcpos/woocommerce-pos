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
 */

namespace WCPOS\WooCommercePOS;

class i18n {

	/**
	 * Load the plugin text domain for translation.
	 */
	public function construct() {
		load_plugin_textdomain( 'woocommerce-pos', false, PLUGIN_PATH . '/languages/' );
	}
}
