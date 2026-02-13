<?php
/**
 * Update to 1.8.12
 *
 * Remove duplicate postmeta rows on POS-touched posts.
 *
 * Since v1.4.0, the REST API response hooks called save_meta_data() on every
 * read request. On non-HPOS sites this triggered wp_update_post(), which fired
 * save_post — giving third-party plugins (Jetpack, Astra, Xero, etc.) an
 * opportunity to add duplicate meta rows via add_post_meta(). Over 2+ years
 * this accumulated thousands of junk rows per post on some stores.
 *
 * This migration keeps the most recent row (highest meta_id) for each
 * (post_id, meta_key) pair that has more than 20 identical entries, and
 * deletes the rest. Scoped to posts with _woocommerce_pos_uuid meta.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Update script with file-scoped variables.

global $wpdb;

$wcpos_deleted = $wpdb->query(
	"DELETE pm FROM {$wpdb->postmeta} pm
	INNER JOIN (
		SELECT post_id, meta_key, MAX(meta_id) AS keep_id
		FROM {$wpdb->postmeta}
		WHERE post_id IN (
			SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_woocommerce_pos_uuid'
		)
		GROUP BY post_id, meta_key
		HAVING COUNT(*) > 20
	) dups ON pm.post_id = dups.post_id
		AND pm.meta_key = dups.meta_key
		AND pm.meta_id != dups.keep_id"
);

if ( \function_exists( 'wc_get_logger' ) ) {
	$wcpos_logger = wc_get_logger();
	if ( false === $wcpos_deleted ) {
		$wcpos_logger->error(
			'WCPOS 1.8.12 migration: database query failed when removing duplicate meta rows.',
			array( 'source' => 'woocommerce-pos' )
		);
	} else {
		$wcpos_logger->info(
			sprintf( 'WCPOS 1.8.12 migration: removed %d duplicate meta rows from POS-touched posts.', (int) $wcpos_deleted ),
			array( 'source' => 'woocommerce-pos' )
		);
	}
}

// phpcs:enable
