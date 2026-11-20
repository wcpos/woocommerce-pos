<?php
/**
 * Sync store table repair.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Logger;

/**
 * Re-creates sync store tables that disappeared after the store was latched.
 *
 * Once wcpos_sync_schema_version is current, version_check() never re-runs the sync install,
 * so a table dropped later (a cleanup plugin, a partial restore) left every gated sync request
 * answering 503 for good. Only the missing tables' own installers run: re-creating the journal
 * starts a new epoch (every till resyncs once); the digest and mutations tables do not. The
 * latch is never written, so a host whose CREATE keeps failing cannot flap it.
 */
final class Store_Repair {
	/** Transient marking a repair attempt, so a host whose CREATE keeps failing retries once per interval. */
	public const ATTEMPT_TRANSIENT = 'wcpos_sync_store_repair_attempt';

	/** Seconds between attempts: one per 30 rejected polls per till; at most 96 a day on a failing host. */
	public const INTERVAL = 900;

	/**
	 * Repair a latched sync store that lost a table. Called only from the health gate's failure branch.
	 *
	 * @return bool True when the store is healthy afterwards and the request may be served.
	 */
	public static function repaired(): bool {
		// An unlatched site is still installing, and version_check() retries it there.
		if ( Api::SCHEMA_VERSION !== get_option( Api::SCHEMA_OPTION ) ) {
			return false;
		}
		if ( false !== get_transient( self::ATTEMPT_TRANSIENT ) ) {
			return Health::is_healthy();
		}
		if ( ! Activator::acquire_db_upgrade_lock() ) {
			return false;
		}
		try {
			// Without a persistent object cache, the earlier miss is in notoptions; clear it to see another request's writes.
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( '_transient_' . self::ATTEMPT_TRANSIENT, 'options' );
			wp_cache_delete( '_transient_timeout_' . self::ATTEMPT_TRANSIENT, 'options' );
			wp_cache_delete( Api::SCHEMA_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			// Activation runs the install without this lock and can delete the latch.
			// The caches were just cleared, so this re-read can differ from the first; PHPStan cannot see that.
			if ( Api::SCHEMA_VERSION !== get_option( Api::SCHEMA_OPTION ) ) { // @phpstan-ignore notIdentical.alwaysFalse
				return false;
			}
			if ( false !== get_transient( self::ATTEMPT_TRANSIENT ) ) {
				return Health::is_healthy();
			}
			$missing = Health::missing_tables();
			if ( empty( $missing ) ) {
				return true;
			}
			// Setting it first means a fatal error or timeout mid-CREATE still counts as an attempt.
			set_transient( self::ATTEMPT_TRANSIENT, time(), self::INTERVAL );
			global $wpdb;
			if ( in_array( $wpdb->prefix . Health::SYNC_JOURNAL_TABLE, $missing, true ) ) {
				( new Sync_Journal() )->install();
			}
			if ( in_array( $wpdb->prefix . Health::STORED_DIGEST_TABLE, $missing, true ) ) {
				( new Integrity_Digest() )->install();
			}
			if ( in_array( $wpdb->prefix . Health::MUTATIONS_TABLE, $missing, true ) ) {
				( new Mutation_Store() )->install();
			}
			$still_missing = Health::missing_tables();
			$short_name    = static function ( string $table ) use ( $wpdb ): string {
				return substr( $table, strlen( $wpdb->prefix ) );
			};
			$context       = array(
				'missing'       => array_map( $short_name, $missing ),
				'still_missing' => array_map( $short_name, $still_missing ),
			);
			// A journal re-create resyncs every till, so it must reach error reporting.
			if ( in_array( $wpdb->prefix . Health::SYNC_JOURNAL_TABLE, $missing, true ) ) {
				Logger::error( 'Sync store tables were missing; repair attempted', $context );
			} else {
				Logger::warning( 'Sync store tables were missing; repair attempted', $context );
			}
			return empty( $still_missing );
		} finally {
			Activator::release_db_upgrade_lock();
		}
	}
}
