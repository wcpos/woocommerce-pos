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
}
