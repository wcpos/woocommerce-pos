<?php

/**
 * Load required classes
 *
 * @package   WCPOS\WooCommercePOS\Init
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;
use const DOING_AJAX;

class Init {

	/**
	 * Constructor
	 */
	public function __construct() {
		// global helper functions
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-form-handlers.php';

		/**
		 * Init hooks
		 */
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		/**
		 * Headers for API discoverability
		 */
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 5, 4 );
		add_action( 'send_headers', array( $this, 'send_headers' ), 10, 1 );
	}

	/**
	 * Load the required resources
	 */
	public function init() {
		// common classes
		new i18n();
		new Gateways();
		new Products();
		//      new Customers();
		new Orders();

		// AJAX only
		if ( is_admin() && ( defined( '\DOING_AJAX' ) && DOING_AJAX ) ) {
			// new AJAX();
		}

		if ( is_admin() && ! ( defined( '\DOING_AJAX' ) && DOING_AJAX ) ) {
			// admin only
			new Admin();
		} else {
			// frontend only
			new Templates();
		}

		// load integrations
		$this->integrations();
	}

	/**
	 * Loads POS integrations with third party plugins
	 */
	private function integrations() {
		//      // WooCommerce Bookings - http://www.woothemes.com/products/woocommerce-bookings/
		//      if ( class_exists( 'WC-Bookings' ) ) {
		//          new Integrations\Bookings();
		//      }
	}

	/**
	 * Loads the POS API and duck punches the WC REST API
	 */
	public function rest_api_init() {
		if ( woocommerce_pos_request() ) {
			new API();
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
	 */
	public function query_vars( array $query_vars ): array {
		$query_vars[] = SHORT_NAME;

		return $query_vars;
	}

	/**
	 * Allow pre-flight requests from WCPOS Desktop and Mobile Apps
	 * Note: pre-flight requests cannot have headers, so I can't filter by pos request
	 * See: https://fetch.spec.whatwg.org/#cors-preflight-fetch
	 *
	 * @param bool $served Whether the request has already been served.
	 *                                           Default false.
	 * @param WP_HTTP_Response $result Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @param WP_REST_Server $server Server instance.
	 *
	 * @return bool $served
	 */
	public function rest_pre_serve_request( bool $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		if ( $request->get_method() == 'OPTIONS' ) {
			$server->send_header( 'Access-Control-Allow-Origin', '*' );
			$server->send_header( 'Access-Control-Allow-Headers', 'Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type, X-WCPOS' );
		}

		return $served;
	}

	/**
	 * Allow HEAD checks for WP API Link URL and server uptime
	 * Fires once the requested HTTP headers for caching, content type, etc. have been sent.
	 */
	public function send_headers() {
		// some server convert HEAD to GET method, so use this query param instead
		if ( isset( $_GET['wcpos_http_method'] ) && $_GET['wcpos_http_method'] == 'head' ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Expose-Headers: Link' );
		}
	}

}
