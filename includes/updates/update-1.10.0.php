<?php
/**
 * Update to 1.10.0
 *
 * Promote legacy multisite per-blog user uuids to the network-wide key.
 *
 * Before user identity consolidated in Sync\Pos_Uuid (#1450), the cashier
 * endpoint minted `_woocommerce_pos_uuid_{blog_id}` user meta on multisite
 * while every other reader used the plain `_woocommerce_pos_uuid` key —
 * forking one user into two POS identities. #1450 adds lazy read-time
 * adoption of the current blog's legacy value; this migration performs the
 * promotion deterministically for ALL users in one pass and deletes the
 * legacy rows. The lazy adoption stays as a safety net for rows this run
 * cannot see. MERGE ORDER MATTERS: this migration must not ship without
 * #1450 — the pre-#1450 cashier endpoint re-mints a per-blog uuid the
 * moment its row disappears, re-opening the fork this migration closes.
 *
 * Promotion policy (deliberately close to, but not identical to,
 * Pos_Uuid::adopt_legacy_multisite_user_uuid):
 * - Any existing VALID plain uuid wins — it is what the /customers endpoint
 *   has already served to clients. All plain rows are checked, matching
 *   Pos_Uuid::read_valid_uuid_from_meta; invalid-only values count as absent.
 * - Otherwise blog ids are tried in ASCENDING order and the first valid
 *   legacy value not owned by another live user's plain key is promoted.
 *   A collision (cloned/imported row) falls through to the NEXT blog's
 *   value — preserving some legacy identity beats minting fresh. The lazy
 *   path instead adopts the CURRENT blog's value; whichever writes first
 *   wins and the other becomes a no-op, so the two policies cannot flap.
 * - Legacy rows are deleted only when the user ends the pass with a valid
 *   plain uuid or had no promotable value at all. A failed meta write
 *   leaves the user's legacy rows in place for the lazy path, because the
 *   ladder is one-shot and will not retry. NOTE: deletion trades rollback
 *   convenience for a clean meta table — a later downgrade to 1.9.x
 *   re-mints per-blog cashier uuids instead of finding these rows.
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
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries are prepared before execution.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names and an array_fill-built %s placeholder list are interpolated.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Update script with file-scoped variables.

global $wpdb;

// The trailing separator is part of the escaped literal, so the plain
// `_woocommerce_pos_uuid` key itself cannot match. The users JOIN skips
// orphaned rows of deleted users — nothing reads them and promoting them
// would recreate garbage. Non-numeric suffixes are filtered out below.
$wcpos_legacy_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.user_id, m.meta_key, m.meta_value
		FROM {$wpdb->usermeta} m
		INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
		WHERE m.meta_key LIKE %s
		ORDER BY m.user_id ASC, m.umeta_id ASC",
		$wpdb->esc_like( Pos_Uuid::META_KEY . '_' ) . '%'
	)
);

if ( '' !== $wpdb->last_error ) {
	if ( \function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->error(
			'WCPOS 1.10.0 migration: legacy uuid discovery query failed, no rows were migrated: ' . $wpdb->last_error,
			array( 'source' => 'woocommerce-pos' )
		);
	}

	return;
}

$wcpos_legacy_key_pattern = '/^' . preg_quote( Pos_Uuid::META_KEY, '/' ) . '_(\d+)$/';

// Group as [user_id][blog_id] => value. Duplicate rows for one key keep the
// FIRST (lowest umeta_id) value unless a later row is the only valid uuid.
$wcpos_legacy_by_user = array();
foreach ( (array) $wcpos_legacy_rows as $wcpos_row ) {
	if ( ! preg_match( $wcpos_legacy_key_pattern, (string) $wcpos_row->meta_key, $wcpos_matches ) ) {
		continue;
	}
	$wcpos_user_id = (int) $wcpos_row->user_id;
	$wcpos_blog_id = (int) $wcpos_matches[1];
	$wcpos_value   = (string) $wcpos_row->meta_value;
	if (
		! isset( $wcpos_legacy_by_user[ $wcpos_user_id ][ $wcpos_blog_id ] )
		|| ( ! Pos_Uuid::is_uuid( $wcpos_legacy_by_user[ $wcpos_user_id ][ $wcpos_blog_id ] ) && Pos_Uuid::is_uuid( $wcpos_value ) )
	) {
		$wcpos_legacy_by_user[ $wcpos_user_id ][ $wcpos_blog_id ] = $wcpos_value;
	}
}

// Prefetch which candidate values are already owned as a LIVE user's plain
// key (one chunked query instead of one query per candidate — meta_value is
// unindexed, so per-candidate lookups would range-scan every customer row).
// Deleted users' stale rows are excluded, mirroring uuid_owned_by_other_user.
$wcpos_candidate_values = array();
foreach ( $wcpos_legacy_by_user as $wcpos_blog_values ) {
	foreach ( $wcpos_blog_values as $wcpos_value ) {
		if ( Pos_Uuid::is_uuid( $wcpos_value ) ) {
			$wcpos_candidate_values[ $wcpos_value ] = true;
		}
	}
}

$wcpos_owners_by_value = array();
foreach ( array_chunk( array_keys( $wcpos_candidate_values ), 500 ) as $wcpos_chunk ) {
	$wcpos_placeholders = implode( ',', array_fill( 0, \count( $wcpos_chunk ), '%s' ) );
	$wcpos_owner_rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.meta_value, m.user_id
			FROM {$wpdb->usermeta} m
			INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
			WHERE m.meta_key = %s AND m.meta_value IN ( {$wcpos_placeholders} )",
			array_merge( array( Pos_Uuid::META_KEY ), $wcpos_chunk )
		)
	);
	if ( '' !== $wpdb->last_error ) {
		if ( \function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'WCPOS 1.10.0 migration: uuid ownership prefetch query failed, no rows were migrated: ' . $wpdb->last_error,
				array( 'source' => 'woocommerce-pos' )
			);
		}

		return;
	}
	foreach ( (array) $wcpos_owner_rows as $wcpos_owner_row ) {
		$wcpos_owners_by_value[ strtolower( (string) $wcpos_owner_row->meta_value ) ][] = (int) $wcpos_owner_row->user_id;
	}
}

$wcpos_promoted   = 0;
$wcpos_collisions = 0;
$wcpos_failed     = 0;
$wcpos_cleaned    = 0;

foreach ( $wcpos_legacy_by_user as $wcpos_user_id => $wcpos_blog_values ) {
	// All plain rows, matching Pos_Uuid::read_valid_uuid_from_meta — a valid
	// uuid in ANY row wins even when an invalid duplicate sits in front of it.
	$wcpos_plain_values    = get_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY );
	$wcpos_has_valid_plain = false;
	foreach ( (array) $wcpos_plain_values as $wcpos_plain_value ) {
		if ( Pos_Uuid::is_uuid( $wcpos_plain_value ) ) {
			$wcpos_has_valid_plain = true;

			break;
		}
	}

	$wcpos_write_failed = false;

	if ( ! $wcpos_has_valid_plain ) {
		ksort( $wcpos_blog_values ); // Ascending blog id, numerically.

		foreach ( $wcpos_blog_values as $wcpos_legacy_value ) {
			if ( ! Pos_Uuid::is_uuid( $wcpos_legacy_value ) ) {
				continue;
			}

			// Owned by a DIFFERENT live user (cloned/imported row): fall through
			// to the next blog's value rather than serve a duplicate RxDB key.
			// Users iterate in ascending id order and promotions land in this
			// map as they happen, so shared values converge deterministically.
			$wcpos_collides = false;
			foreach ( $wcpos_owners_by_value[ strtolower( $wcpos_legacy_value ) ] ?? array() as $wcpos_owner_id ) {
				if ( $wcpos_owner_id !== $wcpos_user_id ) {
					$wcpos_collides = true;

					break;
				}
			}
			if ( $wcpos_collides ) {
				++$wcpos_collisions;

				continue;
			}

			$wcpos_owned_value = $wcpos_legacy_value;
			if ( array() === (array) $wcpos_plain_values ) {
				// Unique add: if #1450's lazy adoption raced us and already wrote
				// a plain uuid, the add fails and THAT value stands.
				$wcpos_written = false !== add_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY, $wcpos_legacy_value, true );
			} else {
				// Invalid-only plain rows: overwrite, as the lazy path would.
				$wcpos_written = false !== update_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY, $wcpos_legacy_value );
			}

			if ( ! $wcpos_written ) {
				// Re-read before declaring failure — a concurrent writer winning
				// the unique add is success, just not OUR value.
				wp_cache_delete( $wcpos_user_id, 'user_meta' );
				foreach ( (array) get_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY ) as $wcpos_recheck_value ) {
					if ( Pos_Uuid::is_uuid( $wcpos_recheck_value ) ) {
						$wcpos_written     = true;
						$wcpos_owned_value = $wcpos_recheck_value;

						break;
					}
				}
			}

			if ( $wcpos_written ) {
				++$wcpos_promoted;
				$wcpos_owners_by_value[ strtolower( $wcpos_owned_value ) ][] = $wcpos_user_id;
			} else {
				// Genuine write failure (meta filter veto, DB error). The ladder
				// is one-shot, so keep this user's legacy rows for the lazy path.
				$wcpos_write_failed = true;
			}

			break;
		}
	}

	if ( $wcpos_write_failed ) {
		++$wcpos_failed;

		continue;
	}

	foreach ( array_keys( $wcpos_blog_values ) as $wcpos_blog_id ) {
		delete_user_meta( $wcpos_user_id, Pos_Uuid::META_KEY . '_' . $wcpos_blog_id );
	}
	++$wcpos_cleaned;
}

if ( array() !== $wcpos_legacy_by_user && \function_exists( 'wc_get_logger' ) ) {
	$wcpos_logger  = wc_get_logger();
	$wcpos_message = \sprintf(
		'WCPOS 1.10.0 migration: promoted %d legacy per-blog user uuid(s) to the network key, %d collision fallback(s), cleaned legacy rows for %d user(s), %d write failure(s).',
		$wcpos_promoted,
		$wcpos_collisions,
		$wcpos_cleaned,
		$wcpos_failed
	);
	if ( $wcpos_failed > 0 ) {
		$wcpos_logger->error( $wcpos_message . ' Failed users keep their legacy rows for lazy adoption.', array( 'source' => 'woocommerce-pos' ) );
	} else {
		$wcpos_logger->info( $wcpos_message, array( 'source' => 'woocommerce-pos' ) );
	}
}


/*
 * Seed the activation latches for stores that already exist.
 *
 * `pos_app_opened` and `pos_first_order` (#793 Phase 4) each fire once per
 * site, guarded by an option that only new installs are supposed to be
 * missing. Every store upgrading to 1.10.0 is missing it by definition, so
 * without this their next POS open and next POS sale would both be reported
 * as first-ever — inventing an activation spike out of the existing user base
 * and corrupting the cohorts the metric exists to measure.
 *
 * The open latch is seeded unconditionally: this site predates the latch, so
 * its true first open is unknowable, and an unknown first is better than a
 * wrong one. The order latch is seeded ONLY when the store has already sold
 * through the POS — a store that has never taken a POS sale still has a
 * genuine first sale ahead of it, and that one should be reported.
 */
add_option( Services\Lifecycle_Events::FIRST_OPEN_OPTION, Services\Lifecycle_Events::LATCH_VALUE, '', true );

// EXISTS rather than COUNT: this runs during an upgrade and only the presence
// of any POS order matters. Mirrors Landing_Profile::get_pos_order_count()'s
// HPOS/legacy split.
if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$wcpos_has_pos_order = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_order_operational_data op ON op.order_id = o.id
				WHERE o.type = 'shop_order' AND op.created_via = %s LIMIT 1
			)",
			'woocommerce-pos'
		)
	);
} else {
	$wcpos_has_pos_order = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT EXISTS (
				SELECT 1 FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order' AND pm.meta_key = '_created_via' AND pm.meta_value = %s LIMIT 1
			)",
			'woocommerce-pos'
		)
	);
}

if ( $wcpos_has_pos_order > 0 ) {
	add_option( Services\Lifecycle_Events::FIRST_ORDER_OPTION, Services\Lifecycle_Events::LATCH_VALUE, '', true );
}

// phpcs:enable
