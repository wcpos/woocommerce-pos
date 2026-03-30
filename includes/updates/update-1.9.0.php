<?php
/**
 * Update to 1.9.0.
 *
 * Clean up bloated woocommerce-pos log files caused by i18n translation
 * download spam (thundering herd race condition on missing locales).
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

$wcpos_log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.

if ( ! is_dir( $wcpos_log_dir ) ) {
	return;
}

$wcpos_log_files = glob( $wcpos_log_dir . 'woocommerce-pos-*.log' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.

if ( empty( $wcpos_log_files ) ) {
	return;
}

$wcpos_deleted_count = 0; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.
foreach ( $wcpos_log_files as $wcpos_log_file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.
	if ( wp_delete_file( $wcpos_log_file ) || ! file_exists( $wcpos_log_file ) ) {
		++$wcpos_deleted_count;
	}
}

if ( \function_exists( 'wc_get_logger' ) && $wcpos_deleted_count > 0 ) {
	$wcpos_update_logger = wc_get_logger(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.
	$wcpos_update_logger->info(
		sprintf( 'WCPOS 1.9.0 migration: cleaned up %d POS log file(s) from i18n spam.', $wcpos_deleted_count ),
		array( 'source' => 'woocommerce-pos' )
	);
}
