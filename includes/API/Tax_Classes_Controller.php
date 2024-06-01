<?php
/**
 * REST API Tax Classes controller class.
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
}
