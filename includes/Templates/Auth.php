<?php
/**
 * Auth template
 * NOTE: This is for authentication via JWT, used by mobile/desktop apps.
 *
 * Security measures:
 * - Direct credential validation (bypasses wp_authenticate to avoid 2FA/captcha plugins)
 * - Rate limiting by connecting peer address (`REMOTE_ADDR`)
 * - Account lockout after failed attempts, per account and peer
 * - Honeypot field for bot detection
 * - Auth session expiration
 * - State parameter validation
 * - Redirect URI scheme and origin validation
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WCPOS\WooCommercePOS\Services\Cashier;
use WP_Error;
use WP_User;

/**
 * Auth template.
 */
class Auth {
	/**
	 * Rate limit: max attempts per IP per time window.
	 */
	private const MAX_ATTEMPTS_PER_IP = 10;

	/**
	 * Rate limit: time window in seconds (15 minutes).
	 */
	private const RATE_LIMIT_WINDOW = 900;

	/**
	 * Account lockout: max failed attempts per account and peer.
	 */
	private const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Account lockout: duration in seconds (15 minutes).
	 */
	private const LOCKOUT_DURATION = 900;

	/**
	 * Auth session expiration in seconds (10 minutes).
	 */
	private const AUTH_SESSION_EXPIRY = 600;

	/**
	 * Allowed redirect URI schemes.
	 *
	 * The native app registers one URL scheme per build profile so that a
	 * device with two variants installed (e.g. the store build and the dev
	 * client) returns the login to the app that started it: `wcpos` for the
	 * store build, `wcpos-dev` for the development client, `wcpos-adhoc` for
	 * ad-hoc test builds (monorepo `apps/main/app.config.ts`). `exp` is the
	 * Expo Go client. Matching is an exact `<scheme>://` prefix, so listing
	 * `wcpos-dev` does not admit `wcpos-devious`.
	 *
	 * @var array
	 */
	private const ALLOWED_SCHEMES = array( 'wcpos', 'wcpos-dev', 'wcpos-adhoc', 'exp', 'https', 'http' );

	/**
	 * Web origins that may receive a login redirect besides the site's own.
	 *
	 * `https://wcpos.expo.app` is the hosted production client (monorepo
	 * `.github/workflows/deploy.yml`, job "Deploy production"). Preview
	 * deployments live on variable `wcpos--<id>.expo.app` hosts and are NOT
	 * listed: any Expo account can register an `expo.app` subdomain, so a
	 * wildcard would reopen the hole. A test store allows its preview origins
	 * through `woocommerce_pos_auth_redirect_origin_allowed`.
	 *
	 * @var array
	 */
	private const ALLOWED_WEB_ORIGINS = array( 'https://wcpos.expo.app' );

	/**
	 * The redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * The state parameter.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Auth session token (for expiring auth URLs).
	 *
	 * @var string
	 */
	private $auth_session;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hide the admin bar for a clean login UI.
		add_filter( 'show_admin_bar', '__return_false' );

		// Initialize properties.
		$this->redirect_uri = $this->validate_redirect_uri( isset( $_REQUEST['redirect_uri'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect_uri'] ) ) : '' );
		$this->state        = sanitize_text_field( wp_unslash( $_REQUEST['state'] ?? '' ) );
		$this->auth_session = sanitize_text_field( wp_unslash( $_REQUEST['auth_session'] ?? '' ) );
		$this->error        = '';

		// Validate required parameters.
		if ( empty( $this->redirect_uri ) ) {
			$this->error = __( 'Missing or invalid redirect_uri parameter.', 'woocommerce-pos' );

			return;
		}

		if ( empty( $this->state ) ) {
			$this->error = /* translators: Short WCPOS UI label; keep concise. */ __( 'Missing state parameter.', 'woocommerce-pos' );

			return;
		}

		// Create or validate auth session (for expiring auth URLs).
		if ( ! $this->validate_or_create_auth_session() ) {
			return;
		}

		// Check IP rate limit before processing.
		if ( $this->is_ip_rate_limited() ) {
			$this->error = __( 'Too many requests. Please try again later.', 'woocommerce-pos' );
			$this->log_auth_attempt( '', 'rate_limited' );

			return;
		}

		// Handle form submission.
		$this->handle_form_submission();
	}

	/**
	 * Get the redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return $this->redirect_uri;
	}

	/**
	 * Get the state parameter.
	 *
	 * @return string
	 */
	public function get_state(): string {
		return $this->state;
	}

	/**
	 * Get the error message.
	 *
	 * @return string
	 */
	public function get_error(): string {
		return $this->error;
	}

	/**
	 * Get the auth session token.
	 *
	 * @return string
	 */
	public function get_auth_session(): string {
		return $this->auth_session;
	}

	/**
	 * Render the auth template.
	 *
	 * @return void
	 */
	public function get_template(): void {
		// NOTE: We intentionally do NOT call do_action('login_init') here.
		// This auth form bypasses WordPress's standard login flow to avoid
		// interference from security plugins (2FA, captcha, etc.).

		/*
		 * Fires before the WCPOS auth template is rendered.
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_auth_template_redirect
		 */
		do_action( 'woocommerce_pos_auth_template_redirect' );

		// Make this instance available to the template.
		global $wcpos_auth_instance; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wcpos_auth_instance = $this; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		if ( ! headers_sent() ) {
			nocache_headers();
		}

		include woocommerce_pos_locate_template( 'auth.php' );
		exit;
	}

	/**
	 * Validate and sanitize redirect URI.
	 *
	 * `http(s)` destinations must sit on a known origin; see `is_allowed_web_destination()`.
	 *
	 * @param string $uri The URI to validate.
	 *
	 * @return string Empty string if invalid.
	 */
	private function validate_redirect_uri( string $uri ): string {
		if ( empty( $uri ) ) {
			return '';
		}

		// Remove control characters.
		$uri = preg_replace( '/[\x00-\x1f\x7f]/', '', $uri );

		// Check if URI starts with an allowed scheme.
		foreach ( self::ALLOWED_SCHEMES as $scheme ) {
			if ( 0 === stripos( $uri, $scheme . '://' ) ) {
				// For http/https, use esc_url for full validation.
				if ( 'http' === $scheme || 'https' === $scheme ) {
					$escaped = esc_url( $uri, array( 'http', 'https' ) );

					return $escaped && $this->is_allowed_web_destination( $escaped ) ? $escaped : '';
				}

				// For custom schemes (wcpos://, exp://), just return it
				// These are app deep links, not web URLs.
				return $uri;
			}
		}

		return '';
	}

	/**
	 * Whether a web redirect destination sits on an origin that may receive tokens.
	 *
	 * Compares scheme, host and effective port — the origin — never the path or
	 * query, and never the request's own Host/Origin/Referer headers, which the
	 * caller controls. A URL with userinfo (`https://site@attacker.example`) is
	 * refused outright because it reads as the site to a person.
	 *
	 * @param string $url The escaped http(s) URL.
	 *
	 * @return bool
	 */
	private function is_allowed_web_destination( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$origin  = $this->origin_from_parts( $parts );
		$allowed = in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true );

		foreach ( array( home_url(), site_url() ) as $site_url ) {
			$site = wp_parse_url( $site_url );
			if ( ! is_array( $site ) || ! in_array( $site['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $site['host'] ) || isset( $site['user'] ) || isset( $site['pass'] ) ) {
				continue;
			}

			if ( $origin === $this->origin_from_parts( $site ) ) {
				$allowed = true;
			}
			if ( 'https' === $parts['scheme'] && 443 === ( $parts['port'] ?? 443 )
				&& 'http' === $site['scheme'] && 80 === ( $site['port'] ?? 80 )
				&& strtolower( $parts['host'] ) === strtolower( $site['host'] ) ) {
				$allowed = true;
			}
		}

		if ( in_array( $origin, self::ALLOWED_WEB_ORIGINS, true ) ) {
			$allowed = true;
		}

		/*
		 * Allow a login redirect to a web origin the plugin does not know.
		 *
		 * Runs after the built-in checks (the site's own origin, the hosted
		 * client, loopback hosts). Return true only for an origin you operate:
		 * whoever receives the redirect receives the cashier's tokens.
		 *
		 * @param bool   $allowed Whether the origin passed the built-in checks.
		 * @param string $origin  `scheme://host[:port]`, lower-cased, default port omitted.
		 * @param string $url     The full escaped destination.
		 *
		 * @return bool
		 *
		 * @since 1.11.1
		 *
		 * @hook woocommerce_pos_auth_redirect_origin_allowed
		 */
		return (bool) apply_filters( 'woocommerce_pos_auth_redirect_origin_allowed', $allowed, $origin, $url );
	}

	/**
	 * `scheme://host[:port]` for parsed URL parts, with the default port omitted.
	 *
	 * @param array $parts Output of wp_parse_url().
	 *
	 * @return string
	 */
	private function origin_from_parts( array $parts ): string {
		$scheme       = strtolower( $parts['scheme'] );
		$default_port = 'https' === $scheme ? 443 : 80;
		$port         = $parts['port'] ?? $default_port;

		return $scheme . '://' . strtolower( $parts['host'] ) . ( $port !== $default_port ? ':' . $port : '' );
	}

	/**
	 * Validate or create auth session to prevent expired/reused auth URLs.
	 *
	 * @return bool
	 */
	private function validate_or_create_auth_session(): bool {
		$session_key = 'wcpos_auth_session_' . md5( $this->state . $this->redirect_uri );

		if ( empty( $this->auth_session ) ) {
			$stored = get_transient( $session_key );
			if ( $stored ) {
				$this->auth_session = $stored;

				return true;
			}

			// First visit - create session.
			$this->auth_session = wp_generate_password( 32, false );
			set_transient( $session_key, $this->auth_session, self::AUTH_SESSION_EXPIRY );

			return true;
		}

		// Validate existing session.
		$stored_session = get_transient( $session_key );

		if ( ! $stored_session ) {
			$this->error = __( 'Auth session expired. Please try logging in again from the app.', 'woocommerce-pos' );

			return false;
		}

		if ( ! hash_equals( $stored_session, $this->auth_session ) ) {
			$this->error = __( 'Invalid auth session. Please try logging in again from the app.', 'woocommerce-pos' );

			return false;
		}

		return true;
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	private function handle_form_submission(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		// Verify nonce for security.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wcpos_auth' ) ) {
			$this->error = __( 'Security check failed. Please try again.', 'woocommerce-pos' );

			return;
		}

		// Check honeypot field (should be empty).
		if ( ! empty( $_POST['wcpos_website'] ?? '' ) ) {
			// Bot detected - silently fail with generic error.
			$this->log_auth_attempt( '', 'honeypot_triggered' );
			sleep( 2 ); // Slow down bots.
			$this->error = /* translators: Short WCPOS UI label; keep concise. */ __( 'Authentication failed.', 'woocommerce-pos' );

			return;
		}

		$username = sanitize_user( wp_unslash( $_POST['wcpos-log'] ?? '' ) );
		$password = isset( $_POST['wcpos-pwd'] ) ? wp_unslash( $_POST['wcpos-pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must not be sanitized before authentication.

		// Check if username is locked out.
		if ( $this->is_username_locked( $username ) ) {
			$this->error = __( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', 'woocommerce-pos' );
			$this->log_auth_attempt( $username, 'locked_out' );

			return;
		}

		// Authenticate user directly (bypasses wp_authenticate filter chain).
		$user = $this->authenticate_direct( $username, $password );

		if ( is_wp_error( $user ) ) {
			$this->record_failed_attempt( $username );
			$this->increment_ip_attempts();
			$this->log_auth_attempt( $username, 'failed', $user->get_error_code() );
			$this->error = $user->get_error_message();

			return;
		}

		// Check if user has access to POS.
		if ( ! Cashier::instance()->can_open_pos( $user ) ) {
			$this->log_auth_attempt( $username, 'no_permission' );
			$this->error = Cashier::instance()->missing_pos_capabilities_message( $user );

			return;
		}

		// Clear failed attempts on successful login.
		$this->clear_failed_attempts( $username );

		// Clean up auth session.
		$session_key = 'wcpos_auth_session_' . md5( $this->state . $this->redirect_uri );
		delete_transient( $session_key );

		// Log successful auth.
		$this->log_auth_attempt( $username, 'success' );

		// Generate JWT token using Services/Auth.
		$auth_service  = AuthService::instance();
		$redirect_data = $auth_service->get_redirect_data( $user );

		if ( empty( $redirect_data ) ) {
			$this->error = __( 'Failed to generate authentication tokens.', 'woocommerce-pos' );

			return;
		}

		// On success, redirect back to app (or fallback to dashboard).
		$redirect_params = array(
			'access_token'  => rawurlencode( $redirect_data['access_token'] ),
			'refresh_token' => rawurlencode( $redirect_data['refresh_token'] ),
			'token_type'    => rawurlencode( $redirect_data['token_type'] ),
			'expires_at'    => \intval( $redirect_data['expires_at'] ),
			'id'            => \intval( $redirect_data['id'] ),
			'uuid'          => rawurlencode( $redirect_data['uuid'] ),
			'display_name'  => rawurlencode( $redirect_data['display_name'] ),
		);

		// Include state parameter if it was provided.
		if ( ! empty( $this->state ) ) {
			$redirect_params['state'] = rawurlencode( $this->state );
		}

		$target = $this->redirect_uri
				? add_query_arg( $redirect_params, $this->redirect_uri )
				: admin_url();

		wp_redirect( $target );
		exit;
	}

	/**
	 * Authenticate user directly, bypassing the authenticate filter chain.
	 *
	 * This intentionally bypasses 2FA, captcha, and other security plugin hooks
	 * because this is a non-interactive authentication flow for mobile/desktop apps.
	 *
	 * Security is maintained through:
	 * - Rate limiting
	 * - Account lockout
	 * - Auth session expiration
	 * - Honeypot fields
	 *
	 * @param string $username The username.
	 * @param string $password The password.
	 *
	 * @return WP_Error|WP_User
	 */
	private function authenticate_direct( string $username, string $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error(
				'empty_credentials',
				__( 'Please enter both username and password.', 'woocommerce-pos' )
			);
		}

		// Get user by login or email.
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			$user = get_user_by( 'email', $username );
		}

		if ( ! $user ) {
			// Use generic message to prevent username enumeration.
			return new WP_Error(
				'invalid_credentials',
				__( 'Invalid username or password.', 'woocommerce-pos' )
			);
		}

		// Check if password is correct.
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			// Use same generic message.
			return new WP_Error(
				'invalid_credentials',
				__( 'Invalid username or password.', 'woocommerce-pos' )
			);
		}

		/*
		 * Allow plugins to block authentication if absolutely necessary.
		 *
		 * This is a WCPOS-specific filter that runs AFTER password validation.
		 * Use this sparingly - the purpose of this auth flow is to bypass
		 * interactive security measures.
		 *
		 * @param WP_Error|WP_User $user     The authenticated user or WP_Error.
		 * @param string           $username The username used.
		 *
		 * @return WP_Error|WP_User
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_authenticate_user
		 */
		return apply_filters( 'woocommerce_pos_authenticate_user', $user, $username );
	}

	/**
	 * Check if IP is rate limited.
	 *
	 * @return bool
	 */
	private function is_ip_rate_limited(): bool {
		$ip            = $this->get_client_ip();
		$transient_key = 'wcpos_auth_ip_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		return $attempts >= self::MAX_ATTEMPTS_PER_IP;
	}

	/**
	 * Increment IP attempt counter.
	 *
	 * @return void
	 */
	private function increment_ip_attempts(): void {
		$ip            = $this->get_client_ip();
		$transient_key = 'wcpos_auth_ip_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		set_transient( $transient_key, $attempts + 1, self::RATE_LIMIT_WINDOW );
	}

	/**
	 * Lockout bucket for an identifier as typed by this peer.
	 *
	 * The bucket is the canonical account (login and email aliases share it)
	 * joined to the connecting peer, so a stranger's failures never lock the
	 * cashier out from their own till, and rotating the typed alias does not
	 * buy a second budget. An identifier that matches no account gets its own
	 * bucket per peer so guessing unknown names is still counted.
	 *
	 * @param string $username The identifier as typed.
	 *
	 * @return string Hash used as the transient key suffix.
	 */
	private function lockout_key( string $username ): string {
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			$user = get_user_by( 'email', $username );
		}
		$subject = $user ? 'user:' . $user->ID : 'input:' . strtolower( trim( $username ) );

		return md5( $subject . '|' . $this->get_client_ip() );
	}

	/**
	 * Check if login is locked out for an account and peer.
	 *
	 * @param string $username The username to check.
	 *
	 * @return bool
	 */
	private function is_username_locked( string $username ): bool {
		if ( empty( $username ) ) {
			return false;
		}

		$username_key  = $this->lockout_key( $username );
		$transient_key = 'wcpos_auth_lock_' . $username_key;

		return false !== get_transient( $transient_key );
	}

	/**
	 * Record a failed login attempt for an account and peer.
	 *
	 * @param string $username The username that failed.
	 *
	 * @return void
	 */
	private function record_failed_attempt( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}

		$username_key = $this->lockout_key( $username );
		$attempts_key = 'wcpos_auth_fail_' . $username_key;
		$attempts     = (int) get_transient( $attempts_key );
		$attempts++;

		set_transient( $attempts_key, $attempts, self::LOCKOUT_DURATION );

		// Lock account after max attempts.
		if ( $attempts >= self::MAX_FAILED_ATTEMPTS ) {
			$lock_key = 'wcpos_auth_lock_' . $username_key;
			set_transient( $lock_key, time(), self::LOCKOUT_DURATION );

			// Log the lockout.
			Logger::log(
				\sprintf(
					'WCPOS Auth: Account locked - username: %s, IP: %s, attempts: %d',
					$username,
					$this->get_client_ip(),
					$attempts
				)
			);
		}
	}

	/**
	 * Clear failed attempts for an account and peer after successful login.
	 *
	 * @param string $username The username to clear.
	 *
	 * @return void
	 */
	private function clear_failed_attempts( string $username ): void {
		if ( empty( $username ) ) {
			return;
		}

		$username_key = $this->lockout_key( $username );
		delete_transient( 'wcpos_auth_fail_' . $username_key );
		delete_transient( 'wcpos_auth_lock_' . $username_key );
	}

	/**
	 * Get the server-established connecting peer address.
	 *
	 * Forwarded headers are caller-controlled unless the host rewrites them,
	 * letting a caller choose its own bucket. A host behind a trusted proxy
	 * normalises REMOTE_ADDR itself (mod_remoteip / real_ip), which is the only
	 * trustworthy place for that policy.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return 'unknown';
	}

	/**
	 * Log authentication attempt.
	 *
	 * @param string $username   The username attempted.
	 * @param string $status     The status: success, failed, rate_limited, locked_out, honeypot_triggered, no_permission.
	 * @param string $error_code Optional error code for failed attempts.
	 *
	 * @return void
	 */
	private function log_auth_attempt( string $username, string $status, string $error_code = '' ): void {
		$log_entry = \sprintf(
			'WCPOS Auth: %s - username: %s, IP: %s, state: %s',
			$status,
			$username ? $username : 'unknown',
			$this->get_client_ip(),
			substr( $this->state, 0, 8 ) . '...' // Truncate state for logs.
		);

		if ( $error_code ) {
			$log_entry .= ', error: ' . $error_code;
		}

		Logger::log( $log_entry );
	}
}
