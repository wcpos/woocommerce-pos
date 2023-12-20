<?php

namespace WCPOS\WooCommercePOS\Services;

use Exception;
use WCPOS\Vendor\Firebase\JWT\JWT;
use WCPOS\Vendor\Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;
use WP_Error;
use WP_User;
use const DAY_IN_SECONDS;

/**
 * Auth Service class
 */
class Auth {
	/**
	 * The single instance of the class.
	 *
	 * @var Auth|null
	 */
	private static $instance = null;

		/**
		 * Gets the singleton instance.
		 *
		 * @return Auth
		 */
	public static function instance(): Auth {
		if ( null === self::$instance ) {
				self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Or Auth::instance() instead.
	 */
	public function __construct() {
	}

	/**
	 * Generate a secret key if it doesn't exist, or return the existing one
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
	 * Validate the provided JWT token
	 *
	 * @param string $token
	 *
	 * @return object|WP_Error
	 */
	public function validate_token( $token = '' ) {
		try {
			$decoded_token = JWT::decode( $token, new Key( $this->get_secret_key(), 'HS256' ) );

			// The Token is decoded now validate the iss
			if ( get_bloginfo( 'url' ) != $decoded_token->iss ) {
				// The iss do not match, return error
				return new WP_Error(
					'woocommmerce_pos_auth_bad_iss',
					'The iss do not match with this server',
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

			/** Everything looks good return the decoded token */
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
	 * Generate a JWT token for the provided user
	 *
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	/**
	 * Generate a JWT token for the provided user
	 *
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	public function generate_token( WP_User $user ) {
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
		 * Filters the JWT issued at time.
		 *
		 * @param {string} $issued_at
		 * @returns {string} Issued at time
		 * @since 1.0.0
		 * @hook woocommerce_pos_jwt_auth_not_before
		 */
		$not_before = apply_filters( 'woocommerce_pos_jwt_auth_not_before', $issued_at, $issued_at );

		/**
		 * Filters the JWT expire time.
		 *
		 * @param {string} $issued_at
		 * @returns {string} Expire time
		 * @since 1.0.0
		 * @hook woocommerce_pos_jwt_auth_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_auth_expire', $issued_at + ( DAY_IN_SECONDS * 7 ), $issued_at );

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'nbf'  => $not_before,
			'exp'  => $expire,
			'data' => array(
				'user' => array(
					'id' => $user->data->ID,
				),
			),
		);

		/**
		 * Let the user modify the token data before the sign.
		 *
		 * @param {string} $token
		 * @param {WP_User} $user
		 * @returns {string} Token
		 * @since 1.0.0
		 * @hook woocommerce_pos_jwt_auth_token_before_sign
		 */
		$token = JWT::encode( apply_filters( 'woocommerce_pos_jwt_auth_token_before_sign', $token, $user ), $this->get_secret_key(), 'HS256' );

		return $token;
	}


	/**
	 * Get user's data
	 *
	 * @param WP_User $user
	 *
	 * @return array
	 */
	public function get_user_data( WP_User $user ): array {
		$data = array(
			'uuid'         => $this->get_user_uuid( $user ),
			'id'           => $user->ID,
			'jwt'          => $this->generate_token( $user ),
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'first_name'   => $user->user_firstname,
			'last_name'    => $user->user_lastname,
			'nice_name'    => $user->user_nicename,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID ),
		);

		return $data;
	}

	/**
	 * Note: usermeta is shared across all sites in a network, this can cause issues in the POS.
	 * We need to make sure that the user uuid is unique per site.
	 *
	 * @param WP_User $user
	 * @return string
	 */
	private function get_user_uuid( WP_User $user ): string {
		$meta_key = '_woocommerce_pos_uuid';

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$meta_key = $meta_key . '_' . get_current_blog_id();
		}

		$uuid = get_user_meta( $user->ID, $meta_key, true );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			update_user_meta( $user->ID, $meta_key, $uuid );
		}

		return $uuid;
	}

	/**
	 * Revoke JWT Token
	 */
	public function revoke_token(): void {
		// Implementation
	}

	/**
	 * Refresh JWT Token.
	 */
	public function refresh_token(): void {
		// Implementation
	}
}
