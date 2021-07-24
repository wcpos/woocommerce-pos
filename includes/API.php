<?php

/**
 * WC REST API Class
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\API\Settings;
use WCPOS\WooCommercePOS\API\Stores;
use WP_REST_Request;
use WP_REST_Server;

class API {
	const REST_NAMESPACE = SHORT_NAME . '/v1/';

	private $wc_rest_api_handler;

	/**
	 * WCPOS REST API namespaces and endpoints.
	 *
	 * @var array
	 */
	protected $controllers = array();

	/**
	 *
	 */
	public function __construct() {
		/**
		 * These filters allow changes to the WC REST API
		 *
		 * note: I needed to init WC API patches earlier than rest_dispatch_request for validation patch
		 */
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 4 );
		add_filter( 'rest_endpoints', array( $this, 'rest_endpoints' ), 99, 1 );

		/**
		 * Init and register routes for the WCPOS REST API
		 */
		$this->init();
	}

	/**
	 * Init and register routes for the WCPOS REST API
	 */
	public function init() {
		$this->controllers = array(
//			'auth' => new Auth(),
			'settings' => new Settings(),
			'stores'   => new Stores(),
		);

		foreach ( $this->controllers as $key => $controller_class ) {
			$controller_class->register_routes();
		}



		// Stores


		// Settings

	}

	/**
	 * Filters the pre-calculated result of a REST API dispatch request.
	 *
	 * Allow hijacking the request before dispatching by returning a non-empty. The returned value
	 * will be used to serve the request instead.
	 *
	 * @param mixed $result Response to replace the requested version with. Can be anything
	 *                                 a normal endpoint can return, or null to not hijack the request.
	 * @param WP_REST_Server $server Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return mixed
	 */
	public function rest_pre_dispatch( $result, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/v3/orders' ) ) {
			$this->wc_rest_api_handler = new API\Orders( $request );
		}
		if ( 0 === strpos( $request->get_route(), '/wc/v3/products' ) ) {
			$this->wc_rest_api_handler = new API\Products( $request );
		}
		if ( 0 === strpos( $request->get_route(), '/wc/v3/customers' ) ) {
			$this->wc_rest_api_handler = new API\Customers( $request );
		}

		return $result;
	}

	/**
	 * Filters the REST API dispatch request result.
	 *
	 * @param mixed $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @param string $route Route matched for the request.
	 * @param array $handler Route handler used for the request.
	 *
	 * @return mixed
	 */
	public function rest_dispatch_request( $dispatch_result, $request, $route, $handler ) {
		$params = $request->get_params();

		if ( isset( $params['posts_per_page'] ) && - 1 == $params['posts_per_page'] && isset( $params['fields'] ) ) {
			if ( $this->wc_rest_api_handler ) {
				$dispatch_result = $this->wc_rest_api_handler->get_all_posts( $params['fields'] );
			}
		}

		return $dispatch_result;
	}

	/**
	 * Filters the array of available REST API endpoints.
	 *
	 * @param array $endpoints The available endpoints. An array of matching regex patterns, each mapped
	 *                         to an array of callbacks for the endpoint. These take the format
	 *                         `'/path/regex' => array( $callback, $bitmask )` or
	 *                         `'/path/regex' => array( array( $callback, $bitmask ).
	 *
	 * @return array
	 *
	 */
	public function rest_endpoints( array $endpoints ): array {

		// add ordering by meta_value to customers endpoint
		if ( isset( $endpoints['/wc/v3/customers'] ) ) {
			$endpoint = $endpoints['/wc/v3/customers'];

			// allow ordering by meta_value
			$endpoint[0]['args']['orderby']['enum'][] = 'meta_value';

			// add valid meta_key
			$endpoint[0]['args']['meta_key'] = array(
				'description'       => 'The meta key to query',
				'type'              => 'string',
				'enum'              => array( 'first_name', 'last_name', 'email' ),
				'validate_callback' => 'rest_validate_request_arg',
			);
		}

		return $endpoints;
	}

}
