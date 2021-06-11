<?php

/**
 * WC REST API Class
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WP_REST_Request;
use WP_REST_Server;

class API {

	private $handler;
	const REST_NAMESPACE = SHORT_NAME . '/v1/';


	/**
	 *
	 */
	public function __construct() {
//		add_filter( 'rest_index', array( $this, 'rest_index' ) );
		// note: I needed to init WC API patches earlier than rest_dispatch_request for validation patch
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 4 );

		$this->init();
	}

	/**
	 *
	 */
	public function init() {

		// Validate JWT token
		register_rest_route( self::REST_NAMESPACE, '/jwt/authorize', array(
			'methods'             => 'POST',
			'callback'            => array( new Auth\JWT(), 'generate_token' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'username' => array(
					/* translators: WordPress */
					'description' => __( 'Username', 'wordpress' ),
					'type'        => 'string',
				),
				'password' => array(
					/* translators: WordPress */
					'description' => __( 'Password', 'wordpress' ),
					'type'        => 'string',
				),
			),
		) );

		// Validate JWT token
		register_rest_route( self::REST_NAMESPACE, '/jwt/validate', array(
			'methods'             => 'POST',
			'callback'            => array( new Auth\JWT(), 'validate_token' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'jwt' => array(
					'description' => __( 'JWT token.', PLUGIN_NAME ),
					'type'        => 'string',
				)
			),
		) );

		// Refresh JWT token
		register_rest_route( self::REST_NAMESPACE, '/jwt/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( new Auth\JWT(), 'refresh_token' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'jwt' => array(
					'description' => __( 'JWT token.', PLUGIN_NAME ),
					'type'        => 'string',
				)
			),
		) );

		// Revoke JWT token
		register_rest_route( self::REST_NAMESPACE, '/jwt/revoke', array(
			'methods'             => 'POST',
			'callback'            => array( new Auth\JWT(), 'revoke_token' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'jwt' => array(
					'description' => __( 'JWT token.', PLUGIN_NAME ),
					'type'        => 'string',
				)
			),
		) );
	}

	/**
	 * @param mixed $result Response to replace the requested version with. Can be anything
	 *                                 a normal endpoint can return, or null to not hijack the request.
	 * @param WP_REST_Server $server Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 */
	public function rest_pre_dispatch( $result, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/v3/orders' ) ) {
			$this->handler = new API\Orders( $request );
		}
		if ( 0 === strpos( $request->get_route(), '/wc/v3/products' ) ) {
			$this->handler = new API\Products( $request );
		}

		return $result;
	}

	/**
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
			if ( $this->handler ) {
				$dispatch_result = $this->handler->get_all_posts( $params['fields'] );
			}
		}

		return $dispatch_result;
	}

}
