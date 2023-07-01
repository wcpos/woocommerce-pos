<?php
/**
 * REST Controller.
 *
 * This class extend `WP_REST_Controller`
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * @class   WCPOS_REST_Controller
 *
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 */

namespace WCPOS\WooCommercePOS\API\Abstracts;

use WP_REST_Controller;
use function defined;
use const WCPOS\WooCommercePOS\SHORT_NAME;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Rest Controller Class.
 *
 * @extends  WP_REST_Controller
 */
abstract class Controller extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '';
}
