<?php
/**
 * REST API WC Shipping Methods controller
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Shipping_Methods_Controller' ) ) {
	return;
}

use WC_REST_Shipping_Methods_Controller;

/**
 * Shipping methods controller class.
 */
class Shipping_Methods_Controller extends WC_REST_Shipping_Methods_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Check whether a given request has permission to view shipping methods.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}
		return parent::get_items_permissions_check( $request );
	}
}
