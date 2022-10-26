<?php

/**
 * Load required classes
 *
 * @package   WCPOS\WooCommercePOS\Init
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use const DOING_AJAX;

class Init {

	/**
	 * Constructor
	 */
	public function __construct() {
		// global helper functions
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-form-handlers.php';

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
	 *
	 */
	public function query_vars( $query_vars ) {
		$query_vars[] = SHORT_NAME;

		return $query_vars;
	}

}
