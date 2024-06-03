<?php
/**
 * WooCommerce POS REST API Class, ie: /wcpos/v1/ endpoints.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Services\Auth;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;


/**
 *
 */
class API {
	/**
	 * WCPOS REST API namespaces and endpoints.
	 *
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * Flag to check if authentication has been checked.
	 *
	 * @var bool
	 */
	protected $is_auth_checked = false;


	public function __construct() {
		$this->register_routes();

		// Allows requests from WCPOS Desktop and Mobile Apps
		add_filter( 'rest_allowed_cors_headers', array( $this, 'rest_allowed_cors_headers' ), 10, 1 );
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 10, 4 );

		/*
		 * Adds authentication to for JWT bearer tokens
		 * - We run determine_current_user at 20 to allow other plugins to run first
		 */
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 50, 1 );

		// Adds info about the WordPress install
		add_filter( 'rest_index', array( $this, 'rest_index' ), 10, 1 );

		// These filters allow changes to the WC REST API response
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );
	}

	/**
	 * Register routes for all controllers.
	 */
	public function register_routes() {
		/**
		 * Filter the list of controller classes used in the WooCommerce POS REST API.
		 *
		 * This filter allows customizing or extending the set of controller classes that handle
		 * REST API routes for the WooCommerce POS. By filtering these controllers, plugins can
		 * modify existing endpoints or add new controllers for additional functionality.
		 *
		 * @since 1.5.0
		 *
		 * @param array $controllers Associative array of controller identifiers to their corresponding class names.
		 *        - 'auth'                  => Fully qualified name of the class handling authentication.
		 *        - 'settings'              => Fully qualified name of the class handling settings.
		 *        - 'stores'                => Fully qualified name of the class handling stores management.
		 *        - 'products'              => Fully qualified name of the class handling products.
		 *        - 'product_variations'    => Fully qualified name of the class handling product variations.
		 *        - 'orders'                => Fully qualified name of the class handling orders.
		 *        - 'customers'             => Fully qualified name of the class handling customers.
		 *        - 'product_tags'          => Fully qualified name of the class handling product tags.
		 *        - 'product_categories'    => Fully qualified name of the class handling product categories.
		 *        - 'taxes'                 => Fully qualified name of the class handling taxes.
		 *        - 'shipping_methods'      => Fully qualified name of the class handling shipping methods.
		 *        - 'tax_classes'           => Fully qualified name of the class handling tax classes.
		 *        - 'order_statuses'        => Fully qualified name of the class handling order statuses.
		 */
		$classes = apply_filters(
			'woocommerce_pos_rest_api_controllers',
			array(
				// woocommerce pos rest api controllers.
				'auth'                  => API\Auth::class,
				'settings'              => API\Settings::class,
				'stores'                => API\Stores::class,

				// extend WC REST API controllers.
				'products'              => API\Products_Controller::class,
				'product_variations'    => API\Product_Variations_Controller::class,
				'orders'                => API\Orders_Controller::class,
				'customers'             => API\Customers_Controller::class,
				'product_tags'          => API\Product_Tags_Controller::class,
				'product_categories'    => API\Product_Categories_Controller::class,
				'taxes'                 => API\Taxes_Controller::class,
				'shipping_methods'      => API\Shipping_Methods_Controller::class,
				'tax_classes'           => API\Tax_Classes_Controller::class,
				'order_statuses'        => API\Data_Order_Statuses_Controller::class,
			)
		);

		foreach ( $classes as $key => $class ) {
			if ( class_exists( $class ) ) {
				$this->controllers[ $key ] = new $class();
				$this->controllers[ $key ]->register_routes();
			}
		}
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
	 * NOTE: I have seen this filter called with NULL for $served, it should be a boolean.
	 *
	 * @param mixed            $served  Whether the request has already been served.
	 *                                  Default false.
	 * @param WP_HTTP_Response $result  Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 *
	 * @return bool $served
	 */
	public function rest_pre_serve_request( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
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
	 * Adds info to the WP REST API index response.
	 * - UUID
	 * - Version Info
	 *
	 * @param WP_REST_Response $response Response data.
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
		$response->data['wp_version'] = get_bloginfo( 'version' );
		$response->data['wc_version'] = WC()->version;
		$response->data['wcpos_version'] = VERSION;

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
		$max_length = 10000;

		// Process 'include' parameter
		$include = $request->get_param( 'include' );
		if ( $include ) {
			$processed_include = $this->shorten_param_array( $include, $max_length );
			$request->set_param( 'wcpos_include', $processed_include );
			unset( $request['include'] );
		}

		// Process 'exclude' parameter
		$exclude = $request->get_param( 'exclude' );
		if ( $exclude ) {
			$processed_exclude = $this->shorten_param_array( $exclude, $max_length );
			$request->set_param( 'wcpos_exclude', $processed_exclude );
			unset( $request['exclude'] );
		}

		return $result;
	}

	/**
	 * Some servers have a limit on the number of include/exclude we can use in a request.
	 * Worst thing is there is often no error message, the request returns an empty response.
	 *
	 * For example, WP Engine has a limit of 1024 characters?
	 * https://wpengine.com/support/using-dev-tools/#Long_Queries_in_wp_db
	 *
	 * @TODO - For long queries, I should find a better solution than this.
	 *
	 * @param string|array $param_value
	 * @param int          $max_length
	 * @return array
	 */
	private function shorten_param_array( $param_value, $max_length ) {
		$param_array  = is_array( $param_value ) ? $param_value : explode( ',', $param_value );
		$param_string = implode( ',', $param_array );

		if ( strlen( $param_string ) > $max_length ) {
			shuffle( $param_array ); // Shuffle to randomize

			$new_param_string   = '';
			$random_param_array = array();

			foreach ( $param_array as $id ) {
				if ( strlen( $new_param_string . $id ) < $max_length ) {
					$new_param_string .= $id . ',';
					$random_param_array[] = $id;
				} else {
					break; // Stop when maximum length is reached
				}
			}

			return $random_param_array;
		}

		return $param_array;
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
		if ( isset( $handler['callback'] ) && is_array( $handler['callback'] ) && isset( $handler['callback'][0] ) ) {
			$controller = $handler['callback'][0];

			// Check if the controller object is one of our registered controllers.
			foreach ( $this->controllers as $key => $wcpos_controller ) {
				if ( $controller === $wcpos_controller ) {
					/**
					 * I'm adding some additional PHP settings before the response. Placing them here so they only apply to the POS API.
					 *
					 * - error_reporting(0) - Turn off error reporting
					 * - ini_set('display_errors', 0) - Turn off error display
					 * - ini_set('precision', 10) - Set the precision of floating point numbers
					 * - ini_set('serialize_precision', 10) - Set the precision of floating point numbers for serialization
					 *
					 * This is to prevent any PHP errors from being displayed in the response.
					 *
					 * The precision settings are to prevent floating point weirdness, eg: stock_quantity 3.6 becomes 3.6000000000000001
					 */
					error_reporting( 0 );
					@ini_set( 'display_errors', 0 );
					@ini_set( 'precision', 10 );
					@ini_set( 'serialize_precision', 10 );

					// Check if the controller has a 'wcpos_dispatch_request' method.
					if ( method_exists( $controller, 'wcpos_dispatch_request' ) ) {
						return $controller->wcpos_dispatch_request( $dispatch_result, $request, $route, $handler );
					}
					break;
				}
			}
		}

		return $dispatch_result;
	}

	/**
	 * Check the Authorization header for a Bearer token.
	 *
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
