<?php
/**
 * Account lockout for typed credentials, per account and peer.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * One failure counter for every place WCPOS checks a typed password: the POS login page and
 * the manager-approval routes. A failure on any of them counts toward one lockout, and a
 * locked account is refused on all of them. The *_bucket_* methods are the atomic counter under
 * it, also used by the login page's per-peer rate limit.
 */
final class Credential_Lockout {
	/** Max failed attempts per account and peer. */
	private const MAX_FAILED_ATTEMPTS = 5;

	/** Lockout duration in seconds (15 minutes). */
	private const LOCKOUT_DURATION = 900;

	/** Seconds a counted attempt keeps its window open while its password is checked; far longer
	 * than any check, so a window cannot end and restart the count under an attempt in flight. */
	private const IN_FLIGHT_SECONDS = 60;

	/** Option name prefix of the per-bucket attempt counter, read and written only by raw SQL. */
	private const ROW_PREFIX = 'wcpos_auth_attempts_';

	/** Times an attempt re-reads a row that changed under it before it fails closed; a change
	 * needs another request's write in the same instant, so a few tries are plenty. */
	private const COUNT_TRIES = 5;

	/** Most live unknown-name rows a site keeps before a new unknown name is refused. A store's own
	 * staff rarely mistype names, so these rows stay few; each address adds at most 10 per window
	 * (the per-address limit), so reaching 5000 takes about 500 addresses inside 15 minutes. The
	 * manager-approval and till-close routes have no per-address limit, so one signed-in POS user can
	 * reach the ceiling alone; at the ceiling only new unknown names are refused there. Peer and
	 * real-account rows are not capped, because capping them would let an attacker lock real
	 * managers out. */
	private const MAX_LIVE_ROWS = 5000;

	/** Starts the bucket of an identifier that matches no account, so the ceiling counts those rows
	 * through the option_name index; an md5 is hex, so no other bucket starts with it. */
	private const UNKNOWN_PREFIX = 'n';

	/** Most expired counter rows one attempt deletes, so no single request does a large delete. It
	 * is larger than the rows one attempt can add, so expired rows never pile up. */
	private const SWEEP_BATCH = 100;

	/** Most counter rows one sweep reads, so its cost does not grow with the table. Its run starts at
	 * a random point and wraps, so every row is reached over time, and a table of 500 rows or fewer
	 * is swept whole. */
	private const SWEEP_SCAN = 500;

	/** Count an attempt for an account and peer before its password is checked.
	 *
	 * Returns null when the account is locked or full; the caller then refuses without checking
	 * the password. Otherwise it returns the attempt's generation for release_attempt(), or '' for
	 * an empty identifier, which counts nothing.
	 *
	 * @param string $username The identifier as typed.
	 */
	public static function reserve_attempt( string $username ): ?string {
		if ( empty( $username ) ) {
			return '';
		}
		$username_key = self::key( $username );
		if ( false !== get_transient( 'wcpos_auth_lock_' . $username_key ) ) {
			return null;
		}
		// Only an unknown name's bucket is capped; a real account always locks and counts.
		$capped = 0 === strpos( $username_key, self::UNKNOWN_PREFIX );
		return self::reserve_bucket_attempt( $username_key, self::MAX_FAILED_ATTEMPTS, self::LOCKOUT_DURATION, $capped );
	}

	/** Count an attempt in a bucket before its password is checked (CWE-307).
	 *
	 * Returns the generation the attempt was counted in, which release_bucket_attempt() needs, or
	 * null when the bucket is full or the attempt could not be counted (fail closed); the caller
	 * then refuses without checking the password. Shared by the account lockout here and the login
	 * page's per-peer rate limit.
	 *
	 * @param string $bucket An md5 bucket key; the row is wcpos_auth_attempts_<bucket>.
	 * @param int    $limit  Attempts allowed in the window.
	 * @param int    $window Seconds a failure keeps counting.
	 * @param bool   $capped True only for an unknown name's bucket: a new live row for it is refused
	 *                       (fail closed) once MAX_LIVE_ROWS unknown-name rows are live, since staff
	 *                       rarely mistype names. Peer and real-account buckets are never capped,
	 *                       because capping them would let an attacker lock real managers out.
	 */
	public static function reserve_bucket_attempt( string $bucket, int $limit, int $window, bool $capped = false ): ?string {
		global $wpdb;
		$name = self::ROW_PREFIX . $bucket;
		$live = null;
		// The row is count|expires-at|generation. Each try reads the row and writes the next value
		// only over the exact value it read, so the attempt knows its own position and the generation
		// it was counted in, and overlapping attempts cannot pass on one count. A full bucket is
		// refused without a write, so a refusal holds no slot and moves no window. A failure sets the
		// expiry, as the old transient's TTL did; an admitted attempt holds the window open while it
		// is in flight, so a window cannot end and restart the count under it.
		for ( $try = 0; $try < self::COUNT_TRIES; $try++ ) {
			$now  = time();
			// get_row(), not get_var(): get_var() reads an empty stored value as no row.
			$found = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counter row is never cached.
			$seen  = null === $found ? null : (string) $found[0];
			$row   = self::live_row( $seen, $now );
			if ( null !== $row && $row[0] >= $limit ) {
				return null;
			}
			// A new live window past the ceiling is refused without a write, as is one whose count
			// failed; the count is read once per call.
			if ( null === $row && $capped ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counter rows are never cached.
				$live = $live ?? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, '|', 2), '|', -1) AS UNSIGNED) >= %d LIMIT %d) AS live", $wpdb->esc_like( self::ROW_PREFIX . self::UNKNOWN_PREFIX ) . '%', $now, self::MAX_LIVE_ROWS ) );
				if ( null === $live || (int) $live >= self::MAX_LIVE_ROWS ) {
					return null;
				}
			}
			// An ended window, or a row in an older format, starts a new generation; wp_rand() is
			// cryptographic, so no seeding elsewhere can make a generation come round again.
			$next = null === $row ? array( 1, $now + $window, \sprintf( '%08x%08x', wp_rand(), wp_rand() ) ) : array( $row[0] + 1, max( $row[1], $now + self::IN_FLIGHT_SECONDS ), $row[2] );
			// Only an attempt that writes sweeps, once; a sweep of this ended row makes the UPDATE retry as an INSERT.
			if ( 0 === $try ) {
				self::sweep();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_option() reads then writes.
			$written = null === $seen ? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, implode( '|', $next ) ) ) : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", implode( '|', $next ), $name, $seen ) );
			if ( false === $written ) {
				break;
			}
			if ( 1 === $written ) {
				return $next[2];
			}
		}
		// Fail closed: an attempt that could not be counted is not checked.
		Logger::error( 'WCPOS Auth: could not count a credential attempt; refusing it.' );
		return null;
	}

	/** Restart the 15-minute window from this failure and lock at the limit; the attempt that
	 * failed was already counted by reserve_attempt().
	 *
	 * @param string $username The identifier that failed.
	 */
	public static function record_failure( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}
		$username_key = self::key( $username );
		$attempts     = self::record_bucket_failure( $username_key, self::LOCKOUT_DURATION );
		// An unknown name gets no lock: its full row already refuses for the same window, and a lock
		// would leave two option rows that only the daily purge deletes.
		if ( $attempts >= self::MAX_FAILED_ATTEMPTS && 0 !== strpos( $username_key, self::UNKNOWN_PREFIX ) ) {
			set_transient( 'wcpos_auth_lock_' . $username_key, time(), self::LOCKOUT_DURATION );
			// A lock stands only over a full count. A give-back between the read above and the
			// write lifted nothing, so read again after writing; one that lands later lifts the
			// lock itself. Lifting a lock a newer failure needed is harmless: the full bucket
			// refuses on its own.
			if ( (int) self::attempts( $username_key ) < self::MAX_FAILED_ATTEMPTS ) {
				delete_transient( 'wcpos_auth_lock_' . $username_key );
				return;
			}
			Logger::log( \sprintf( 'WCPOS Auth: Account locked - username: %s, IP: %s, attempts: %d', $username, self::client_ip(), $attempts ) );
		}
	}

	/** Give back a counted attempt whose password was right but which was refused for another
	 * reason (no capability, approving oneself), so it does not count as a failure.
	 *
	 * A lock set while this attempt held a slot counted it as a failure, so a give-back that
	 * takes effect lifts the lock. The count is then under the limit by construction; if newer
	 * failures fill it again, the full bucket refuses on its own until its window ends.
	 *
	 * @param string $username   The identifier that authenticated.
	 * @param string $generation What reserve_attempt() returned for this attempt.
	 */
	public static function release_attempt( string $username, string $generation ): void {
		if ( empty( $username ) ) {
			return;
		}
		$username_key = self::key( $username );
		if ( self::release_bucket_attempt( $username_key, $generation ) ) {
			delete_transient( 'wcpos_auth_lock_' . $username_key );
		}
	}

	/** Restart a bucket's window from a failure counted by reserve_bucket_attempt().
	 *
	 * @param string $bucket The bucket key.
	 * @param int    $window Seconds the failure keeps counting.
	 * @return int The attempts the bucket holds.
	 */
	public static function record_bucket_failure( string $bucket, int $window ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_option() reads then writes.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', %d, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name = %s", time() + $window, self::ROW_PREFIX . $bucket ) );
		return (int) self::attempts( $bucket );
	}

	/** Give back an attempt counted by reserve_bucket_attempt() that is not a failure.
	 *
	 * A give-back only cancels the increment it belongs to: the statement matches the row only
	 * while it holds the attempt's generation, and an ended window or a row recreated after
	 * clear() has a new one, so a late give-back changes nothing.
	 *
	 * @param string $bucket     The bucket key.
	 * @param string $generation What reserve_bucket_attempt() returned for this attempt.
	 * @return bool Whether the give-back took effect.
	 */
	public static function release_bucket_attempt( string $bucket, string $generation ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_option() reads then writes.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) - 1, '|', SUBSTRING_INDEX(option_value, '|', -2)) WHERE option_name = %s AND SUBSTRING_INDEX(option_value, '|', -1) = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) > 0", self::ROW_PREFIX . $bucket, $generation ) );
	}

	/** Whether a bucket's current window has reached its limit, read without counting.
	 *
	 * @param string $bucket The bucket key.
	 * @param int    $limit  Attempts allowed in the window.
	 */
	public static function bucket_is_full( string $bucket, int $limit ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counter row is never cached.
		$row = self::live_row( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ROW_PREFIX . $bucket ) ), time() );
		return null !== $row && $row[0] >= $limit;
	}

	/** A counter row's count, expiry and generation while its window is open; null for no row,
	 * an ended window, or a value in any other format (rows written before generations included).
	 *
	 * @param string|null $value The stored option_value.
	 * @param int         $now   The current time.
	 * @return array{0: int, 1: int, 2: string}|null
	 */
	private static function live_row( ?string $value, int $now ): ?array {
		$parts = explode( '|', (string) $value );
		if ( 3 !== \count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || '' === $parts[2] || (int) $parts[1] < $now ) {
			return null;
		}
		return array( (int) $parts[0], (int) $parts[1], $parts[2] );
	}

	/** Clear the attempts and lock for an account and peer after a successful check, and drop
	 * one batch of other buckets whose window has ended.
	 *
	 * @param string $username The identifier to clear.
	 */
	public static function clear( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}
		global $wpdb;
		$username_key = self::key( $username );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rows written by reserve_attempt(), never through the options API.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::ROW_PREFIX . $username_key ) );
		self::sweep();
		delete_transient( 'wcpos_auth_lock_' . $username_key );
	}

	/** Delete up to SWEEP_BATCH counter rows whose window has ended, picked from a run of at most
	 * SWEEP_SCAN rows in name order that starts at a random name and wraps round. A row read with no
	 * expiry counts as expired; the DELETE repeats the expiry condition and decides, so a row an
	 * attempt revived between the statements stays. A failed or empty read sweeps nothing. */
	private static function sweep(): void {
		global $wpdb;
		$now   = time();
		$start = self::ROW_PREFIX . ( 0 === wp_rand( 0, 1 ) ? '' : self::UNKNOWN_PREFIX ) . md5( (string) wp_rand() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name >= %s ORDER BY option_name LIMIT %d", $wpdb->esc_like( self::ROW_PREFIX ) . '%', $start, self::SWEEP_SCAN ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rows written by reserve_bucket_attempt(), never through the options API.
		if ( \count( $rows ) < self::SWEEP_SCAN ) {
			$rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s ORDER BY option_name LIMIT %d", $wpdb->esc_like( self::ROW_PREFIX ) . '%', $start, self::SWEEP_SCAN - \count( $rows ) ), ARRAY_N ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
		}
		$names = array_slice( array_keys( array_filter( array_column( $rows, 1, 0 ), static fn( $value ) => (int) ( explode( '|', (string) $value )[1] ?? 0 ) < $now ) ), 0, self::SWEEP_BATCH );
		if ( empty( $names ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rows written by reserve_bucket_attempt(), never through the options API.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ', ', array_fill( 0, \count( $names ), '%s' ) ) . ") AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, '|', 2), '|', -1) AS UNSIGNED) < %d", array_merge( $names, array( $now ) ) ) );
	}

	/** The server-established connecting peer address.
	 *
	 * Forwarded headers are caller-controlled unless the host rewrites them, letting a caller
	 * choose its own bucket. A host behind a trusted proxy normalises REMOTE_ADDR itself
	 * (mod_remoteip / real_ip), which is the only trustworthy place for that policy.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/** Lockout bucket for an identifier as typed by this peer.
	 *
	 * The bucket is the canonical account (login and email aliases share it) joined to the
	 * connecting peer, so a stranger's failures never lock the cashier out from their own till,
	 * and rotating the typed alias does not buy a second budget. An identifier that matches no
	 * account gets its own bucket per peer, named with UNKNOWN_PREFIX, so guessing unknown names
	 * is still counted. Callers
	 * pass the identifier after one sanitize_user(), as wp_authenticate() and the login page
	 * resolve it, so every spelling that reaches one account reaches one bucket.
	 *
	 * @param string $username The identifier after sanitize_user().
	 */
	private static function key( string $username ): string {
		$user    = get_user_by( 'login', $username );
		$user    = $user ? $user : get_user_by( 'email', $username );
		$subject = $user ? 'user:' . $user->ID : 'input:' . strtolower( trim( $username ) );
		return ( $user ? '' : self::UNKNOWN_PREFIX ) . md5( $subject . '|' . self::client_ip() );
	}

	/** Attempts counted in a bucket's row, read past the options cache; null when the read fails.
	 *
	 * @param string $username_key The bucket from key().
	 */
	private static function attempts( string $username_key ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counter row is never cached.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ROW_PREFIX . $username_key ) );
		return null === $value ? null : (int) $value;
	}
}
