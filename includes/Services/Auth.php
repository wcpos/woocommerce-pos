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
use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WP_Error;
use WP_User;
use const DAY_IN_SECONDS;
use const HOUR_IN_SECONDS;

/**
 * Auth Service class.
 */
class Auth {
	/**
	 * Maximum retained idle sessions.
	 *
	 * @deprecated Use Session_Registry::MAX_SESSIONS_PER_USER.
	 */
	public const MAX_SESSIONS_PER_USER = Session_Registry::MAX_SESSIONS_PER_USER;

	/**
	 * Minimum idle time before eviction.
	 *
	 * @deprecated Use Session_Registry::SESSION_EVICTION_IDLE_SECONDS.
	 */
	public const SESSION_EVICTION_IDLE_SECONDS = Session_Registry::SESSION_EVICTION_IDLE_SECONDS;

	/**
	 * Session row byte ceiling.
	 *
	 * @deprecated Use Session_Registry::MAX_SESSIONS_ROW_BYTES.
	 */
	public const MAX_SESSIONS_ROW_BYTES = Session_Registry::MAX_SESSIONS_ROW_BYTES;

	/**
	 * The single instance of the class.
	 *
	 * @var null|Auth
	 */
	private static $instance = null;

	/**
	 * Session storage.
	 *
	 * @var Session_Registry
	 */
	private $sessions;

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Or Auth::instance() instead.
	 */
	public function __construct() {
		$this->sessions = new Session_Registry();
	}

	/**
	 * Get the session registry.
	 *
	 * @return Session_Registry
	 */
	public function sessions(): Session_Registry {
		return $this->sessions;
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
	 * Extract a WCPOS token from an authorization value.
	 *
	 * @param mixed $auth_value Authorization value.
	 *
	 * @return null|string
	 */
	public function extract_token( $auth_value ): ?string {
		if ( ! \is_string( $auth_value ) || '' === $auth_value ) {
			return null;
		}

		// Match the old sscanf( 'Bearer %s' ) semantics exactly: any run of
		// whitespace after the scheme, token = the next non-whitespace run.
		if ( 1 === preg_match( '/^Bearer\s+(\S+)/', $auth_value, $matches ) ) {
			return $matches[1];
		}

		return 1 === preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $auth_value ) ? $auth_value : null;
	}

	/**
	 * Authenticate the current request from its WCPOS token.
	 *
	 * @return false|int|WP_Error User ID, validation error, or false when no WCPOS token is present.
	 */
	public function authenticate_request() {
		$auth_header = $this->get_auth_header();
		$token       = $this->extract_token( $auth_header );
		if ( null === $token ) {
			return false;
		}

		$decoded_token = $this->validate_token( $token );
		if ( is_wp_error( $decoded_token ) ) {
			return $decoded_token;
		}

		return absint( $decoded_token->data->user->id );
	}

	/**
	 * Get authorization header/param value.
	 *
	 * Checks multiple sources for the authorization token:
	 * 1. HTTP_AUTHORIZATION server variable (standard)
	 * 2. REDIRECT_HTTP_AUTHORIZATION (Apache CGI workaround)
	 * 3. authorization query parameter (for servers that strip auth headers)
	 *
	 * @return false|string The authorization value or false if not found.
	 */
	public function get_auth_header() {
		// Check HTTP_AUTHORIZATION (not empty - htaccess SetEnvIf can set empty value).
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// Check REDIRECT_HTTP_AUTHORIZATION (Apache CGI).
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Check authorization query param.
		if ( ! empty( $_GET['authorization'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['authorization'] ) );
		}

		return false;
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

				// The session is live: record that, so eviction can tell a device that is
				// working right now from one that has not been seen in a week.
				if ( isset( $decoded_token->refresh_jti ) ) {
					$this->sessions->touch(
						absint( $decoded_token->data->user->id ),
						(string) $decoded_token->refresh_jti
					);
				}
			}

			// Everything looks good return the decoded token.
			return $decoded_token;
		} catch ( \WCPOS\Vendor\Firebase\JWT\ExpiredException $e ) {
			return new WP_Error(
				'woocommerce_pos_auth_token_expired',
				'Token expired',
				array( 'status' => 403 )
			);
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
		$token_data = $this->generate_access_token_data( $user, $refresh_jti );

		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		return $token_data['token'];
	}

	/**
	 * Generate an access token and return the token metadata used by callers.
	 *
	 * @param WP_User $user        The user object.
	 * @param string  $refresh_jti Optional refresh token JTI to link access token to session.
	 *
	 * @return array|WP_Error
	 */
	private function generate_access_token_data( WP_User $user, string $refresh_jti = '' ) {
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
		$expire    = $this->get_access_token_expire( $issued_at );

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
		$payload = apply_filters( 'woocommerce_pos_jwt_access_token_before_sign', $token, $user );
		$token   = JWT::encode( $payload, $this->get_secret_key(), 'HS256' );

		$expires_at        = $this->get_payload_claim( $payload, 'exp' );
		$access_jti        = $this->get_payload_claim( $payload, 'jti' );
		$linked_refresh_jti = $this->get_payload_claim( $payload, 'refresh_jti' );

		$expires_at = null === $expires_at ? $expire : (int) $expires_at;
		$access_jti = null === $access_jti ? $jti : (string) $access_jti;

		if ( null !== $linked_refresh_jti ) {
			$linked_refresh_jti = (string) $linked_refresh_jti;
			$this->sessions->record_access_expiry( $user->ID, $linked_refresh_jti, $expires_at );
		}

		return array(
			'token'       => $token,
			'expires_at'  => $expires_at,
			'jti'         => $access_jti,
			'refresh_jti' => $linked_refresh_jti,
		);
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
		$expire    = $this->get_refresh_token_expire( $issued_at );

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
		$evicted   = $this->sessions->record( $user->ID, $jti, $expire, Session_Context::from_request() );
		$issued_at = time();
		foreach ( $evicted as $evicted_jti => $token_data ) {
			/*
			 * Blacklist ONLY a session that can still hold a live access token. An eviction
			 * is not a revoke: clearing a bloated row can drop thousands of long-dead
			 * sessions at once, and a transient for each would guard nothing — an expired
			 * access token is already rejected on its own `exp` claim, and the refresh token
			 * dies with the meta entry (`is_live()` requires the entry). This
			 * also bounds each transient this path writes to one access-token lifetime,
			 * rather than the refresh-token expiry `get_access_token_blacklist_ttl()` falls
			 * back to for a session with no recorded access-token expiry.
			 */
			$horizon = $this->access_token_horizon( $token_data );
			if ( $horizon > $issued_at ) {
				$this->blacklist_token( $evicted_jti, $horizon - $issued_at );
			}
		}

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
		$access_token_data = $this->generate_access_token_data( $user, $decoded_refresh->jti ?? '' );
		if ( is_wp_error( $access_token_data ) ) {
			return $access_token_data;
		}

		return array(
			'access_token'  => $access_token_data['token'],
			'refresh_token' => $refresh_token,
			'token_type'    => 'Bearer',
			'expires_at'    => (int) $access_token_data['expires_at'],
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
			'roles'        => array_values( $user->roles ),
			// The helper reports effective grants, including role-editor denies.
			'capabilities' => Access_Section::effective_capabilities( $user ),
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

		/*
		 * Before the first row read on this path. A refresh loads the whole session row —
		 * `is_live()` below, then `refresh_activity()` — so it needs
		 * the same protection a login has against a row too large to read (#1776).
		 * Validating an ACCESS token needs no such guard: it no longer touches the row.
		 */
		$this->sessions->guard_row( absint( $decoded->data->user->id ) );

		// Check if refresh token is still valid (not revoked).
		if ( ! $this->sessions->is_live( $decoded->data->user->id, $decoded->jti ?? '' ) ) {
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
		$new_access_token_data = $this->generate_access_token_data( $user, $decoded->jti ?? '' );
		if ( is_wp_error( $new_access_token_data ) ) {
			return $new_access_token_data;
		}

		return array(
			'access_token' => $new_access_token_data['token'],
			'token_type'   => 'Bearer',
			'expires_at'   => (int) $new_access_token_data['expires_at'],
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
		return $this->sessions->revoke( $user_id, $jti );
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
		$refresh_tokens = $this->sessions->entries( $user_id );

		// Blacklist all sessions for instant access token invalidation. The expiry
		// policy is only consulted when there is something to blacklist.
		if ( array() !== $refresh_tokens ) {
			$issued_at     = time();
			$access_expire = $this->get_access_token_expire( $issued_at );

			foreach ( $refresh_tokens as $jti => $token_data ) {
				$ttl = $this->get_access_token_blacklist_ttl( $token_data, $issued_at, $access_expire );
				$this->blacklist_token( $jti, $ttl );
			}
		}

		return $this->sessions->revoke_all( $user_id );
	}

	/**
	 * Get all active sessions for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array
	 */
	public function get_user_sessions( int $user_id ): array {
		return $this->sessions->list( $user_id );
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
		$refresh_tokens = $this->sessions->entries( $user_id );
		if ( array() === $refresh_tokens ) {
			// No row (or nothing in it): nothing to blacklist, nothing to rewrite.
			return false;
		}

		// Blacklist all sessions except current for instant access token invalidation.
		$issued_at     = time();
		$access_expire = $this->get_access_token_expire( $issued_at );

		foreach ( $refresh_tokens as $jti => $token_data ) {
			if ( $jti !== $current_jti ) {
				$ttl = $this->get_access_token_blacklist_ttl( $token_data, $issued_at, $access_expire );
				$this->blacklist_token( $jti, $ttl );
			}
		}

		return $this->sessions->keep_only( $user_id, $current_jti );
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
		return $this->sessions->refresh_activity( $user_id, $jti );
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
		$session_data = $this->sessions->entry( $user_id, $refresh_jti );
		$ttl          = $this->get_access_token_blacklist_ttl( $session_data );

		// Revoke the refresh token (session) from user meta.
		$revoked = $this->revoke_session( $user_id, $refresh_jti );

		if ( $revoked ) {
			// Blacklist the session JTI - this invalidates ALL access tokens for this session
			// TTL covers the current policy and any access token expiry recorded for the session.
			$this->blacklist_token( $refresh_jti, $ttl );
		}

		return $revoked;
	}

	/**
	 * The last moment an access token minted against a session can still validate.
	 *
	 * @param array $token_data Stored session record.
	 *
	 * @return int Unix timestamp; 0 when the session carries no usable timestamp at all.
	 */
	private function access_token_horizon( array $token_data ): int {
		if ( isset( $token_data['access_expires'] ) ) {
			return (int) $token_data['access_expires'];
		}

		// Rows written before `access_expires` was recorded. The newest access token such a
		// session can hold was minted no later than its last recorded activity, so one
		// access-token lifetime past that moment is the outside limit.
		$last_seen = (int) ( $token_data['last_active'] ?? $token_data['created'] ?? 0 );

		return $last_seen > 0 ? $this->get_access_token_expire( $last_seen ) : 0;
	}

	/**
	 * Filters the JWT access token expire time.
	 * Default: 30 minutes for access tokens.
	 *
	 * @param int $issued_at Token issued timestamp.
	 *
	 * @return int Expire time.
	 *
	 * @since 1.8.0
	 *
	 * @hook woocommerce_pos_jwt_access_token_expire
	 */
	private function get_access_token_expire( int $issued_at ): int {
		return (int) apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );
	}

	/**
	 * Filters the JWT refresh token expire time.
	 * Default: 30 days for refresh tokens.
	 *
	 * @param int $issued_at Token issued timestamp.
	 *
	 * @return int Expire time.
	 *
	 * @since 1.8.0
	 *
	 * @hook woocommerce_pos_jwt_refresh_token_expire
	 */
	private function get_refresh_token_expire( int $issued_at ): int {
		return (int) apply_filters( 'woocommerce_pos_jwt_refresh_token_expire', $issued_at + ( DAY_IN_SECONDS * 30 ), $issued_at );
	}

	/**
	 * Read a top-level claim from a JWT payload array/object.
	 *
	 * @param mixed  $payload The filtered JWT payload.
	 * @param string $claim   The claim name.
	 *
	 * @return mixed|null
	 */
	private function get_payload_claim( $payload, string $claim ) {
		if ( \is_array( $payload ) && array_key_exists( $claim, $payload ) ) {
			return $payload[ $claim ];
		}

		if ( \is_object( $payload ) && isset( $payload->{$claim} ) ) {
			return $payload->{$claim};
		}

		return null;
	}

	/**
	 * Calculate blacklist TTL for a session.
	 *
	 * @param array    $session_data  Session metadata.
	 * @param null|int $issued_at     Current timestamp.
	 * @param null|int $access_expire Current access token expiry policy value.
	 *
	 * @return int
	 */
	private function get_access_token_blacklist_ttl(
		array $session_data = array(),
		?int $issued_at = null,
		?int $access_expire = null
	): int {
		$issued_at     = null === $issued_at ? time() : $issued_at;
		$access_expire = null === $access_expire ? $this->get_access_token_expire( $issued_at ) : $access_expire;

		if ( isset( $session_data['access_expires'] ) ) {
			$access_expire = max( $access_expire, (int) $session_data['access_expires'] );
		} elseif ( isset( $session_data['expires'] ) ) {
			$access_expire = max( $access_expire, (int) $session_data['expires'] );
		}

		return max( 0, $access_expire - $issued_at );
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
