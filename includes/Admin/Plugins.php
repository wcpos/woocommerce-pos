<?php

/**
 * WP Plugin Updates
 *
 * @package  WCPOS\WooCommercePOS\Admin\Plugins
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
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
	 *
	 * @param string[] $actions An array of plugin action links. By default this can include 'activate',
	 *                              'deactivate', and 'delete'. With Multisite active this can also include
	 *                              'network_active' and 'network_only' items.
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array $plugin_data An array of plugin data. See `get_plugin_data()`.
	 * @param string $context The plugin context. By default this can include 'all', 'active', 'inactive',
	 *                              'recently_activated', 'upgrade', 'mustuse', 'dropins', and 'search'.
	 */
	public function plugin_action_links( array $actions ): array {
		$actions['settings'] = '<a href="' . admin_url( 'admin.php?page=woocommerce-pos-settings' ) . '">' .
		                       /* translators: wordpress */
		                       __( 'Settings' ) . '</a>';

		return $actions;
	}

	/**
	 * Fires at the end of the update message container in each row of the plugins list table.
	 * Thanks to: http://andidittrich.de/2015/05/howto-upgrade-notice-for-wordpress-plugins.html
	 *
	 *
	 * @param array $plugin_data {
	 *     An array of plugin metadata.
	 *
	 * @type string $name The human-readable name of the plugin.
	 * @type string $plugin_uri Plugin URI.
	 * @type string $version Plugin version.
	 * @type string $description Plugin description.
	 * @type string $author Plugin author.
	 * @type string $author_uri Plugin author URI.
	 * @type string $text_domain Plugin text domain.
	 * @type string $domain_path Relative path to the plugin's .mo file(s).
	 * @type bool $network Whether the plugin can only be activated network wide.
	 * @type string $title The human-readable title of the plugin.
	 * @type string $author_name Plugin author's name.
	 * @type bool $update Whether there's an available update. Default null.
	 * }
	 *
	 * @param array $r {
	 *      An array of metadata about the available plugin update.
	 *
	 * @type int $id Plugin ID.
	 * @type string $slug Plugin slug.
	 * @type string $new_version New plugin version.
	 * @type string $url Plugin URL.
	 * @type string $package Plugin update package URL.
	 * }
	 * @since 2.8.0
	 *
	 */
	public function plugin_update_message( $plugin_data, $r ) {
		if ( isset( $r->upgrade_notice ) && strlen( trim( $r->upgrade_notice ) ) > 0 ) {
			echo '<p style="background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><strong>' .
			     /* translators: wordpress */
			     __( 'Important:' ) . '</strong> ';
			echo esc_html( $r->upgrade_notice ), '</p>';
		}
	}

}
