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

	/** Count an attempt for an account and peer before its password is checked.
	 *
	 * Returns false when the account is locked or the attempt would pass the limit; the caller
	 * then refuses without checking the password. The attempt is counted in one atomic statement
	 * before the check, so overlapping requests cannot all pass on the same count (CWE-307).
	 *
	 * @param string $username The identifier as typed.
	 */
	public static function reserve_attempt( string $username ): bool {
		if ( empty( $username ) ) {
			return true;
		}
		$username_key = self::key( $username );
		if ( false !== get_transient( 'wcpos_auth_lock_' . $username_key ) ) {
			return false;
		}
		return self::reserve_bucket_attempt( $username_key, self::MAX_FAILED_ATTEMPTS, self::LOCKOUT_DURATION );
	}

	/** Count an attempt in a bucket before its password is checked, in one atomic statement.
	 *
	 * Returns false when the count would pass the limit, or when the attempt could not be counted
	 * (fail closed); the caller then refuses without checking the password. Shared by the account
	 * lockout here and the login page's per-peer rate limit.
	 *
	 * @param string $bucket An md5 bucket key; the row is wcpos_auth_attempts_<bucket>.
	 * @param int    $limit  Attempts allowed in the window.
	 * @param int    $window Seconds a failure keeps counting.
	 */
	public static function reserve_bucket_attempt( string $bucket, int $limit, int $window ): bool {
		global $wpdb;
		$now   = time();
		$fresh = '1|' . ( $now + $window );
		// The row is count|expires-at, so one statement restarts an expired window or adds one. Every
		// attempt adds one, even past the limit, and a refund only ever takes back the refunding
		// request's own one, so a read-back never admits an attempt whose increment was skipped. A
		// failure sets the window's expiry, as the old transient's TTL did; an attempt within the
		// limit only holds the window open while it is in flight, and one past it does not.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_option() reads then writes.
		$written  = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = IF(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d, %s, CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', IF(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) >= %d, SUBSTRING_INDEX(option_value, '|', -1), GREATEST(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED), %d))))", self::ROW_PREFIX . $bucket, $fresh, $now, $fresh, $limit, $now + self::IN_FLIGHT_SECONDS ) );
		$attempts = false === $written ? null : self::attempts( $bucket );
		if ( null === $attempts ) {
			// Fail closed: an attempt that could not be counted is not checked.
			Logger::error( 'WCPOS Auth: could not count a credential attempt; refusing it.' );
			return false;
		}
		if ( $attempts > $limit ) {
			// A refused attempt gives its own one back, so it holds no slot once the overlap ends.
			self::release_bucket_attempt( $bucket );
			return false;
		}
		return true;
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
		if ( $attempts >= self::MAX_FAILED_ATTEMPTS ) {
			set_transient( 'wcpos_auth_lock_' . $username_key, time(), self::LOCKOUT_DURATION );
			Logger::log( \sprintf( 'WCPOS Auth: Account locked - username: %s, IP: %s, attempts: %d', $username, self::client_ip(), $attempts ) );
		}
	}

	/** Give back a counted attempt whose password was right but which was refused for another
	 * reason (no capability, approving oneself), so it does not count as a failure.
	 *
	 * @param string $username The identifier that authenticated.
	 */
	public static function release_attempt( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}
		self::release_bucket_attempt( self::key( $username ) );
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
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', %d) WHERE option_name = %s", time() + $window, self::ROW_PREFIX . $bucket ) );
		return (int) self::attempts( $bucket );
	}

	/** Give back an attempt counted by reserve_bucket_attempt() that is not a failure.
	 *
	 * @param string $bucket The bucket key.
	 */
	public static function release_bucket_attempt( string $bucket ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_option() reads then writes.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) - 1, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) > 0", self::ROW_PREFIX . $bucket ) );
	}

	/** Whether a bucket's current window has reached its limit, read without counting.
	 *
	 * @param string $bucket The bucket key.
	 * @param int    $limit  Attempts allowed in the window.
	 */
	public static function bucket_is_full( string $bucket, int $limit ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counter row is never cached.
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ROW_PREFIX . $bucket ) );
		list( $count, $expires ) = array_map( 'intval', explode( '|', $value ) ) + array( 0, 0 );
		return $expires >= time() && $count >= $limit;
	}

	/** Clear the attempts and lock for an account and peer after a successful check, and drop
	 * any other bucket whose window has ended.
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s OR (option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d)", self::ROW_PREFIX . $username_key, $wpdb->esc_like( self::ROW_PREFIX ) . '%', time() ) );
		delete_transient( 'wcpos_auth_lock_' . $username_key );
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
	 * account gets its own bucket per peer so guessing unknown names is still counted. Callers
	 * pass the identifier after one sanitize_user(), as wp_authenticate() and the login page
	 * resolve it, so every spelling that reaches one account reaches one bucket.
	 *
	 * @param string $username The identifier after sanitize_user().
	 */
	private static function key( string $username ): string {
		$user    = get_user_by( 'login', $username );
		$user    = $user ? $user : get_user_by( 'email', $username );
		$subject = $user ? 'user:' . $user->ID : 'input:' . strtolower( trim( $username ) );
		return md5( $subject . '|' . self::client_ip() );
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
