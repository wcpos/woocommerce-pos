<?php

/**
 * Load required classes
 *
 * @package   WCPOS\WooCommercePOS\Run
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Run {

	/**
	 * Constructor
	 */
	public function __construct() {
		// global helper functions
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';

		// allow requests from wcpos app
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 5, 3 );
		add_action( 'send_headers', array( $this, 'send_headers' ) );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

	}

	/**
	 * Load the required resources
	 */
	public function init() {
		// common classes
		new i18n();
		//      new Gateways();
		//      new Products();
		//      new Customers();
		new Orders();

		// AJAX only
		if ( is_admin() && ( defined( '\DOING_AJAX' ) && \DOING_AJAX ) ) {
			// new AJAX();
		}

		if ( is_admin() && ! ( defined( '\DOING_AJAX' ) && \DOING_AJAX ) ) {
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
	 * Loads the POS API and duck punches the WC REST API
	 */
	public function rest_api_init() {
		if ( woocommerce_pos_request() ) {
			new API();
		}
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
	 * Add Access Control Allow Headers for POS app
	 *
	 * @param \WP_HTTP_Response $result Result to send to the client. Usually a WP_REST_Response.
	 * @param \WP_REST_Server $server Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 *
	 * @return mixed $result
	 */
	public function rest_pre_serve_request( $result, $server, $request ) {
		if ( $request->get_method() == 'OPTIONS' || woocommerce_pos_request() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WCPOS' );
		}

		return $result;
	}

	/**
	 * Allow WP API Discovery from the homepage
	 */
	public function send_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Expose-Headers: Link' );
	}

	/**
	 *
	 */
	public function query_vars( $query_vars ) {
		$query_vars[] = SHORT_NAME;

		return $query_vars;
	}

}
