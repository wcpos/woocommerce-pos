<?php
/**
 * Load required classes.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WCPOS\WooCommercePOS\Services\Extensions;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;
use const DOING_AJAX;

/**
 * Init class.
 */
class Init {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// global helper functions.
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-store-functions.php';

		// Init hooks.
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Headers for API discoverability.
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 5, 4 );
		add_action( 'send_headers', array( $this, 'send_headers' ), 99, 1 );
		add_action( 'send_headers', array( $this, 'remove_x_frame_options' ), 9999, 1 );

		/*
		 * Add JWT authentication filter.
		 *
		 * Hook order: plugins_loaded -> init (determine_current_user) -> rest_api_init
		 *
		 * This filter runs at priority 20 (after WordPress core's cookie auth at priority 10).
		 * It must be registered here (during plugins_loaded) because determine_current_user
		 * fires during 'init', which is BEFORE rest_api_init where our API class loads.
		 */
		add_filter( 'determine_current_user', array( $this, 'determine_current_user_early' ), 20 );
	}

	/**
	 * Early authentication check for JWT tokens.
	 *
	 * This runs BEFORE rest_api_init, so we can authenticate users before WP REST API
	 * permission callbacks run. This is especially important for authorization via
	 * query parameter (?authorization=Bearer...) which some servers require.
	 *
	 * Note: We don't check for X-WCPOS header here because:
	 * 1. The header check uses getallheaders() which may not work in all environments
	 * 2. JWT authentication should work regardless - the token itself is proof of WCPOS usage
	 * 3. Invalid tokens (non-WCPOS) will fail validation anyway
	 *
	 * @param false|int $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return false|int User ID if authenticated, original value otherwise.
	 */
	public function determine_current_user_early( $user_id ) {
		// Skip if user already authenticated.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		// Check for authorization token (header or param).
		$auth_header = $this->get_auth_header_early();
		if ( ! \is_string( $auth_header ) || empty( $auth_header ) ) {
			return $user_id;
		}

		// Extract Bearer token.
		list( $token ) = sscanf( $auth_header, 'Bearer %s' );
		if ( ! $token ) {
			return $user_id;
		}

		// Validate token - this will fail for non-WCPOS tokens.
		$auth_service  = AuthService::instance();
		$decoded_token = $auth_service->validate_token( $token );

		if ( is_wp_error( $decoded_token ) ) {
			return $user_id;
		}

		// Return the authenticated user ID.
		return absint( $decoded_token->data->user->id );
	}

	/**
	 * Load the required resources.
	 */
	public function init(): void {
		$this->init_common();
		$this->init_frontend();
		$this->init_admin();
		$this->init_integrations();
	}

	/**
	 * Loads the POS API and duck punches the WC REST API.
	 */
	public function init_rest_api(): void {
		if ( woocommerce_pos_request() ) {
			new API();
		} else {
			new WC_API();
		}
	}

	/**
	 * Adds 'wcpos' to the query variables allowed before processing.
	 *
	 * Allows (publicly allowed) query vars to be added, removed, or changed prior
	 * to executing the query. Needed to allow custom rewrite rules using your own arguments
	 * to work, or any other custom query variables you want to be publicly available.
	 *
	 * @param string[] $query_vars The array of allowed query variable names.
	 *
	 * @return string[] The array of allowed query variable names.
	 */
	public function query_vars( array $query_vars ): array {
		$query_vars[] = SHORT_NAME;

		return $query_vars;
	}

	/**
	 * Allow pre-flight requests from WCPOS Desktop and Mobile Apps
	 * Note: pre-flight requests cannot have headers, so I can't filter by pos request
	 * See: https://fetch.spec.whatwg.org/#cors-preflight-fetch.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 *                                  Default false.
	 * @param WP_HTTP_Response $result  Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 *
	 * @return bool $served
	 */
	public function rest_pre_serve_request( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
		if ( 'OPTIONS' == $request->get_method() ) {
			$allow_headers = array(
				'Authorization',            // For user-agent authentication with a server.
				'X-WP-Nonce',               // WordPress-specific header, used for CSRF protection.
				'Content-Disposition',      // Informs how to process the response data.
				'Content-MD5',              // For verifying data integrity.
				'Content-Type',             // Specifies the media type of the resource.
				'X-HTTP-Method-Override',   // Used to override the HTTP method.
				'X-WCPOS',                  // Used to identify WCPOS requests.
			);

			$server->send_header( 'Access-Control-Allow-Origin', '*' );
			$server->send_header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE' );
			$server->send_header( 'Access-Control-Allow-Headers', implode( ', ', $allow_headers ) );
		}

		return $served;
	}

	/**
	 * Allow HEAD checks for WP API Link URL and server uptime
	 * Fires once the requested HTTP headers for caching, content type, etc. have been sent.
	 *
	 * FIXME: Why is Link header not exposed sometimes on my development machine?
	 *
	 * @return void
	 */
	public function send_headers(): void {
		// some server convert HEAD to GET method, so use this query param instead.
		if ( isset( $_GET['_method'] ) && 'head' === strtolower( sanitize_text_field( wp_unslash( $_GET['_method'] ) ) ) ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Expose-Headers: Link' );
		}
	}

	/**
	 * Some security plugins will set X-Frame-Options: SAMEORIGIN/DENY, which will prevent the POS desktop
	 * application from opening pages like the login in an iframe.
	 *
	 * For pages we need, we will remove the X-Frame-Options header.
	 *
	 * @param mixed $wp The WP object.
	 *
	 * @return void
	 */
	public function remove_x_frame_options( $wp ): void {
		if ( woocommerce_pos_request() || isset( $wp->query_vars['wcpos-login'] ) ) {
			if ( ! headers_sent() && \function_exists( 'header_remove' ) ) {
				header_remove( 'X-Frame-Options' );
			}
		}
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
	private function get_auth_header_early() {
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
	 * Common initializations.
	 */
	private function init_common(): void {
		// init the Services.
		SettingsService::instance();
		AuthService::instance();
		Extensions::instance();

		// init other functionality needed by both frontend and admin.
		new i18n();
		new Gateways();
		new Products();
		new Orders();
		new Emails();
		new Templates();
	}

	/**
	 * Frontend specific initializations.
	 */
	private function init_frontend(): void {
		if ( ! is_admin() ) {
			new Template_Router();
			new Form_Handler();
		}
	}

	/**
	 * Admin specific initializations.
	 */
	private function init_admin(): void {
		if ( is_admin() ) {
			if ( \defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				new AJAX();
			} else {
				new Admin();
			}
		}
	}

	/**
	 * Integrations.
	 */
	private function init_integrations(): void {
		// WooCommerce Bookings - http://www.woothemes.com/products/woocommerce-bookings/
		// if ( class_exists( 'WC-Bookings' ) ) {
		// new Integrations\Bookings();
		// }.

		// Yoast SEO - https://wordpress.org/plugins/wordpress-seo/.
		if ( class_exists( 'WPSEO_Options' ) ) {
			new Integrations\WPSEO();
		}

		// wePOS alters the WooCommerce REST API, breaking the expected schema
		// It's very bad form on their part, but we need to work around it.
		new Integrations\WePOS();
	}
}
