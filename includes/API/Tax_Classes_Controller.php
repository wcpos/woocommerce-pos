<?php
/**
 * REST API Tax Classes controller class.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Tax_Classes_Controller' ) ) {
	return;
}

use WC_REST_Tax_Classes_Controller;

/**
 * REST API Tax Classes controller class.
 */
class Tax_Classes_Controller extends WC_REST_Tax_Classes_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Check whether a given request has permission to view tax classes.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( current_user_can( 'access_woocommerce_pos' ) ) {
			return true;
		}
		return parent::get_items_permissions_check( $request );
	}
}
