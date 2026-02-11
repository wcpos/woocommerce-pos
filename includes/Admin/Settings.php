<?php
/**
 * POS Settings.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Services\Extensions as ExtensionsService;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Class Settings.
 */
class Settings {
	/**
	 * Settings constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'remove_admin_notices' ) );

		/*
		 * Initializes the settings for WCPOS in the admin panel.
		 *
		 * This action hook can be used to run additional initialization routines
		 * when the WCPOS settings are being set up in the admin panel.
		 *
		 * @since 1.0.0
		 *
		 * @param Settings $this Settings class instance for the current admin context.
		 */
		do_action( 'woocommerce_pos_admin_settings_init', $this );
	}

	/**
	 * Output the settings pages.
	 */
	public static function display_settings_page(): void {
		printf(
			'<div id="woocommerce-pos-settings">
			<div id="woocommerce-pos-js-error" class="wrap">
				<h1>%s</h1>
				<p>%s <a href="mailto:support@wcpos.com">support@wcpos.com</a></p>
			</div>
		</div>',
			esc_html__( 'Error', 'woocommerce-pos' ),
			esc_html__( 'Settings failed to load, please contact support', 'woocommerce-pos' )
		);
	}

	/**
	 * Remove all admin notices on our settings page.
	 *
	 * @return void
	 */
	public function remove_admin_notices(): void {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Enqueue assets.
	 *
	 * Note: SCRIPT_DEBUG should be set in the wp-config.php file for debugging
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$is_development = isset( $_ENV['DEVELOPMENT'] ) && sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) );
		$dir            = $is_development ? 'build' : 'assets';

		wp_enqueue_style(
			PLUGIN_NAME . '-settings-styles',
			PLUGIN_URL . $dir . '/css/settings.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-settings',
			PLUGIN_URL . $dir . '/js/settings.js',
			array(
				'react',
				'react-dom',
				'wp-api-fetch',
				'wp-url',
				'lodash',
			),
			VERSION,
			true
		);

		// Add inline script.
		wp_add_inline_script( PLUGIN_NAME . '-settings', $this->inline_script(), 'before' );
	}

	/**
	 * Generate the inline script for settings.
	 *
	 * @return string
	 */
	private function inline_script(): string {
		$settings_service = SettingsService::instance();
		$barcodes         = array_values( $settings_service->get_barcodes() );
		$order_statuses   = $settings_service->get_order_statuses();
		$new_ext_count    = $this->get_new_extensions_count();

		return \sprintf(
			'var wcpos = wcpos || {}; wcpos.settings = {
            barcodes: %s,
            order_statuses: %s,
            newExtensionsCount: %s
        }; wcpos.translationVersion = %s;',
			json_encode( $barcodes ),
			json_encode( $order_statuses ),
			json_encode( $new_ext_count ),
			json_encode( TRANSLATION_VERSION )
		);
	}

	/**
	 * Get the count of extensions the current user hasn't seen yet.
	 *
	 * Uses the cached catalog transient to avoid remote fetches on every page load.
	 * Returns null if the catalog hasn't been fetched yet.
	 *
	 * @return int|null
	 */
	private function get_new_extensions_count(): ?int {
		$cached = get_transient( ExtensionsService::TRANSIENT_KEY );

		if ( false === $cached || ! \is_array( $cached ) ) {
			return null;
		}

		$catalog_slugs = array_column( $cached, 'slug' );
		$seen_slugs    = get_user_meta( get_current_user_id(), '_wcpos_seen_extension_slugs', true );

		if ( ! \is_array( $seen_slugs ) ) {
			$seen_slugs = array();
		}

		$new_slugs = array_diff( $catalog_slugs, $seen_slugs );

		return \count( $new_slugs );
	}
}
