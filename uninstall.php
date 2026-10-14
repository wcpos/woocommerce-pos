<?php
/**
 * Fired when the plugin is uninstalled (deleted via the WordPress admin).
 *
 * WCPOS distinguishes three kinds of data:
 *
 * - Derived/operational state — sync tables (rebuildable from WooCommerce
 *   data on reinstall), cron events, transients, the print-job queue, JWT
 *   secrets/tokens, sync watermarks and version latches, plugin-owned upload
 *   directories and log files. Always removed.
 *
 * - User-authored configuration — POS settings, receipt templates (and their
 *   revisions, taxonomies, and options), the receipt sequence counter, the
 *   cashier role. Preserved by default (a delete/reinstall cycle must not
 *   destroy a merchant's receipt designs or numbering continuity). Removed
 *   only when a full wipe is requested via the WCPOS_REMOVE_ALL_DATA
 *   constant (wp-config.php) or the wcpos_remove_all_data option.
 *
 * - Data owned by others — WCPOS Pro's options (woocommerce_pos_pro_*,
 *   wcpos_stores_migrated) are NEVER touched, even on a full wipe: they
 *   belong to a different plugin. POS metadata on orders/products
 *   (_woocommerce_pos_* post/order meta) is part of WooCommerce's order
 *   history and is also never touched.
 *
 * This file runs standalone — the plugin is NOT loaded — so table, option,
 * hook, and post-type names are hardcoded. Test_Uninstall guards them
 * against drift from the class constants.
 *
 * There is deliberately no is-this-really-an-uninstall guard at the top:
 * definitions live in uninstall-functions.php, loaded here unless already
 * present, and the sweep at the bottom is gated on WP_UNINSTALL_PLUGIN.
 * Loading the definitions (tests, direct access) has no side effects.
 *
 * @author  Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     https://wcpos.com
 * @package WooCommercePOS
 */

// A second load in the same request (both plugins deleted at once) must not
// redeclare: Pro's uninstall loads its bundled copy of these definitions first.
if ( ! function_exists( 'woocommerce_pos_uninstall_site' ) ) {
	require_once __DIR__ . '/uninstall-functions.php';
}

// Run the sweep only when WordPress is actually uninstalling the plugin.
if ( \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	// Report churn ONCE, before anything is deleted: the report needs the
	// consent setting, the site UUID and the user meta that the sweep removes,
	// and firing it per site would put a network request in front of every blog
	// of a large multisite uninstall.
	woocommerce_pos_uninstall_report();

	if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
		// number => 0 removes WP_Site_Query's default 100-site cap.
		$woocommerce_pos_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $woocommerce_pos_sites as $woocommerce_pos_site_id ) {
			switch_to_blog( (int) $woocommerce_pos_site_id );
			woocommerce_pos_uninstall_site();
			restore_current_blog();
		}
	} else {
		woocommerce_pos_uninstall_site();
	}

	// Network-wide surfaces, once: user meta is a shared table, and the
	// object-cache flush is global.
	woocommerce_pos_uninstall_network();
	wp_cache_flush();
}
