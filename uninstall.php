<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Init an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * @author    Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see      http://www.woopos.com.au
 * @package  WooCommercePOS
 */

// If uninstall not called from WordPress, then exit.
if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Analytics identity (landing-experiments spec §5.1: deleted on uninstall).
if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
	$woocommerce_pos_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $woocommerce_pos_sites as $woocommerce_pos_site_id ) {
		switch_to_blog( (int) $woocommerce_pos_site_id );
		delete_option( 'wcpos_anon_id' );
		restore_current_blog();
	}
} else {
	delete_option( 'wcpos_anon_id' );
}
