<?php

/**
 * WP Plugin Updates.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_FILE;

class Plugins {
	public function __construct() {
		add_filter( 'plugin_action_links_' . PLUGIN_FILE, array( $this, 'plugin_action_links' ) );
		add_action( 'in_plugin_update_message-' . PLUGIN_FILE, array( $this, 'plugin_update_message' ), 10, 2 );
	}

	/**
	 * Filters the list of action links displayed for a specific plugin in the Plugins list table.
	 *
	 * @param string[] $actions     An array of plugin action links. By default this can include 'activate',
	 *                              'deactivate', and 'delete'. With Multisite active this can also include
	 *                              'network_active' and 'network_only' items.
	 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array    $plugin_data An array of plugin data. See `get_plugin_data()`.
	 * @param string   $context     The plugin context. By default this can include 'all', 'active', 'inactive',
	 *                              'recently_activated', 'upgrade', 'mustuse', 'dropins', and 'search'.
	 */
	public function plugin_action_links( array $actions ): array {
		$settings = array(
			'settings' => '<a href="' . admin_url( 'admin.php?page=woocommerce-pos-settings' ) . '">' .
			// translators: wordpress
			__( 'Settings' ) . '</a>',
		);

		return $settings + $actions;
	}

	/**
	 * Fires at the end of the update message container in each row of the plugins list table.
	 * Thanks to: http://andidittrich.de/2015/05/howto-upgrade-notice-for-wordpress-plugins.html.
	 *
	 * @param array $plugin_data {
	 *                           An array of plugin metadata.
	 *
	 * @var string The human-readable name of the plugin.
	 * @var string Plugin URI.
	 * @var string Plugin version.
	 * @var string Plugin description.
	 * @var string Plugin author.
	 * @var string Plugin author URI.
	 * @var string Plugin text domain.
	 * @var string Relative path to the plugin's .mo file(s).
	 * @var bool   Whether the plugin can only be activated network wide.
	 * @var string The human-readable title of the plugin.
	 * @var string Plugin author's name.
	 * @var bool   Whether there's an available update. Default null.
	 *             }
	 *
	 * @param array $r {
	 *                 An array of metadata about the available plugin update.
	 *
	 * @var int    Plugin ID.
	 * @var string Plugin slug.
	 * @var string New plugin version.
	 * @var string Plugin URL.
	 * @var string Plugin update package URL.
	 *             }
	 *
	 * @since 2.8.0
	 */
	public function plugin_update_message( $plugin_data, $r ): void {
		if ( isset( $r->upgrade_notice ) && \strlen( trim( $r->upgrade_notice ) ) > 0 ) {
			echo '<p style="background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><strong>' .
				 // translators: wordpress
				 __( 'Important:' ) . '</strong> ';
			echo esc_html( $r->upgrade_notice ), '</p>';
		}
	}
}
