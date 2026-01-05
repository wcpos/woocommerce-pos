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
use const WCPOS\WooCommercePOS\VERSION;

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

		// Add "Upgrade to Pro" link if Pro is not installed.
		if ( ! defined( 'WCPOS\WooCommercePOSPro\VERSION' ) ) {
			$actions['upgrade'] = '<a href="https://wcpos.com/pro" target="_blank" style="color: #d63638; font-weight: 600;">' .
				__( 'Upgrade to Pro', 'woocommerce-pos' ) . '</a>';
		}

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
	 * @param object $r {
	 *                 An object of metadata about the available plugin update.
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
		// Check if updating to v1.8.x from an earlier version.
		$new_version     = isset( $r->new_version ) ? $r->new_version : '';
		$current_version = VERSION;

		// Show major update notice when upgrading to 1.8.x from earlier versions.
		if ( version_compare( $new_version, '1.8.0', '>=' ) && version_compare( $current_version, '1.8.0', '<' ) ) {
			$this->show_major_update_notice();
		}

		// Show any additional upgrade notice from readme.txt.
		if ( isset( $r->upgrade_notice ) && \strlen( trim( $r->upgrade_notice ) ) > 0 ) {
			echo '<p style="background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><strong>' .
				 // translators: wordpress
				 __( 'Important:' ) . '</strong> ';
			echo esc_html( $r->upgrade_notice ), '</p>';
		}
	}

	/**
	 * Display a major update notice for v1.8.
	 */
	private function show_major_update_notice(): void {
		?>
		<hr style="margin: 15px 0; border: 0; border-top: 1px solid #ffb900;">
		<div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border: 1px solid #ffb900; border-radius: 4px; padding: 12px 15px; margin-top: 10px;">
			<p style="margin: 0 0 10px 0; font-weight: 600; color: #856404; font-size: 14px;">
				<span class="dashicons dashicons-warning" style="color: #ffb900; margin-right: 5px;"></span>
				<?php esc_html_e( 'Major Update', 'woocommerce-pos' ); ?>
			</p>
			<p style="margin: 0 0 10px 0; color: #856404;">
				<?php esc_html_e( 'Update when you have time to test the POS. You can rollback to the previous version if needed.', 'woocommerce-pos' ); ?>
			</p>
			<p style="margin: 0; padding: 10px; background-color: rgba(255,255,255,0.5); border-radius: 3px; color: #0073aa;">
				<span class="dashicons dashicons-star-filled" style="color: #ffb900; margin-right: 5px;"></span>
				<strong><?php esc_html_e( 'Pro Users:', 'woocommerce-pos' ); ?></strong>
				<?php
				printf(
					/* translators: %s: URL to My Account page */
					esc_html__( 'WCPOS Pro is now a standalone plugin. Download the latest version from %s', 'woocommerce-pos' ),
					'<a href="https://wcpos.com/my-account" target="_blank" style="color: #0073aa; text-decoration: underline;">wcpos.com/my-account</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
