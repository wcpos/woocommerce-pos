<?php

namespace WCPOS\WooCommercePOS\Services;

use const DAY_IN_SECONDS;
use Exception;
use const HOUR_IN_SECONDS;
use Ramsey\Uuid\Uuid;
use WCPOS\Vendor\Firebase\JWT\JWT;
use WCPOS\Vendor\Firebase\JWT\Key;
use WP_Error;
use WP_User;

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
	 * @param string $token
	 * @param string $token_type 'access' or 'refresh'
	 *
	 * @return object|WP_Error
	 */
	public function validate_token( $token = '', $token_type = 'access' ) {
		try {
			$secret_key    = 'refresh' === $token_type ? $this->get_refresh_secret_key() : $this->get_secret_key();
			$decoded_token = JWT::decode( $token, new Key( $secret_key, 'HS256' ) );

			// The Token is decoded now validate the iss
			if ( get_bloginfo( 'url' ) != $decoded_token->iss ) {
				// The iss do not match, return error
				return new WP_Error(
					'woocommmerce_pos_auth_bad_iss',
					'The iss do not match with this server',
					array( 'status' => 403 )
				);
			}

			// Validate token type
			if ( ! isset( $decoded_token->type ) || $decoded_token->type !== $token_type ) {
				return new WP_Error(
					'woocommmerce_pos_auth_invalid_token_type',
					'Invalid token type',
					array( 'status' => 403 )
				);
			}

			// So far so good, validate the user id in the token
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

			// Everything looks good return the decoded token
			return $decoded_token;
		} catch ( Exception $e ) {
			// Something is wrong trying to decode the token, send back the error
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
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	public function generate_access_token( WP_User $user ) {
		// First thing, check the secret key if not exist return a error
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
		 * @param {int} $expire_time
		 * @param {int} $issued_at
		 *
		 * @returns {int} Expire time
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_access_token_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_access_token_expire', $issued_at + ( HOUR_IN_SECONDS / 2 ), $issued_at );

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'exp'  => $expire,
			'type' => 'access',
			'data' => array(
				'user' => array(
					'id' => $user->data->ID,
				),
			),
		);

		/**
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
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	public function generate_refresh_token( WP_User $user ) {
		// First thing, check the secret key if not exist return a error
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
		 * @param {int} $expire_time
		 * @param {int} $issued_at
		 *
		 * @returns {int} Expire time
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_refresh_token_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_refresh_token_expire', $issued_at + ( DAY_IN_SECONDS * 30 ), $issued_at );

		// Generate unique JTI (JWT ID) for refresh token tracking
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
		 * @param {array} $token
		 * @param {WP_User} $user
		 *
		 * @returns {array} Token
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_jwt_refresh_token_before_sign
		 */
		$token = JWT::encode( apply_filters( 'woocommerce_pos_jwt_refresh_token_before_sign', $token, $user ), $this->get_refresh_secret_key(), 'HS256' );

		// Store refresh token JTI for potential revocation
		$this->store_refresh_token_jti( $user->ID, $jti, $expire );

		return $token;
	}

	/**
	 * Generate both access and refresh tokens.
	 *
	 * @param WP_User $user
	 *
	 * @return array|WP_Error
	 */
	public function generate_token_pair( WP_User $user ) {
		$access_token = $this->generate_access_token( $user );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$refresh_token = $this->generate_refresh_token( $user );
		if ( is_wp_error( $refresh_token ) ) {
			return $refresh_token;
		}

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'token_type'    => 'Bearer',
			'expires_in'    => apply_filters( 'woocommerce_pos_jwt_access_token_expire', HOUR_IN_SECONDS / 2, time() ) - time(),
		);
	}

	/**
	 * Legacy method for backward compatibility.
	 *
	 * @deprecated Use generate_access_token() instead
	 *
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	public function generate_token( WP_User $user ) {
		return $this->generate_access_token( $user );
	}

	/**
	 * Get user's data (minimal set for security).
	 *
	 * @param WP_User $user
	 *
	 * @return array
	 */
	public function get_user_data( WP_User $user ): array {
		$tokens = $this->generate_token_pair( $user );
		if ( is_wp_error( $tokens ) ) {
			return array();
		}

		return array(
			'uuid'         => $this->get_user_uuid( $user ),
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'first_name'   => $user->user_firstname,
			'last_name'    => $user->user_lastname,
			'nice_name'    => $user->user_nicename,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID ),
			// Token data
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'token_type'    => $tokens['token_type'],
			'expires_in'    => $tokens['expires_in'],
		);
	}

	/**
	 * Get minimal user data for redirect (security-focused).
	 *
	 * @param WP_User $user
	 *
	 * @return array
	 */
	public function get_redirect_data( WP_User $user ): array {
		$tokens = $this->generate_token_pair( $user );
		if ( is_wp_error( $tokens ) ) {
			return array();
		}

		// Only return essential data for redirect URL
		return array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'token_type'    => $tokens['token_type'],
			'expires_in'    => $tokens['expires_in'],
			'user_id'       => $user->ID, // Minimal user identification
		);
	}

	/**
	 * Refresh an access token using a valid refresh token.
	 *
	 * @param string $refresh_token
	 *
	 * @return array|WP_Error
	 */
	public function refresh_access_token( string $refresh_token ) {
		$decoded = $this->validate_token( $refresh_token, 'refresh' );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		// Check if refresh token is still valid (not revoked)
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

		// Generate new access token (refresh token stays the same)
		$new_access_token = $this->generate_access_token( $user );
		if ( is_wp_error( $new_access_token ) ) {
			return $new_access_token;
		}

		return array(
			'access_token' => $new_access_token,
			'token_type'   => 'Bearer',
			'expires_in'   => apply_filters( 'woocommerce_pos_jwt_access_token_expire', HOUR_IN_SECONDS / 2, time() ) - time(),
		);
	}

	/**
	 * Revoke JWT Token by JTI.
	 *
	 * @param int    $user_id
	 * @param string $jti
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
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public function revoke_all_refresh_tokens( int $user_id ): bool {
		return delete_user_meta( $user_id, '_woocommerce_pos_refresh_tokens' );
	}

	/**
	 * Store refresh token JTI for tracking/revocation.
	 *
	 * @param int    $user_id
	 * @param string $jti
	 * @param int    $expires
	 */
	private function store_refresh_token_jti( int $user_id, string $jti, int $expires ): void {
		$refresh_tokens = get_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', true );
		if ( ! \is_array( $refresh_tokens ) ) {
			$refresh_tokens = array();
		}

		// Clean up expired tokens
		$refresh_tokens = array_filter( $refresh_tokens, function( $token ) {
			return $token['expires'] > time();
		});

		// Add new token
		$refresh_tokens[ $jti ] = array(
			'expires' => $expires,
			'created' => time(),
		);

		update_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', $refresh_tokens );
	}

	/**
	 * Check if refresh token is still valid (not revoked).
	 *
	 * @param int    $user_id
	 * @param string $jti
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
	 * Note: usermeta is shared across all sites in a network, this can cause issues in the POS.
	 * We need to make sure that the user uuid is unique per site.
	 *
	 * @param WP_User $user
	 *
	 * @return string
	 */
	private function get_user_uuid( WP_User $user ): string {
		$meta_key = '_woocommerce_pos_uuid';

		if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
			$meta_key = $meta_key . '_' . get_current_blog_id();
		}

		$uuid = get_user_meta( $user->ID, $meta_key, true );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			update_user_meta( $user->ID, $meta_key, $uuid );
		}

		return $uuid;
	}
}
