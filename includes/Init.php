<?php

/**
 * Load required classes.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package   WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use const DOING_AJAX;

use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 *
 */
class Init {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// global helper functions
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-store-functions.php';

		// Init hooks
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Headers for API discoverability
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 5, 4 );
		add_action( 'send_headers', array( $this, 'send_headers' ), 99, 1 );
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
	 * Common initializations
	 */
	private function init_common() {
		// init the Services
		SettingsService::instance();
		AuthService::instance();

		// init other functionality needed by both frontend and admin
		new i18n();
		new Gateways();
		new Products();
		new Orders();
	}

	/**
	 * Frontend specific initializations
	 */
	private function init_frontend() {
		if ( ! is_admin() ) {
				new Templates();
				new Form_Handler();
		}
	}

	/**
	 * Admin specific initializations
	 */
	private function init_admin() {
		if ( is_admin() ) {
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
					new AJAX();
			} else {
					new Admin();
			}
		}
	}

	/**
	 * Integrations
	 */
	private function init_integrations() {
		// WooCommerce Bookings - http://www.woothemes.com/products/woocommerce-bookings/
		// if ( class_exists( 'WC-Bookings' ) ) {
		// new Integrations\Bookings();
		// }

		// Yoast SEO - https://wordpress.org/plugins/wordpress-seo/
		if ( class_exists( 'WPSEO_Options' ) ) {
			new Integrations\WPSEO();
		}
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
		// some server convert HEAD to GET method, so use this query param instead
		if ( isset( $_GET['_method'] ) && 'head' == strtolower( $_GET['_method'] ) ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Expose-Headers: Link' );
		}
	}
}
