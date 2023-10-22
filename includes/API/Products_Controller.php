<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Products_Controller') ) {
	return;
}

use WC_REST_Products_Controller;

class Products_Controller extends WC_REST_Products_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';
}
