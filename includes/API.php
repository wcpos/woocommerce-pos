<?php

/**
 * REST API Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Services\Auth;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class API {
	/**
	 * WCPOS REST API namespaces and endpoints.
	 *
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * @var
	 */
	protected $wc_rest_api_handler;

	/**
	 * @var bool
	 */
	protected $is_auth_checked = false;


	public function __construct() {
		// Init and register routes for the WCPOS REST API
		$this->controllers = array(
			// woocommerce pos rest api controllers
			'auth'                  => new API\Auth(),
			'settings'              => new API\Settings(),
			'stores'                => new API\Stores(),

			// extend WC REST API controllers
			'products'              => new API\Products_Controller(),
			'product_variations'    => new API\Product_Variations_Controller(),
			'orders'                => new API\Orders_Controller(),
			'customers'             => new API\Customers_Controller(),
			'product_tags'          => new API\Product_Tags_Controller(),
			'product_categories'    => new API\Product_Categories_Controller(),
			'taxes'                 => new API\Taxes_Controller(),
		);

		foreach ( $this->controllers as $key => $controller_class ) {
			$controller_class->register_routes();
		}

		// Allows requests from WCPOS Desktop and Mobile Apps
		add_filter( 'rest_allowed_cors_headers', array( $this, 'rest_allowed_cors_headers' ), 10, 1 );
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 10, 4 );

		/*
		 * Adds authentication to for JWT bearer tokens
		 * - We run determine_current_user at 20 to allow other plugins to run first
		 */
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 50, 1 );

		// Adds uuid for the WordPress install
		add_filter( 'rest_index', array( $this, 'rest_index' ), 10, 1 );

		// These filters allow changes to the WC REST API response
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );

		$this->prevent_messages();
	}

	/**
	 * Add CORS headers to the REST API response.
	 *
	 * @param string[] $allow_headers The list of request headers to allow.
	 *
	 * @return string[] $allow_headers
	 */
	public function rest_allowed_cors_headers( array $allow_headers ): array {
		$allow_headers[] = 'X-WCPOS';
		$allow_headers[] = 'X-HTTP-Method-Override';

		return $allow_headers;
	}

	/**
	 * Add Access Control Allow Headers for POS app.
	 *
	 * NOTE: I have seen this filter called with NULL for $served, which is not expected
	 *
	 * @param mixed            $served  Whether the request has already been served.
	 *                                  Default false.
	 * @param WP_HTTP_Response $result  Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 *
	 * @return bool $served
	 */
	public function rest_pre_serve_request( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		// Check if served is a boolean
		if ( ! \is_bool( $served ) ) {
			Logger::log( "Warning: 'rest_pre_serve_request' filter received a non-boolean value for 'served'. Defaulting to 'false'." );
			$served = false; // Default value if not provided correctly
		}

		$server->send_header( 'Access-Control-Allow-Origin', '*' );

		return $served;
	}

	/**
	 * Check request for any login tokens.
	 *
	 * @param false|int $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return false|int|void
	 */
	public function determine_current_user( $user_id ) {
		$this->is_auth_checked = true;
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		return $this->authenticate( $user_id );
	}

	/**
	 * It's possible that the determine_current_user filter above is not called
	 * https://github.com/woocommerce/woocommerce/issues/26847.
	 *
	 * We need to make sure our
	 *
	 * @param mixed $errors
	 */
	public function rest_authentication_errors( $errors ) {
		// Pass through other errors
		if ( ! empty( $error ) ) {
			return $error;
		}

		// check if determine_current_user has been called
		if ( ! $this->is_auth_checked ) {
			// Authentication hasn't occurred during `determine_current_user`, so check auth.
			$user_id = $this->authenticate( false );
			if ( $user_id ) {
				wp_set_current_user( $user_id );

				return true;
			}
		}

		return $errors;
	}

	/**
	 * Extract the Authorization Bearer token from the request.
	 *
	 * @return string|false
	 */
	public function get_auth_header() {
		// Check if HTTP_AUTHORIZATION is set in $_SERVER
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		// Check for alternative header in $_SERVER
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		// Check for authorization param in URL ($_GET)
		if ( isset( $_GET['authorization'] ) ) {
			return sanitize_text_field( $_GET['authorization'] );
		}

		// Return false if none of the variables are set
		return false;
	}

	/**
	 * Add uuid to the WP REST API index.
	 *
	 * @param WP_REST_Response $response Response data
	 *
	 * @return WP_REST_Response
	 */
	public function rest_index( WP_REST_Response $response ): WP_REST_Response {
		$uuid = get_option( 'woocommerce_pos_uuid' );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			update_option( 'woocommerce_pos_uuid', $uuid );
		}
		$response->data['uuid'] = $uuid;

		return $response;
	}

	/**
	 * Filters the pre-calculated result of a REST API dispatch request.
	 *
	 * Allow hijacking the request before dispatching by returning a non-empty. The returned value
	 * will be used to serve the request instead.
	 *
	 * @param mixed           $result  Response to replace the requested version with. Can be anything
	 *                                 a normal endpoint can return, or null to not hijack the request.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return mixed
	 */
	public function rest_pre_dispatch( $result, $server, $request ) {
		// Get 'include' parameter from request
		$include = $request->get_param( 'include' );

		if ( $include ) {
			// Convert to array if it's not
			$include_array  = \is_array( $include ) ? $include : explode( ',', $include );
			$include_string = implode( ',', $include_array );

			// If the length of the 'include' string exceeds 10,000 characters, create a new array
			if ( \strlen( $include_string ) > 10000 ) {
				shuffle( $include_array ); // Shuffle the IDs to randomize

				// Construct a random array of no more than 10,000 characters
				$max_include_length   = 10000;
				$new_include_string   = '';
				$random_include_array = array();

				foreach ( $include_array as $id ) {
					if ( \strlen( $new_include_string . $id ) < $max_include_length ) {
						$new_include_string .= $id . ',';
						$random_include_array[] = $id;
					} else {
						break; // Stop when we reach the maximum length
					}
				}

				// Set modified 'include' parameter back to request
				$request->set_param( 'include', $random_include_array );
			}
		}

		return $result;
	}

	/**
	 * Filters the REST API dispatch request result.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 *
	 * @return mixed
	 */
	public function rest_dispatch_request( $dispatch_result, $request, $route, $handler ) {
		if ( isset( $handler['callback'] ) && \is_array( $handler['callback'] ) && isset( $handler['callback'][0] ) ) {
			/*
			 * If $handler['callback'][0] matches one of our controllers we add a filter for $dispatch_result.
			 * This allows us to conditionally init woocommerce hooks in the controller.
			 */
			foreach ( $this->controllers as $key => $controller_class ) {
				if ( $handler['callback'][0] === $controller_class ) {
					/**
					 * Filters the dispatch result for a request.
					 *
					 * The dynamic portion of the hook name, `$key`, refers to the identifier of the controller.
					 *
					 * @since 1.4.0
					 *
					 * @param mixed           $dispatch_result The dispatch result.
					 * @param WP_REST_Request $request         The request instance.
					 * @param string          $route           The route being dispatched.
					 * @param array           $handler         The handler for the route.
					 */
					$dispatch_result = apply_filters( "woocommerce_pos_rest_dispatch_{$key}_request", $dispatch_result, $request, $route, $handler );

					break;
				}
			}
		}

		return $dispatch_result;
	}

	/**
	 * Error messages and notices can cause the JSON response to fail.
	 */
	private function prevent_messages(): void {
		error_reporting( 0 );
		@ini_set( 'display_errors', 0 );
	}

	/**
	 * @param false|int $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return int|WP_Error
	 */
	private function authenticate( $user_id ) {
		// check if there is an auth header
		$auth_header = $this->get_auth_header();
		if ( ! is_string( $auth_header ) ) {
			return $user_id;
		}

		// Extract Bearer token from Authorization Header
		list($token) = sscanf( $auth_header, 'Bearer %s' );

		if ( $token ) {
			$auth_service = Auth::instance();
			$decoded_token = $auth_service->validate_token( $token );

			// Check if validate_token returned WP_Error and user_id is null
			if ( is_wp_error( $decoded_token ) && $user_id === null ) {
					return $decoded_token;
			}

			// If the token is valid, set the user_id
			if ( ! is_wp_error( $decoded_token ) ) {
				$user_id = $decoded_token->data->user->id;
				return absint( $user_id );
			}
		}

		return $user_id;
	}
}
