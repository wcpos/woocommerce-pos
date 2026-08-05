<?php
/**
 * Update to 1.10.0
 *
 * Promote legacy multisite per-blog user uuids to the network-wide key.
 *
 * Before user identity consolidated in Sync\Pos_Uuid (#1450), the cashier
 * endpoint minted `_woocommerce_pos_uuid_{blog_id}` user meta on multisite
 * while every other reader used the plain `_woocommerce_pos_uuid` key —
 * forking one user into two POS identities. Pos_Uuid now lazily adopts the
 * current blog's legacy value at read time; this migration performs the same
 * promotion deterministically for ALL users in one pass and deletes the
 * legacy rows. The lazy adoption remains as a safety net for rows created
 * between this run and the #1450 code going live.
 *
 * Rules (mirroring Pos_Uuid::adopt_legacy_multisite_user_uuid):
 * - An existing VALID plain uuid always wins — it is what the /customers
 *   endpoint has already served to clients; an invalid value counts as absent.
 * - Otherwise the lowest blog id's valid legacy value is promoted, unless that
 *   value is already owned by another user's plain key (a cloned/imported
 *   row) — then nothing is promoted and Pos_Uuid mints a fresh uuid on the
 *   next read. Users are processed in ascending id order so shared legacy
 *   values converge deterministically.
 * - All `_woocommerce_pos_uuid_{blog_id}` rows are deleted afterwards.
 *
 * usermeta is a single network-global table and the legacy key encodes the
 * blog id, so one run sweeps the whole network — no switch_to_blog() needed.
 * Re-runs (the update ladder fires per blog on multisite) find no legacy rows
 * and are no-ops.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Update script with file-scoped variables.

global $wpdb;

// Escaped underscores so LIKE matches literally; the trailing `_%` excludes the
// plain `_woocommerce_pos_uuid` key itself. Non-numeric suffixes are filtered
// out below — only `_woocommerce_pos_uuid_{digits}` rows are ours.
$wcpos_legacy_rows = $wpdb->get_results(
	"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
	WHERE meta_key LIKE '\_woocommerce\_pos\_uuid\_%'
	ORDER BY user_id ASC"
);

$wcpos_legacy_by_user = array();
foreach ( (array) $wcpos_legacy_rows as $wcpos_row ) {
	if ( ! preg_match( '/^_woocommerce_pos_uuid_(\d+)$/', (string) $wcpos_row->meta_key, $wcpos_matches ) ) {
		continue;
	}
	$wcpos_legacy_by_user[ (int) $wcpos_row->user_id ][ (int) $wcpos_matches[1] ] = (string) $wcpos_row->meta_value;
}

$wcpos_promoted = 0;
$wcpos_skipped  = 0;
$wcpos_deleted  = 0;

foreach ( $wcpos_legacy_by_user as $wcpos_user_id => $wcpos_blog_values ) {
	$wcpos_plain = get_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY, true );

	if ( ! Pos_Uuid::is_uuid( $wcpos_plain ) ) {
		ksort( $wcpos_blog_values ); // Lowest blog id wins, numerically.

		foreach ( $wcpos_blog_values as $wcpos_legacy_value ) {
			if ( ! Pos_Uuid::is_uuid( $wcpos_legacy_value ) ) {
				continue;
			}

			// Direct query so promotions from earlier users in this same run are
			// visible: two users sharing a cloned legacy value converge to the
			// lower user id owning it and the higher one skipping.
			$wcpos_owned_by_other = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id <> %d",
					Pos_Uuid::META_KEY,
					$wcpos_legacy_value,
					$wcpos_user_id
				)
			) > 0;

			if ( $wcpos_owned_by_other ) {
				++$wcpos_skipped;

				continue;
			}

			update_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY, $wcpos_legacy_value );
			++$wcpos_promoted;

			break;
		}
	}

	foreach ( array_keys( $wcpos_blog_values ) as $wcpos_blog_id ) {
		if ( delete_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY . '_' . $wcpos_blog_id ) ) {
			++$wcpos_deleted;
		}
	}
}

if ( array() !== $wcpos_legacy_by_user && \function_exists( 'wc_get_logger' ) ) {
	wc_get_logger()->info(
		\sprintf(
			'WCPOS 1.10.0 migration: promoted %d legacy per-blog user uuid(s) to the network key, skipped %d collision(s), deleted legacy rows for %d key(s).',
			$wcpos_promoted,
			$wcpos_skipped,
			$wcpos_deleted
		),
		array( 'source' => 'woocommerce-pos' )
	);
}

// phpcs:enable
