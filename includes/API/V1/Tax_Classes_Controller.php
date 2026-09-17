<?php
/**
 * REST API Tax Classes controller class.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Tax_Classes_Controller' ) ) {
	return;
}

use WCPOS\WooCommercePOS\Services\Permission_Rules;
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

	/** Delegate the read decision, preserving WooCommerce's request-dependent checks.
	 *
	 * @param \WP_REST_Request $request Full request details.
	 */
	public function get_items_permissions_check( $request ) {
		return Permission_Rules::verdict( 'tax_classes', 'read', (int) $request['id'], 0, 'v1', $request->get_params() );
	}
}
