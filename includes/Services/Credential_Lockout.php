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
 * locked account is refused on all of them.
 */
final class Credential_Lockout {
	/** Max failed attempts per account and peer. */
	private const MAX_FAILED_ATTEMPTS = 5;

	/** Lockout duration in seconds (15 minutes). */
	private const LOCKOUT_DURATION = 900;

	/** Whether an identifier is locked out for this peer.
	 *
	 * @param string $username The identifier as typed.
	 */
	public static function is_locked( string $username ): bool {
		return ! empty( $username ) && false !== get_transient( 'wcpos_auth_lock_' . self::key( $username ) );
	}

	/** Record a failed attempt for an account and peer, locking at the limit.
	 *
	 * @param string $username The identifier that failed.
	 */
	public static function record_failure( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}
		$username_key = self::key( $username );
		$attempts     = (int) get_transient( 'wcpos_auth_fail_' . $username_key ) + 1;
		set_transient( 'wcpos_auth_fail_' . $username_key, $attempts, self::LOCKOUT_DURATION );
		if ( $attempts >= self::MAX_FAILED_ATTEMPTS ) {
			set_transient( 'wcpos_auth_lock_' . $username_key, time(), self::LOCKOUT_DURATION );
			Logger::log( \sprintf( 'WCPOS Auth: Account locked - username: %s, IP: %s, attempts: %d', $username, self::client_ip(), $attempts ) );
		}
	}

	/** Clear failed attempts for an account and peer after a successful check.
	 *
	 * @param string $username The identifier to clear.
	 */
	public static function clear( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}
		$username_key = self::key( $username );
		delete_transient( 'wcpos_auth_fail_' . $username_key );
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
}
