<?php
/**
 * Update to 1.8.13.
 *
 * Safely remove exact duplicate postmeta rows from POS-touched products and
 * variations. This repair keeps one row per exact
 * (post_id, meta_key, meta_value) tuple.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Services\Meta_Data_Repair;

$woocommerce_pos_meta_repair_result = Meta_Data_Repair::remove_exact_duplicate_postmeta_rows(
	array(
		'dry_run'     => false,
		'batch_size'  => 500,
		'max_batches' => 400,
	)
);

if ( \function_exists( 'wc_get_logger' ) ) {
	$wcpos_logger = wc_get_logger(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.

	$wcpos_logger->info( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- update script file scope.
		sprintf(
			'WCPOS 1.8.13 migration: processed %1$d duplicate groups, deleted %2$d rows (errors: %3$d, batch_limit_hit: %4$s).',
			(int) $woocommerce_pos_meta_repair_result['groups_processed'],
			(int) $woocommerce_pos_meta_repair_result['rows_deleted'],
			(int) $woocommerce_pos_meta_repair_result['errors'],
			$woocommerce_pos_meta_repair_result['hit_batch_limit'] ? 'yes' : 'no'
		),
		array( 'source' => 'woocommerce-pos' )
	);
}
