<?php
/**
 * Update to 0.4
 * - update license options.
 *
 * @version   0.4
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// update capabilities.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Update script with file-scoped variables.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Using $role as loop variable, not overriding WP global.
$roles = array( 'administrator', 'shop_manager' );
$caps  = array( 'manage_woocommerce_pos', 'access_woocommerce_pos' );
foreach ( $roles as $slug ) {
	$role = get_role( $slug );
	if ( $role ) {
		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
