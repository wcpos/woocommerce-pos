<?php
/**
 * Session Context.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Immutable snapshot of the request state a login session is recorded against.
 *
 * The Auth service used to read `$_SERVER` and `$_REQUEST` inline while storing
 * a refresh token, which made the device-metadata rules impossible to test
 * without mutating superglobals. This value object is the single place those
 * reads happen: `from_request()` captures them, everything downstream takes a
 * `Session_Context` and can be handed a hand-built one in tests.
 *
 * The reads in `from_request()` are a faithful port of the previous inline
 * code, superglobal for superglobal, including the use of `$_REQUEST` rather
 * than `$_GET` — the native apps post their device metadata as part of the auth
 * flow, so narrowing the source would change behaviour.
 */
class Session_Context {
	/**
	 * The raw (sanitized) user agent string.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * Explicit platform declaration from a native app: ios, android, electron or web.
	 *
	 * @var string
	 */
	private $platform;

	/**
	 * Explicit app version declaration from a native app.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Explicit build number declaration from a native app.
	 *
	 * @var string
	 */
	private $build;

	/**
	 * The client IP address, or an empty string when it could not be determined.
	 *
	 * @var string
	 */
	private $ip;

	/**
	 * Constructor.
	 *
	 * @param string $user_agent The user agent string.
	 * @param string $platform   Explicit platform declaration.
	 * @param string $version    Explicit app version declaration.
	 * @param string $build      Explicit build number declaration.
	 * @param string $ip         The client IP address.
	 */
	public function __construct(
		string $user_agent = '',
		string $platform = '',
		string $version = '',
		string $build = '',
		string $ip = ''
	) {
		$this->user_agent = $user_agent;
		$this->platform   = $platform;
		$this->version    = $version;
		$this->build      = $build;
		$this->ip         = $ip;
	}

	/**
	 * Capture the session context from the current request.
	 *
	 * Both arrays default to the corresponding superglobal. They are only
	 * parameters so tests can supply request state without mutating globals;
	 * production callers pass nothing.
	 *
	 * @param null|array $server  Server/header state, defaults to $_SERVER.
	 * @param null|array $request Request parameters, defaults to $_REQUEST.
	 *
	 * @return self
	 */
	public static function from_request( ?array $server = null, ?array $request = null ): self {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only capture of client-declared device metadata; the request itself is authenticated by the auth flow.
		$server  = null === $server ? $_SERVER : $server;
		$request = null === $request ? $_REQUEST : $request;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$user_agent = isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $server['HTTP_USER_AGENT'] ) ) : '';

		// Explicit platform declaration from native apps (passed as a param in the auth request).
		$platform = isset( $request['platform'] ) ? sanitize_text_field( wp_unslash( $request['platform'] ) ) : '';
		$version  = isset( $request['version'] ) ? sanitize_text_field( wp_unslash( $request['version'] ) ) : '';
		$build    = isset( $request['build'] ) ? sanitize_text_field( wp_unslash( $request['build'] ) ) : '';

		return new self( $user_agent, $platform, $version, $build, self::client_ip_from_request( $server ) );
	}

	/**
	 * Read the client IP address from the current request.
	 *
	 * Proxy headers are checked in a fixed precedence: Cloudflare, then
	 * X-Forwarded-For, then X-Real-IP, then the raw remote address. The first
	 * header that is present wins, and a comma-separated list is reduced to its
	 * first entry. Anything that does not validate as an IP becomes ''.
	 *
	 * @param null|array $server Server/header state, defaults to $_SERVER.
	 *
	 * @return string
	 */
	public static function client_ip_from_request( ?array $server = null ): string {
		$server     = null === $server ? $_SERVER : $server;
		$ip_address = '';

		// Check for various proxy headers.
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $server[ $header ] ) ) {
				$ip_address = sanitize_text_field( wp_unslash( $server[ $header ] ) );
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
	 * Get the user agent string.
	 *
	 * @return string
	 */
	public function get_user_agent(): string {
		return $this->user_agent;
	}

	/**
	 * Get the explicit platform declaration.
	 *
	 * @return string
	 */
	public function get_platform(): string {
		return $this->platform;
	}

	/**
	 * Get the explicit app version declaration.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Get the explicit build number declaration.
	 *
	 * @return string
	 */
	public function get_build(): string {
		return $this->build;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	public function get_ip(): string {
		return $this->ip;
	}
}
