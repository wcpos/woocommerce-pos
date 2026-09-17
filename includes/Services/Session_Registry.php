<?php
/**
 * Session registry.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use const DAY_IN_SECONDS;
use const MINUTE_IN_SECONDS;

/**
 * Stored sessions and their activity records.
 */
final class Session_Registry {
	/**
	 * User meta key for refresh-token sessions.
	 */
	public const META_KEY = '_woocommerce_pos_refresh_tokens';

	/**
	 * Maximum number of refresh-token sessions retained per user.
	 *
	 * Refresh tokens live for weeks and every entry carries a user agent plus parsed
	 * device info, so without a cap the `_woocommerce_pos_refresh_tokens` row grows until
	 * `get_user_meta()` can no longer unserialize it inside the PHP memory limit.
	 *
	 * This is a ceiling on ACCUMULATED CLUTTER, never a limit on how many devices may be
	 * signed in at once: `evict_oldest_sessions()` only ever removes sessions that have
	 * been idle for SESSION_EVICTION_IDLE_SECONDS, and lets the count exceed this number
	 * rather than log a live device out. Two hundred covers a large merchant's real
	 * devices with room to spare, and 200 entries serialize to roughly a hundred
	 * kilobytes.
	 */
	public const MAX_SESSIONS_PER_USER = 200;

	/**
	 * How long a session must have gone unseen before eviction may remove it.
	 *
	 * The cap alone is not a safe eviction rule. A client that authenticates
	 * programmatically mints sessions far faster than a merchant does, so "the oldest of
	 * N" can be a session created minutes ago and still in use — and evicting it
	 * blacklists its access token, logging a working device out mid-request. That is
	 * exactly what happened on the shared E2E cashier after #1798 shipped a 50-session
	 * cap. A week of silence is a long time for a till: a device seen inside that window
	 * is treated as live and is never a candidate, whatever the count.
	 */
	public const SESSION_EVICTION_IDLE_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * How stale a session's `last_active` may get before an authenticated request rewrites it.
	 *
	 * `last_active` decides what eviction may touch, so it has to reflect USE, not just
	 * token refreshes — before this, only `refresh_access_token()` moved it, and a device
	 * happily working through a 30-minute access token looked idle the whole time. Every
	 * authenticated request now refreshes it, throttled to one write per session per five
	 * minutes so the POS's request volume does not turn into a write per call.
	 */
	private const SESSION_ACTIVITY_REFRESH_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient prefix for the per-session "last seen" record.
	 *
	 * Activity is recorded OUTSIDE the session row on purpose. Writing it into the row
	 * meant every authenticated request did a read-modify-write of the whole
	 * `_woocommerce_pos_refresh_tokens` array, which is neither atomic nor cheap: a
	 * request overlapping a login, logout or revoke for the same user could write back a
	 * stale copy and erase the concurrent change — losing a session that had just been
	 * issued, so the new client worked until its access token expired and was then refused
	 * a refresh. Four parallel E2E shards on one cashier do exactly that. A per-session key
	 * cannot collide with another session's write, and reading it costs no row load at all.
	 */
	private const SESSION_SEEN_TRANSIENT_PREFIX = 'wcpos_session_seen_';

	/**
	 * Byte ceiling on the stored session row before it is discarded UNREAD.
	 *
	 * This is a LAST RESORT for a row no longer safe to load, not a tidy-up threshold —
	 * discarding it signs every one of that user's devices out at once. The bar is set
	 * from measurement rather than caution: a 9,216,730-byte row (17,000 sessions) read
	 * fine under the 128 MB limit that produced the #1776 fatal — `get_user_meta()` cost
	 * ~26 MB to fetch and ~38 MB with the unserialize, and it was the WRITE-BACK, at ~42
	 * MB more, that exhausted the request. Six megabytes therefore sits below anything
	 * measured to be unreadable while still catching a row heading for that fatal. The
	 * first release of this guard used one megabyte, which is comfortably readable and
	 * threw away rows that eviction could simply have trimmed.
	 */
	public const MAX_SESSIONS_ROW_BYTES = 6291456;

	/**
	 * Read the stored session map without guarding or modifying it.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array
	 */
	public function entries( int $user_id ): array {
		$entries = get_user_meta( $user_id, self::META_KEY, true );

		return \is_array( $entries ) ? $entries : array();
	}

	/**
	 * Read one stored session.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti     Refresh token JTI.
	 *
	 * @return array
	 */
	public function entry( int $user_id, string $jti ): array {
		return $this->entries( $user_id )[ $jti ] ?? array();
	}

	/**
	 * Store refresh token JTI for tracking/revocation.
	 *
	 * @param int             $user_id The user ID.
	 * @param string          $jti     The token JTI.
	 * @param int             $expires The expiration timestamp.
	 * @param Session_Context $context Request state the session is recorded against.
	 *
	 * @return array Evicted entries keyed by refresh token JTI.
	 */
	public function record( int $user_id, string $jti, int $expires, Session_Context $context ): array {
		// BEFORE the read: a pre-cap row can be too large to load, and this is the first
		// point in the login flow where WCPOS knows the user id.
		$this->discard_oversized_row( $user_id );

		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) ) {
			$refresh_tokens = array();
		}

		// Clean up expired tokens.
		$refresh_tokens = array_filter(
			$refresh_tokens,
			function ( $token ) {
				return $token['expires'] > time();
			}
		);

		// Capture session metadata.
		$current_time = time();
		$ip_address   = $context->get_ip();
		$user_agent   = $context->get_user_agent();
		$device_info  = $this->parse_user_agent( $user_agent );

		// Check for explicit platform declaration from native apps (passed as a param in the auth request).
		$platform = $context->get_platform();
		$version  = $context->get_version();
		$build    = $context->get_build();

		// Override app_type if platform was explicitly provided by the client.
		if ( \in_array( $platform, array( 'ios', 'android', 'electron', 'web' ), true ) ) {
			$device_info['app_type'] = 'web' === $platform ? 'web' : $platform . '_app';

			// Set appropriate device type based on platform.
			if ( 'ios' === $platform || 'android' === $platform ) {
				$device_info['device_type'] = 'tablet'; // Default to tablet for mobile apps.
			} elseif ( 'electron' === $platform ) {
				$device_info['device_type'] = 'desktop';
			}

			// Use version from param if provided.
			if ( ! empty( $version ) ) {
				$device_info['browser_version'] = $version;
			}

			// Store build number if provided.
			if ( ! empty( $build ) ) {
				$device_info['build'] = $build;
			}

			// Set browser to WooCommerce POS for native apps.
			if ( 'web' !== $platform ) {
				$device_info['browser'] = 'WooCommerce POS';
			}
		}

		// Add new token with metadata.
		$refresh_tokens[ $jti ] = array(
			'expires'     => $expires,
			'created'     => $current_time,
			'last_active' => $current_time,
			'ip_address'  => $ip_address,
			'user_agent'  => $user_agent,
			'device_info' => $device_info,
		);

		// Cap the number of stored sessions so programmatic clients cannot grow the row without bound.
		$evicted = $this->evict_oldest_sessions( $refresh_tokens, $jti );

		update_user_meta( $user_id, self::META_KEY, $refresh_tokens );

		return $evicted;
	}

	/**
	 * Get all active sessions for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array
	 */
	public function list( int $user_id ): array {
		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return array();
		}

		$sessions     = array();
		$current_time = time();

		foreach ( $refresh_tokens as $jti => $token_data ) {
			// Skip expired sessions.
			if ( $token_data['expires'] <= $current_time ) {
				continue;
			}

			$sessions[] = array(
				'jti'         => $jti,
				'created'     => $token_data['created'] ?? $current_time,
				'last_active' => $token_data['last_active'] ?? $token_data['created'] ?? $current_time,
				'expires'     => $token_data['expires'],
				'ip_address'  => $token_data['ip_address'] ?? '',
				'user_agent'  => $token_data['user_agent'] ?? '',
				'device_info' => $token_data['device_info'] ?? array(),
			);
		}

		// Sort by last_active descending (most recent first).
		usort(
			$sessions,
			function ( $a, $b ) {
				return $b['last_active'] - $a['last_active'];
			}
		);

		return $sessions;
	}

	/**
	 * Check if refresh token is still valid (not revoked).
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function is_live( int $user_id, string $jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		return isset( $refresh_tokens[ $jti ] ) && $refresh_tokens[ $jti ]['expires'] > time();
	}

	/**
	 * Refresh a session's `last_active`, at most once every few minutes.
	 *
	 * Called from token validation, so it runs on EVERY authenticated request. The
	 * throttle is what makes that affordable: the value only has to be accurate to within
	 * minutes for a rule that asks whether a session has been unseen for a week, and the
	 * read is already in the user's meta cache by this point.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti     Refresh token JTI (session identifier).
	 */
	public function touch( int $user_id, string $jti ): void {
		if ( 0 === $user_id || '' === $jti ) {
			return;
		}

		$key  = self::SESSION_SEEN_TRANSIENT_PREFIX . $jti;
		$seen = get_transient( $key );

		// The throttle reads the transient, never the session row: this runs on every
		// authenticated request, and the row is the one thing this path must not touch.
		if ( is_numeric( $seen ) && time() - (int) $seen < self::SESSION_ACTIVITY_REFRESH_SECONDS ) {
			return;
		}

		// The TTL IS the idle window, so a missing transient means "not seen in a week".
		set_transient( $key, time(), self::SESSION_EVICTION_IDLE_SECONDS );
	}

	/**
	 * Update last_active timestamp for a session.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function refresh_activity( int $user_id, string $jti ): bool {
		// Public surface: any caller reaching the row goes through the size guard first.
		$this->discard_oversized_row( $user_id );

		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) || ! isset( $refresh_tokens[ $jti ] ) ) {
			return false;
		}

		$refresh_tokens[ $jti ]['last_active'] = time();

		return update_user_meta( $user_id, self::META_KEY, $refresh_tokens );
	}

	/**
	 * Record the latest access token expiry linked to a refresh-token session.
	 *
	 * @param int    $user_id        The user ID.
	 * @param string $refresh_jti    Refresh token JTI.
	 * @param int    $access_expires Access token expiry timestamp.
	 *
	 * @return bool
	 */
	public function record_access_expiry( int $user_id, string $refresh_jti, int $access_expires ): bool {
		if ( empty( $refresh_jti ) || $access_expires <= 0 ) {
			return false;
		}

		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) || ! isset( $refresh_tokens[ $refresh_jti ] ) ) {
			return false;
		}

		$current_access_expires = isset( $refresh_tokens[ $refresh_jti ]['access_expires'] ) ? (int) $refresh_tokens[ $refresh_jti ]['access_expires'] : 0;
		if ( $access_expires <= $current_access_expires ) {
			return true;
		}

		$refresh_tokens[ $refresh_jti ]['access_expires'] = $access_expires;

		return update_user_meta( $user_id, self::META_KEY, $refresh_tokens );
	}

	/**
	 * Revoke JWT Token by JTI.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function revoke( int $user_id, string $jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		if ( isset( $refresh_tokens[ $jti ] ) ) {
			unset( $refresh_tokens[ $jti ] );
			update_user_meta( $user_id, self::META_KEY, $refresh_tokens );
			$this->forget_session_activity( $jti );

			return true;
		}

		return false;
	}

	/**
	 * Drop the stored session row when it is too large to be read safely.
	 *
	 * A LAST RESORT, not a tidy-up: discarding the row signs every one of that user's
	 * devices out at once, so the ceiling is set above anything measured to be readable
	 * (see MAX_SESSIONS_ROW_BYTES) and everything below it is TRIMMED by
	 * `evict_oldest_sessions()` on the same write instead. What this catches is the one
	 * case trimming cannot: a row so large that reading it exhausts the request before any
	 * of the code below runs, which — because that read happens on every login — locks the
	 * user out permanently (#1776). `LENGTH()` lets MySQL answer with a number instead of
	 * the value, so the size is checked without paying for the row.
	 *
	 * @param int $user_id The user ID.
	 */
	private function discard_oversized_row( int $user_id ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, LENGTH(meta_value) AS meta_bytes FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$user_id,
				self::META_KEY
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$bytes = 0;
		foreach ( $rows as $row ) {
			$bytes += (int) $row->meta_bytes;
		}

		if ( $bytes <= self::MAX_SESSIONS_ROW_BYTES ) {
			return;
		}

		foreach ( $rows as $row ) {
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => (int) $row->umeta_id ), array( '%d' ) );
		}

		// The row may already be sitting in the user's meta cache from an earlier
		// `get_user_meta()` in this request; without this the next read serves the value
		// that was just deleted.
		wp_cache_delete( $user_id, 'user_meta' );

		Logger::warning(
			sprintf(
				'Discarded an unreadable WCPOS session row for user %d (%d bytes, ceiling %d). The row was too large to load safely, so every POS session for this user has been logged out once; it is rebuilt, capped, on this login.',
				$user_id,
				$bytes,
				self::MAX_SESSIONS_ROW_BYTES
			)
		);
	}

	/**
	 * Forget a session's recorded activity.
	 *
	 * @param string $jti Refresh token JTI (session identifier).
	 */
	private function forget_session_activity( string $jti ): void {
		if ( '' !== $jti ) {
			delete_transient( self::SESSION_SEEN_TRANSIENT_PREFIX . $jti );
		}
	}

	/**
	 * Drop the least recently active sessions until the per-user cap is met.
	 *
	 * Auth blacklists the returned sessions, so the device that lost its slot is cleanly
	 * logged out instead of keeping a working access token for the remainder of its life.
	 *
	 * @param array  $refresh_tokens Stored sessions keyed by refresh token JTI.
	 * @param string $protected_jti  JTI that must never be evicted (the session being stored).
	 *
	 * @return array Evicted entries keyed by refresh token JTI.
	 */
	private function evict_oldest_sessions( array &$refresh_tokens, string $protected_jti ): array {
		$evict_count = \count( $refresh_tokens ) - self::MAX_SESSIONS_PER_USER;
		if ( $evict_count <= 0 ) {
			return array();
		}

		$evicted     = array();
		$issued_at   = time();
		$idle_before = $issued_at - self::SESSION_EVICTION_IDLE_SECONDS;

		/*
		 * Order eviction candidates oldest-first. The insertion index breaks ties explicitly
		 * because usort() is not stable before PHP 8.0 and bulk logins share a timestamp.
		 *
		 * A session seen within SESSION_EVICTION_IDLE_SECONDS is NOT a candidate at any
		 * count. Being the oldest of N says nothing about being unused when N sessions were
		 * minted in an hour, and evicting a live one blacklists a working device's access
		 * token. The cap yields to that: a user whose sessions are all recent keeps them
		 * all, and the row stays bounded by MAX_SESSIONS_ROW_BYTES instead.
		 */
		$candidates = array();
		$index      = 0;
		foreach ( $refresh_tokens as $candidate_jti => $token_data ) {
			$position = $index++;
			if ( (string) $candidate_jti === $protected_jti ) {
				continue;
			}

			// The ROW timestamp is the cheap filter. It is authoritative when it says a
			// session is live, because login and refresh both write it; when it says idle
			// the activity transient still gets the final word, below.
			$activity = $this->session_row_last_seen( $token_data );
			if ( $activity > $idle_before ) {
				continue;
			}

			$candidates[] = array(
				'jti'      => (string) $candidate_jti,
				'activity' => $activity,
				'index'    => $position,
			);
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['activity'] === $b['activity'] ) {
					return $a['index'] <=> $b['index'];
				}

				return $a['activity'] <=> $b['activity'];
			}
		);

		foreach ( $candidates as $candidate ) {
			if ( $evict_count <= 0 ) {
				break;
			}

			// Checked only for rows already stale, so this costs a handful of transient
			// reads rather than one per stored session.
			if ( $this->session_last_seen( $candidate['jti'], $refresh_tokens[ $candidate['jti'] ] ) > $idle_before ) {
				continue;
			}

			$evicted[ $candidate['jti'] ] = $refresh_tokens[ $candidate['jti'] ];
			$this->forget_session_activity( $candidate['jti'] );
			unset( $refresh_tokens[ $candidate['jti'] ] );
			--$evict_count;
		}

		return $evicted;
	}

	/**
	 * When a session was last seen, taking the later of the row and the activity record.
	 *
	 * The row is rewritten by login and refresh; the transient is written by ordinary
	 * authenticated requests. Neither alone is the whole picture — a device working through
	 * a long-lived access token has an old row timestamp and a fresh transient, and a
	 * session that has not been used at all has the reverse.
	 *
	 * @param string $jti        Refresh token JTI (session identifier).
	 * @param array  $token_data Stored session record.
	 *
	 * @return int Unix timestamp; 0 when neither source carries a usable timestamp.
	 */
	private function session_last_seen( string $jti, array $token_data ): int {
		$row_seen = $this->session_row_last_seen( $token_data );
		$seen     = '' === $jti ? false : get_transient( self::SESSION_SEEN_TRANSIENT_PREFIX . $jti );

		return is_numeric( $seen ) ? max( $row_seen, (int) $seen ) : $row_seen;
	}

	/**
	 * When the stored record itself says a session was last seen.
	 *
	 * Login and refresh both rewrite `last_active` in the row, so this stays accurate for
	 * everything except the stretch between refreshes — which is what the activity
	 * transient covers.
	 *
	 * @param array $token_data Stored session record.
	 *
	 * @return int Unix timestamp; 0 when the record carries no usable timestamp.
	 */
	private function session_row_last_seen( array $token_data ): int {
		if ( isset( $token_data['last_active'] ) ) {
			return (int) $token_data['last_active'];
		}

		if ( isset( $token_data['created'] ) ) {
			return (int) $token_data['created'];
		}

		return 0;
	}

	/**
	 * Parse user agent string to extract device information.
	 *
	 * @param string $user_agent The user agent string.
	 *
	 * @return array
	 */
	private function parse_user_agent( string $user_agent ): array {
		$device_info = array(
			'device_type'     => 'unknown',
			'browser'         => 'unknown',
			'browser_version' => '',
			'os'              => 'unknown',
			'app_type'        => 'web', // web, ios_app, android_app, electron_app.
		);

		if ( empty( $user_agent ) ) {
			return $device_info;
		}

		// Detect WooCommerce POS apps first (custom identifiers)
		// Check for Electron app (including just "WooCommercePOS" in user agent with Electron).
		if ( preg_match( '/Electron/i', $user_agent ) && preg_match( '/WooCommercePOS|WCPOS/i', $user_agent ) ) {
			$device_info['app_type']    = 'electron_app';
			$device_info['browser']     = 'WooCommerce POS';
			$device_info['device_type'] = 'desktop';
			// Try to extract WooCommercePOS version.
			if ( preg_match( '/WooCommercePOS[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/WCPOS[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			}
		} elseif ( preg_match( '/WCPOS[-_]?iOS|WooCommercePOS[-_]?iOS/i', $user_agent ) ) {
			$device_info['app_type']     = 'ios_app';
			$device_info['browser']      = 'WooCommerce POS';
			// Default to tablet unless explicitly detected as phone.
			$device_info['device_type']  = preg_match( '/iphone|ipod/i', $user_agent ) ? 'mobile' : 'tablet';
			if ( preg_match( '/WCPOS[-_]?iOS[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/WooCommercePOS[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			}
		} elseif ( preg_match( '/WCPOS[-_]?Android|WooCommercePOS[-_]?Android/i', $user_agent ) ) {
			$device_info['app_type']     = 'android_app';
			$device_info['browser']      = 'WooCommerce POS';
			// Default to tablet unless explicitly detected as mobile.
			$device_info['device_type']  = preg_match( '/mobile/i', $user_agent ) && ! preg_match( '/tablet/i', $user_agent ) ? 'mobile' : 'tablet';
			if ( preg_match( '/WCPOS[-_]?Android[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/WooCommercePOS[\/\s]([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser_version'] = $matches[1];
			}
		}

		// Detect standard device type (if not already set by app detection).
		if ( 'web' === $device_info['app_type'] ) {
			if ( preg_match( '/mobile|android|iphone|ipod|blackberry|iemobile|opera mini/i', $user_agent ) ) {
				$device_info['device_type'] = 'mobile';
			} elseif ( preg_match( '/tablet|ipad|playbook|silk/i', $user_agent ) ) {
				$device_info['device_type'] = 'tablet';
			} else {
				$device_info['device_type'] = 'desktop';
			}
		}

		// Detect browser (skip if we already detected a WCPOS app).
		if ( 'WooCommerce POS' !== $device_info['browser'] ) {
			if ( preg_match( '/MSIE|Trident/i', $user_agent ) ) {
				$device_info['browser'] = 'Internet Explorer';
				if ( preg_match( '/MSIE ([0-9.]+)/', $user_agent, $matches ) ) {
					$device_info['browser_version'] = $matches[1];
				}
			} elseif ( preg_match( '/Edge\/([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser']         = 'Edge';
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/Edg\/([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser']         = 'Edge';
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/Firefox\/([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser']         = 'Firefox';
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/Chrome\/([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser']         = 'Chrome';
				$device_info['browser_version'] = $matches[1];
			} elseif ( preg_match( '/Safari\/([0-9.]+)/i', $user_agent, $matches ) ) {
				// Safari should be checked after Chrome because Chrome also contains Safari.
				if ( ! preg_match( '/Chrome/i', $user_agent ) ) {
					$device_info['browser']         = 'Safari';
					$device_info['browser_version'] = $matches[1];
				}
			} elseif ( preg_match( '/Opera\/([0-9.]+)/i', $user_agent, $matches ) ) {
				$device_info['browser']         = 'Opera';
				$device_info['browser_version'] = $matches[1];
			}
		}

		// Detect OS.
		if ( preg_match( '/Windows NT ([0-9.]+)/i', $user_agent, $matches ) ) {
			$device_info['os'] = 'Windows';
		} elseif ( preg_match( '/Mac OS X ([0-9_]+)/i', $user_agent, $matches ) ) {
			$device_info['os'] = 'macOS';
		} elseif ( preg_match( '/Android ([0-9.]+)/i', $user_agent, $matches ) ) {
			$device_info['os'] = 'Android';
		} elseif ( preg_match( '/iPhone OS ([0-9_]+)/i', $user_agent, $matches ) ) {
			$device_info['os'] = 'iOS';
		} elseif ( preg_match( '/iPad.*OS ([0-9_]+)/i', $user_agent, $matches ) ) {
			$device_info['os'] = 'iPadOS';
		} elseif ( preg_match( '/Linux/i', $user_agent ) ) {
			$device_info['os'] = 'Linux';
		}

		return $device_info;
	}

	/**
	 * Revoke all refresh tokens for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return bool
	 */
	public function revoke_all( int $user_id ): bool {
		foreach ( $this->entries( $user_id ) as $jti => $token_data ) {
			$this->forget_session_activity( (string) $jti );
		}

		return delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Revoke all sessions except the current one.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $current_jti The current token JTI.
	 *
	 * @return bool
	 */
	public function keep_only( int $user_id, string $current_jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		foreach ( $refresh_tokens as $jti => $token_data ) {
			if ( $jti !== $current_jti ) {
				$this->forget_session_activity( (string) $jti );
			}
		}

		// Keep only the current session in user meta.
		$refresh_tokens = array_filter(
			$refresh_tokens,
			function ( $_token, $jti ) use ( $current_jti ) {
				return $jti === $current_jti;
			},
			ARRAY_FILTER_USE_BOTH
		);

		return update_user_meta( $user_id, self::META_KEY, $refresh_tokens );
	}

	/**
	 * Get the IDs of users with a stored session row.
	 *
	 * @return int[]
	 */
	public function users_with_sessions(): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					self::META_KEY
				)
			)
		);
	}

	/**
	 * Guard the row before a refresh path reads it.
	 *
	 * @param int $user_id The user ID.
	 */
	public function guard_row( int $user_id ): void {
		$this->discard_oversized_row( $user_id );
	}
}
