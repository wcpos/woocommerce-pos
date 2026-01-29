<?php
/**
 * Auth.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use Exception;
use WCPOS\Vendor\Firebase\JWT\JWT;
use WCPOS\Vendor\Firebase\JWT\Key;
use WP_Error;
use WP_User;
use const DAY_IN_SECONDS;
use const HOUR_IN_SECONDS;

/**
 * Auth Service class.
 */
class Auth {
	/**
	 * The single instance of the class.
	 *
	 * @var null|Auth
	 */
	private static $instance = null;

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Or Auth::instance() instead.
	 */
	public function __construct() {
	}

	/**
	 * Gets the singleton instance.
	 *
	 * @return Auth
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Generate a secret key if it doesn't exist, or return the existing one.
	 *
	 * @return string
	 */
	public function get_secret_key(): string {
		$secret_key = get_option( 'woocommerce_pos_secret_key' );
		if ( false === $secret_key || empty( $secret_key ) ) {
			$secret_key = wp_generate_password( 64, true, true );
			update_option( 'woocommerce_pos_secret_key', $secret_key );
		}

		return $secret_key;
	}

	/**
	 * Get refresh token secret key (separate from access token key for security).
	 *
	 * @return string
	 */
	public function get_refresh_secret_key(): string {
		$secret_key = get_option( 'woocommerce_pos_refresh_secret_key' );
		if ( false === $secret_key || empty( $secret_key ) ) {
			$secret_key = wp_generate_password( 64, true, true );
			update_option( 'woocommerce_pos_refresh_secret_key', $secret_key );
		}

		return $secret_key;
	}

	/**
	 * Validate the provided JWT token.
	 *
	 * @param string $token      The JWT token.
	 * @param string $token_type The token type: 'access' or 'refresh'.
	 *
	 * @return object|WP_Error
	 */
	public function validate_token( $token = '', $token_type = 'access' ) {
		try {
			$secret_key    = 'refresh' === $token_type ? $this->get_refresh_secret_key() : $this->get_secret_key();
			$decoded_token = JWT::decode( $token, new Key( $secret_key, 'HS256' ) ); // @phpstan-ignore-line

			// The Token is decoded now validate the iss.
			if ( get_bloginfo( 'url' ) != $decoded_token->iss ) {
				// The iss do not match, return error.
				return new WP_Error(
					'woocommmerce_pos_auth_bad_iss',
					'The iss do not match with this server',
					array( 'status' => 403 )
				);
			}

			// Validate token type.
			if ( ! isset( $decoded_token->type ) || $decoded_token->type !== $token_type ) {
				return new WP_Error(
					'woocommmerce_pos_auth_invalid_token_type',
					'Invalid token type',
					array( 'status' => 403 )
				);
			}

			// So far so good, validate the user id in the token.
			if ( ! isset( $decoded_token->data->user->id ) ) {
				// No user id in the token, abort!!
				return new WP_Error(
					'woocommmerce_pos_auth_bad_request',
					'User ID not found in the token',
					array(
						'status' => 403,
					)
				);
			}

			// Check if access token is blacklisted (for instant revocation)
			// We check both the access token's own JTI and its parent refresh_jti.
			if ( 'access' === $token_type ) {
				// Check if this specific access token is blacklisted.
				if ( isset( $decoded_token->jti ) && $this->is_token_blacklisted( $decoded_token->jti ) ) {
					return new WP_Error(
						'woocommerce_pos_auth_token_revoked',
						'Access token has been revoked',
						array( 'status' => 403 )
					);
				}

				// Check if the parent session (refresh token) is blacklisted
				// This catches ALL access tokens for a revoked session.
				if ( isset( $decoded_token->refresh_jti ) && $this->is_token_blacklisted( $decoded_token->refresh_jti ) ) {
					return new WP_Error(
						'woocommerce_pos_auth_session_revoked',
						'Session has been revoked',
						array( 'status' => 403 )
					);
				}
			}

			// Everything looks good return the decoded token.
			return $decoded_token;
		} catch ( Exception $e ) {
			// Something is wrong trying to decode the token, send back the error.
			return new WP_Error(
				'woocommmerce_pos_auth_invalid_token',
				$e->getMessage(),
				array(
					'status' => 403,
				)
			);
		}
	}

	/**
	 * Generate an access token for the provided user (short-lived).
	 *
	 * @param WP_User $user        The user object.
	 * @param string  $refresh_jti Optional refresh token JTI to link access token to session.
	 *
	 * @return string|WP_Error
	 */
	public function generate_access_token( WP_User $user, string $refresh_jti = '' ) {
		// First thing, check the secret key if not exist return a error.
		if ( ! $this->get_secret_key() ) {
			return new WP_Error(
				'woocommerce_pos_jwt_auth_bad_config',
				__( 'JWT is not configured properly, please contact the admin', 'woocommerce-pos' ),
				array(
					'status' => 403,
				)
			);
		}

		/** Valid credentials, the user exists create the according Token */
		$issued_at = time();

		/**
		 * Filters the JWT access token expire time.
		 * Default: 30 minutes for access tokens.
		 *
		 * @param int $expire_time
		 * @param int $issued_at
		 *
		 * @returns int Expire time
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_access_token_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );

		// Generate unique JTI for access token.
		$jti = wp_generate_uuid4();

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'exp'  => $expire,
			'jti'  => $jti,
			'type' => 'access',
			'data' => array(
				'user' => array(
					'id' => $user->data->ID,
				),
			),
		);

		// Link to refresh token if provided.
		if ( ! empty( $refresh_jti ) ) {
			$token['refresh_jti'] = $refresh_jti;
		}

		/*
		 * Let the user modify the access token data before the sign.
		 *
		 * @param {array} $token
		 * @param {WP_User} $user
		 *
		 * @returns {array} Token
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_access_token_before_sign
		 */
		return JWT::encode( apply_filters( 'woocommerce_pos_jwt_access_token_before_sign', $token, $user ), $this->get_secret_key(), 'HS256' );
	}

	/**
	 * Generate a refresh token for the provided user (long-lived).
	 *
	 * @param WP_User $user The user object.
	 *
	 * @return string|WP_Error
	 */
	public function generate_refresh_token( WP_User $user ) {
		// First thing, check the secret key if not exist return a error.
		if ( ! $this->get_refresh_secret_key() ) {
			return new WP_Error(
				'woocommerce_pos_jwt_auth_bad_config',
				__( 'JWT is not configured properly, please contact the admin', 'woocommerce-pos' ),
				array(
					'status' => 403,
				)
			);
		}

		/** Valid credentials, the user exists create the according Token */
		$issued_at = time();

		/**
		 * Filters the JWT refresh token expire time.
		 * Default: 30 days for refresh tokens.
		 *
		 * @param int $expire_time
		 * @param int $issued_at
		 *
		 * @returns int Expire time
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_refresh_token_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_refresh_token_expire', $issued_at + ( DAY_IN_SECONDS * 30 ), $issued_at );

		// Generate unique JTI (JWT ID) for refresh token tracking.
		$jti = wp_generate_uuid4();

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'exp'  => $expire,
			'jti'  => $jti,
			'type' => 'refresh',
			'data' => array(
				'user' => array(
					'id' => $user->data->ID,
				),
			),
		);

		/**
		 * Let the user modify the refresh token data before the sign.
		 *
		 * @param array $token
		 * @param WP_User $user
		 *
		 * @returns array Token
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_refresh_token_before_sign
		 */
		$token = JWT::encode( apply_filters( 'woocommerce_pos_jwt_refresh_token_before_sign', $token, $user ), $this->get_refresh_secret_key(), 'HS256' );

		// Store refresh token JTI for potential revocation.
		$this->store_refresh_token_jti( $user->ID, $jti, $expire );

		return $token;
	}

	/**
	 * Generate both access and refresh tokens.
	 *
	 * @param WP_User $user The user object.
	 *
	 * @return array|WP_Error
	 */
	public function generate_token_pair( WP_User $user ) {
		// Generate refresh token first to get its JTI.
		$refresh_token = $this->generate_refresh_token( $user );
		if ( is_wp_error( $refresh_token ) ) {
			return $refresh_token;
		}

		// Decode to get the JTI.
		$decoded_refresh = $this->validate_token( $refresh_token, 'refresh' );
		if ( is_wp_error( $decoded_refresh ) ) {
			return $decoded_refresh;
		}

		// Generate access token with link to refresh token.
		$access_token = $this->generate_access_token( $user, $decoded_refresh->jti ?? '' );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$issued_at = time();
		$expire    = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'token_type'    => 'Bearer',
			'expires_at'    => (int) $expire,
		);
	}

	/**
	 * Legacy method for backward compatibility.
	 *
	 * @deprecated Use generate_access_token() instead
	 *
	 * @param WP_User $user The user object.
	 *
	 * @return string|WP_Error
	 */
	public function generate_token( WP_User $user ) {
		return $this->generate_access_token( $user );
	}

	/**
	 * Get user's data (minimal set for security).
	 *
	 * @param WP_User $user The user object.
	 * @param bool    $is_web_frontend Whether this is the web frontend context.
	 *                                 When true, manages web session cookie to prevent
	 *                                 session proliferation on page refresh.
	 *
	 * @return array
	 */
	public function get_user_data( WP_User $user, bool $is_web_frontend = false ): array {
		// For web frontend, revoke previous session to prevent proliferation on page refresh.
		if ( $is_web_frontend ) {
			$this->cleanup_previous_web_session( $user->ID );
		}

		$tokens = $this->generate_token_pair( $user );
		if ( is_wp_error( $tokens ) ) {
			return array();
		}

		// For web frontend, store the new session JTI in a cookie for cleanup on next page load.
		if ( $is_web_frontend ) {
			$this->set_web_session_cookie( $tokens['refresh_token'] );
		}

		return array(
			'uuid'         => Cashier::instance()->get_cashier_uuid( $user ),
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'first_name'   => $user->user_firstname,
			'last_name'    => $user->user_lastname,
			'nice_name'    => $user->user_nicename,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID ),
			// Token data.
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'token_type'    => $tokens['token_type'],
			'expires_at'    => $tokens['expires_at'],
		);
	}

	/**
	 * Get minimal user data for redirect (security-focused).
	 *
	 * @param WP_User $user The user object.
	 *
	 * @return array
	 */
	public function get_redirect_data( WP_User $user ): array {
		$tokens = $this->generate_token_pair( $user );
		if ( is_wp_error( $tokens ) ) {
			return array();
		}

		// Only return essential data for redirect URL.
		return array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'token_type'    => $tokens['token_type'],
			'expires_at'    => $tokens['expires_at'],
			// Get basic user data for display, other data will be fetched from the server.
			'uuid'          => Cashier::instance()->get_cashier_uuid( $user ),
			'id'            => $user->ID,
			'display_name'  => $user->display_name,
		);
	}

	/**
	 * Refresh an access token using a valid refresh token.
	 *
	 * @param string $refresh_token The refresh token.
	 *
	 * @return array|WP_Error
	 */
	public function refresh_access_token( string $refresh_token ) {
		$decoded = $this->validate_token( $refresh_token, 'refresh' );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		// Check if refresh token is still valid (not revoked).
		if ( ! $this->is_refresh_token_valid( $decoded->data->user->id, $decoded->jti ?? '' ) ) {
			return new WP_Error(
				'woocommerce_pos_auth_refresh_token_revoked',
				'Refresh token has been revoked',
				array( 'status' => 403 )
			);
		}

		$user = get_user_by( 'id', $decoded->data->user->id );
		if ( ! $user ) {
			return new WP_Error(
				'woocommerce_pos_auth_user_not_found',
				'User not found',
				array( 'status' => 404 )
			);
		}

		// Update last_active timestamp for this session.
		$this->update_session_activity( $decoded->data->user->id, $decoded->jti ?? '' );

		// Generate new access token with link to refresh token (refresh token stays the same).
		$new_access_token = $this->generate_access_token( $user, $decoded->jti ?? '' );
		if ( is_wp_error( $new_access_token ) ) {
			return $new_access_token;
		}

		$issued_at = time();
		$expire    = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );

		return array(
			'access_token' => $new_access_token,
			'token_type'   => 'Bearer',
			'expires_at'   => (int) $expire,
		);
	}

	/**
	 * Revoke JWT Token by JTI.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function revoke_refresh_token( int $user_id, string $jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		if ( isset( $refresh_tokens[ $jti ] ) ) {
			unset( $refresh_tokens[ $jti ] );
			update_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', $refresh_tokens );

			return true;
		}

		return false;
	}

	/**
	 * Revoke all refresh tokens for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return bool
	 */
	/**
	 * Revoke all refresh tokens for a user with blacklisting.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return bool
	 */
	public function revoke_all_refresh_tokens( int $user_id ): bool {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );

		// Blacklist all sessions for instant access token invalidation.
		if ( \is_array( $refresh_tokens ) ) {
			$issued_at = time();
			$expire    = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );
			$ttl       = max( 0, $expire - $issued_at );

			foreach ( $refresh_tokens as $jti => $token_data ) {
				$this->blacklist_token( $jti, $ttl );
			}
		}

		return delete_user_meta( $user_id, '_woocommerce_pos_refresh_tokens' );
	}

	/**
	 * Get all active sessions for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array
	 */
	public function get_user_sessions( int $user_id ): array {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
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
	 * Revoke a specific session by JTI (alias for revoke_refresh_token for clarity).
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function revoke_session( int $user_id, string $jti ): bool {
		return $this->revoke_refresh_token( $user_id, $jti );
	}

	/**
	 * Revoke all sessions except the current one.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $current_jti The current token JTI.
	 *
	 * @return bool
	 */
	/**
	 * Revoke all sessions except the current one, with blacklisting.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $current_jti The current token JTI.
	 *
	 * @return bool
	 */
	public function revoke_all_sessions_except( int $user_id, string $current_jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		// Blacklist all sessions except current for instant access token invalidation.
		$issued_at = time();
		$expire    = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );
		$ttl       = max( 0, $expire - $issued_at );

		foreach ( $refresh_tokens as $jti => $token_data ) {
			if ( $jti !== $current_jti ) {
				$this->blacklist_token( $jti, $ttl );
			}
		}

		// Keep only the current session in user meta.
		$refresh_tokens = array_filter(
			$refresh_tokens,
			function ( $token, $jti ) use ( $current_jti ) {
				return $jti === $current_jti;
			},
			ARRAY_FILTER_USE_BOTH
		);

		return update_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', $refresh_tokens );
	}

	/**
	 * Update last_active timestamp for a session.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	public function update_session_activity( int $user_id, string $jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
		if ( ! \is_array( $refresh_tokens ) || ! isset( $refresh_tokens[ $jti ] ) ) {
			return false;
		}

		$refresh_tokens[ $jti ]['last_active'] = time();

		return update_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', $refresh_tokens );
	}

	/**
	 * Check if the current user can manage sessions for the target user.
	 *
	 * @param int $target_user_id The target user ID.
	 *
	 * @return bool
	 */
	public function can_manage_user_sessions( int $target_user_id ): bool {
		$current_user_id = get_current_user_id();

		// User can manage their own sessions.
		if ( $current_user_id === $target_user_id ) {
			return true;
		}

		// Administrators can manage anyone's sessions.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Shop managers can manage anyone's sessions.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Blacklist a token JTI (for instant revocation).
	 *
	 * Can be used for access token JTIs or refresh token JTIs (session).
	 * When a refresh_jti is blacklisted, all access tokens linked to it
	 * become invalid.
	 *
	 * @param string $jti Token JTI to blacklist.
	 * @param int    $ttl Time to live in seconds.
	 *
	 * @return bool
	 */
	public function blacklist_token( string $jti, int $ttl ): bool {
		if ( empty( $jti ) ) {
			return false;
		}

		// Use transient with TTL matching token expiration.
		return set_transient( "wcpos_blacklist_{$jti}", true, $ttl );
	}

	/**
	 * Revoke session and blacklist it for instant access token invalidation.
	 *
	 * By blacklisting the refresh_jti, ALL access tokens linked to this session
	 * become immediately invalid (they contain refresh_jti in their payload).
	 *
	 * @param int    $user_id The user ID.
	 * @param string $refresh_jti Refresh token JTI (session identifier).
	 *
	 * @return bool
	 */
	public function revoke_session_with_blacklist( int $user_id, string $refresh_jti ): bool {
		// Revoke the refresh token (session) from user meta.
		$revoked = $this->revoke_session( $user_id, $refresh_jti );

		if ( $revoked ) {
			// Blacklist the session JTI - this invalidates ALL access tokens for this session
			// TTL matches access token expiry (30 min default) since that's how long we need to block.
			$issued_at = time();
			$expire    = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );
			$ttl       = max( 0, $expire - $issued_at );

			$this->blacklist_token( $refresh_jti, $ttl );
		}

		return $revoked;
	}

	/**
	 * Store refresh token JTI for tracking/revocation.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 * @param int    $expires The expiration timestamp.
	 */
	private function store_refresh_token_jti( int $user_id, string $jti, int $expires ): void {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
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
		$ip_address   = $this->get_client_ip();
		$user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$device_info  = $this->parse_user_agent( $user_agent );

		// Check for explicit platform declaration from native apps (passed as query param in auth URL).
		$platform = isset( $_REQUEST['platform'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['platform'] ) ) : '';
		$version  = isset( $_REQUEST['version'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['version'] ) ) : '';
		$build    = isset( $_REQUEST['build'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['build'] ) ) : '';

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

		update_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', $refresh_tokens );
	}

	/**
	 * Check if refresh token is still valid (not revoked).
	 *
	 * @param int    $user_id The user ID.
	 * @param string $jti            The token JTI.
	 *
	 * @return bool
	 */
	private function is_refresh_token_valid( int $user_id, string $jti ): bool {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
		if ( ! \is_array( $refresh_tokens ) ) {
			return false;
		}

		return isset( $refresh_tokens[ $jti ] ) && $refresh_tokens[ $jti ]['expires'] > time();
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip_address = '';

		// Check for various proxy headers.
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip_address = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs).
				if ( false !== strpos( $ip_address, ',' ) ) {
					$ip_parts   = explode( ',', $ip_address );
					$ip_address = trim( $ip_parts[0] );
				}

				break;
			}
		}

		// Validate and sanitize IP.
		if ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
			return $ip_address;
		}

		return '';
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
	 * Check if a token JTI is blacklisted.
	 *
	 * Works for both access token JTIs and refresh token JTIs (sessions).
	 *
	 * @param string $jti Token JTI to check.
	 *
	 * @return bool
	 */
	private function is_token_blacklisted( string $jti ): bool {
		if ( empty( $jti ) ) {
			return false;
		}

		// Check transient.
		return false !== get_transient( "wcpos_blacklist_{$jti}" );
	}

	/**
	 * Clean up previous web session to prevent session proliferation.
	 *
	 * The web application generates new tokens on every page load. This method
	 * revokes the previous session (stored in a cookie) so only one web session
	 * exists per browser at a time.
	 *
	 * @param int $user_id The user ID.
	 */
	private function cleanup_previous_web_session( int $user_id ): void {
		$cookie_name = 'wcpos_web_session_jti';

		if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
			return;
		}

		$previous_jti = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );

		if ( empty( $previous_jti ) ) {
			return;
		}

		// Revoke the previous session (silently - don't care if it fails).
		$this->revoke_session( $user_id, $previous_jti );
	}

	/**
	 * Set a cookie to track the current web session JTI.
	 *
	 * @param string $refresh_token The refresh token to extract JTI from.
	 */
	private function set_web_session_cookie( string $refresh_token ): void {
		$decoded = $this->validate_token( $refresh_token, 'refresh' );

		if ( is_wp_error( $decoded ) || empty( $decoded->jti ) ) {
			return;
		}

		$cookie_name = 'wcpos_web_session_jti';
		$jti         = $decoded->jti;
		$expires     = $decoded->exp ?? ( time() + DAY_IN_SECONDS * 30 );

		// Set cookie with same expiry as refresh token
		// Use httponly for security, but not secure flag as POS may run on localhost.
		setcookie(
			$cookie_name,
			$jti,
			array(
				'expires'  => $expires,
				'path'     => \defined( 'COOKIEPATH' ) ? COOKIEPATH : '/', // @phpstan-ignore-line
				'domain'   => \defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', // @phpstan-ignore-line
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
