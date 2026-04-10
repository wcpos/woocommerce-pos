<?php
/**
 * WCPOS REST API Class, ie: /wcpos/v1/ endpoints.
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
 * API class.
 */
class API {
	/**
	 * WCPOS REST API namespaces and endpoints.
	 *
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * Map of route patterns to controller keys.
	 * Built during register_routes() for use in rest_dispatch_request().
	 *
	 * @var array<string, string>
	 */
	protected $route_map = array();

	/**
	 * Flag to check if authentication has been checked.
	 *
	 * @var bool
	 */
	protected $is_auth_checked = false;

	/**
	 * Flag to track whether WCPOS successfully authenticated the current request
	 * via its own Bearer token. Used to suppress errors from third-party JWT
	 * plugins that inspected the same Authorization header but could not validate
	 * a WCPOS-issued token with their own secret.
	 *
	 * @var bool
	 */
	protected $authenticated_via_wcpos = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_routes();

		// Allows requests from WCPOS Desktop and Mobile Apps.
		add_filter( 'rest_allowed_cors_headers', array( $this, 'rest_allowed_cors_headers' ), 10, 1 );
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 10, 4 );

		/*
		 * Adds authentication to for JWT bearer tokens
		 * - We run determine_current_user at 20 to allow other plugins to run first
		 */
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 50, 1 );

		// Adds info about the WordPress install.
		add_filter( 'rest_index', array( $this, 'rest_index' ), 10, 1 );

		// These filters allow changes to the WC REST API response.
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );
	}

	/**
	 * Register routes for all controllers.
	 */
	public function register_routes(): void {
		/**
		 * Filter the list of controller classes used in the WCPOS REST API.
		 *
		 * This filter allows customizing or extending the set of controller classes that handle
		 * REST API routes for the WCPOS. By filtering these controllers, plugins can
		 * modify existing endpoints or add new controllers for additional functionality.
		 *
		 * @since 1.5.0
		 *
		 * @param array $controllers Associative array of controller identifiers to their corresponding class names.
		 *                           - 'auth'                  => Fully qualified name of the class handling authentication.
		 *                           - 'settings'              => Fully qualified name of the class handling settings.
		 *                           - 'cashier'               => Fully qualified name of the class handling cashier management.
		 *                           - 'products'              => Fully qualified name of the class handling products.
		 *                           - 'product_variations'    => Fully qualified name of the class handling product variations.
		 *                           - 'orders'                => Fully qualified name of the class handling orders.
		 *                           - 'customers'             => Fully qualified name of the class handling customers.
		 *                           - 'product_tags'          => Fully qualified name of the class handling product tags.
		 *                           - 'product_categories'    => Fully qualified name of the class handling product categories.
		 *                           - 'taxes'                 => Fully qualified name of the class handling taxes.
		 *                           - 'shipping_methods'      => Fully qualified name of the class handling shipping methods.
		 *                           - 'tax_classes'           => Fully qualified name of the class handling tax classes.
		 *                           - 'order_statuses'        => Fully qualified name of the class handling order statuses.
		 */
		$classes = apply_filters(
			'woocommerce_pos_rest_api_controllers',
			array(
				// WCPOS rest api controllers.
				'auth'                  => API\Auth::class,
				'settings'              => API\Settings::class,
				'cashier'               => API\Cashier::class,
				'templates'             => API\Templates_Controller::class,
				'receipts'              => API\Receipts_Controller::class,

				// TODO: remove this?
				'stores'                => API\Stores::class,
				'extensions'            => API\Extensions::class,
				'logs'                  => API\Logs::class,

				// extend WC REST API controllers.
				'products'              => API\Products_Controller::class,
				'product_variations'    => API\Product_Variations_Controller::class,
				'orders'                => API\Orders_Controller::class,
				'customers'             => API\Customers_Controller::class,
				'product_tags'          => API\Product_Tags_Controller::class,
				'product_categories'    => API\Product_Categories_Controller::class,
				'product_brands'        => API\Product_Brands_Controller::class,
				'coupons'               => API\Coupons_Controller::class,
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

		// Build route map for use in rest_dispatch_request().
		$rest_server = rest_get_server();
		$all_routes  = $rest_server->get_routes( 'wcpos/v1' );

		foreach ( $all_routes as $route_pattern => $route_handlers ) {
			foreach ( $route_handlers as $route_handler ) {
				$callback = $route_handler['callback'] ?? null;

				// Extract the controller object from the callback.
				$controller_obj = null;
				if ( \is_array( $callback ) && isset( $callback[0] ) && \is_object( $callback[0] ) ) {
					$controller_obj = $callback[0];
				} elseif ( $callback instanceof \Closure ) {
					// WC 10.5+ RestApiCache wraps callbacks in closures.
					// Use reflection to extract the bound $this.
					$ref            = new \ReflectionFunction( $callback );
					$controller_obj = $ref->getClosureThis();
				}

				if ( ! $controller_obj ) {
					continue;
				}

				// Find which controller key this object belongs to.
				foreach ( $this->controllers as $key => $registered_controller ) {
					if ( $controller_obj === $registered_controller ) {
						$this->route_map[ $route_pattern ] = $key;
						break;
					}
				}
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
	 * Runs at priority 20, after other plugins (e.g. third-party JWT plugins at
	 * priority 10) have had a chance to authenticate the user. If another plugin
	 * already returned a valid user ID, we trust it. Otherwise we attempt our own
	 * WCPOS Bearer-token authentication.
	 *
	 * Note: some JWT plugins pass a WP_Error through this filter when they fail to
	 * validate a Bearer token. We treat that the same as false so we can still
	 * authenticate the request with our own JWT.
	 *
	 * Note: this filter may not be called at all when WordPress has already cached
	 * the current user (WooCommerce issue #26847). The rest_authentication_errors
	 * fallback below handles that scenario.
	 *
	 * @param false|int|\WP_Error $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return false|int
	 */
	public function determine_current_user( $user_id ) {
		$this->is_auth_checked = true;

		// Trust a valid user ID set by another plugin (e.g. JWT plugin with its own token).
		// Treat a WP_Error the same as false — another plugin rejected its own token,
		// but we should still attempt authentication with our Bearer token.
		if ( ! empty( $user_id ) && ! is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$result = $this->authenticate( false );
		if ( $result && ! is_wp_error( $result ) ) {
			$this->authenticated_via_wcpos = true;
			return $result;
		}

		// If neither we nor another plugin authenticated the user, return false
		// (not authenticated) rather than a WP_Error from $user_id. WordPress core
		// expects determine_current_user to return false|int, not WP_Error.
		// The JWT plugin's error will surface via rest_authentication_errors instead.
		return is_wp_error( $user_id ) ? false : $user_id;
	}

	/**
	 * Handles two distinct failure modes:
	 *
	 * 1. WooCommerce issue #26847: determine_current_user may not be called when
	 *    WordPress has already cached the current user. We attempt auth here as a
	 *    fallback.
	 *
	 * 2. JWT plugin conflict: a third-party JWT plugin (e.g. jwt-authentication-for-wp-rest-api)
	 *    sees our Bearer token, fails to validate it with its own secret, and returns
	 *    a WP_Error via rest_authentication_errors at priority 10. We run at priority 50
	 *    and attempt our own Bearer-token validation. If it succeeds, we clear the
	 *    stale error — our authentication wins.
	 *
	 * @param mixed $errors Authentication errors.
	 *
	 * @return mixed
	 */
	public function rest_authentication_errors( $errors ) {
		// If there is already an error from a previous filter (e.g. a JWT plugin that
		// rejected our Bearer token), attempt WCPOS authentication before passing it
		// through. This covers the case where determine_current_user was skipped
		// (WC #26847) or where the JWT plugin ran at a higher priority.
		if ( ! empty( $errors ) ) {
			// Only clear errors that originate from JWT authentication plugins. Errors
			// from other mechanisms (maintenance locks, IP restrictions, etc.) should
			// be passed through even when the WCPOS Bearer token is valid.
			$is_jwt_plugin_error = is_wp_error( $errors ) && 0 === strpos( $errors->get_error_code(), 'jwt_auth_' );

			if ( $is_jwt_plugin_error && ! $this->authenticated_via_wcpos ) {
				$user_id = $this->authenticate( false );
				if ( $user_id && ! is_wp_error( $user_id ) ) {
					wp_set_current_user( $user_id );
					$this->authenticated_via_wcpos = true;
				}
			}

			if ( $this->authenticated_via_wcpos && $is_jwt_plugin_error ) {
				return null;
			}

			return $errors;
		}

		// check if determine_current_user has been called.
		if ( ! $this->is_auth_checked ) {
			// Authentication hasn't occurred during `determine_current_user`, so check auth.
			$user_id = $this->authenticate( false );
			if ( $user_id && ! is_wp_error( $user_id ) ) {
				wp_set_current_user( $user_id );
				$this->authenticated_via_wcpos = true;

				return true;
			}
		}

		return $errors;
	}

	/**
	 * Extract the Authorization Bearer token from the request.
	 *
	 * @return false|string
	 */
	public function get_auth_header() {
		// Check if HTTP_AUTHORIZATION is set and not empty
		// (htaccess SetEnvIf can set an empty value when no header is present).
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// Check for alternative header in $_SERVER.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Check for authorization param in URL ($_GET).
		if ( ! empty( $_GET['authorization'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['authorization'] ) );
		}

		// Return false if none of the variables are set.
		return false;
	}

	/**
	 * Adds info to the WP REST API index response.
	 * - UUID
	 * - Version Info.
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
		$response->data['uuid']             = $uuid;
		$response->data['wp_version']       = get_bloginfo( 'version' );
		$response->data['wc_version']       = WC()->version;
		$response->data['wcpos_version']    = VERSION;
		$response->data['use_jwt_as_param'] = woocommerce_pos_get_settings( 'tools', 'use_jwt_as_param' );

		// Add WCPOS authentication endpoint to the response.
		$response->data['authentication']['wcpos'] = array(
			'endpoints' => array(
				'authorization' => Template_Router::get_auth_url(),
			),
		);

		/**
		 * Remove the routes from the response.
		 *
		 * Some wordpress sites have a huge number of routes, like 2MB of data. It shouldn;t matter, but it seems
		 * to cause issues with the desktop application sometimes. We don't use the routes at the moment, so we
		 * can remove them from the response.
		 */
		$data = $response->get_data();
		unset( $data['routes'] );
		$response->set_data( $data );

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
		if ( strpos( $request->get_route(), '/wcpos/v1/' ) !== 0 ) {
			return $result;
		}

		// Baseline permission gate: all POS endpoints require access_woocommerce_pos.
		// Exempt: auth/test and auth/refresh which must be public.
		$route = $request->get_route();
		if ( '/wcpos/v1/auth/test' !== $route && '/wcpos/v1/auth/refresh' !== $route ) {
			if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
				return new \WP_Error(
					'woocommerce_pos_rest_forbidden',
					__( 'You do not have permission to access the POS.', 'woocommerce-pos' ),
					array( 'status' => 403 )
				);
			}
		}

		$max_length = 10000;

		// Process 'include' parameter.
		$include = $request->get_param( 'include' );
		if ( $include ) {
			$processed_include = $this->shorten_param_array( $include, $max_length );
			$request->set_param( 'wcpos_include', $processed_include );
			unset( $request['include'] );
		}

		// Process 'exclude' parameter.
		$exclude = $request->get_param( 'exclude' );
		if ( $exclude ) {
			$processed_exclude = $this->shorten_param_array( $exclude, $max_length );
			$request->set_param( 'wcpos_exclude', $processed_exclude );
			unset( $request['exclude'] );
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
		// Only process wcpos/v1 routes.
		if ( ! isset( $this->route_map[ $route ] ) ) {
			return $dispatch_result;
		}

		/*
		 * POS-specific PHP settings to prevent errors in JSON and float weirdness.
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
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- intentionally disabling error display for POS API responses.
		@ini_set( 'precision', '10' );
		@ini_set( 'serialize_precision', '10' );

		$key        = $this->route_map[ $route ];
		$controller = $this->controllers[ $key ] ?? null;

		if ( $controller && method_exists( $controller, 'wcpos_dispatch_request' ) ) {
			return $controller->wcpos_dispatch_request( $dispatch_result, $request, $route, $handler );
		}

		return $dispatch_result;
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
	 * @param array|string $param_value The parameter value.
	 * @param int          $max_length  The maximum length.
	 *
	 * @return array
	 */
	private function shorten_param_array( $param_value, $max_length ) {
		$param_array  = \is_array( $param_value ) ? $param_value : explode( ',', $param_value );
		$param_string = implode( ',', $param_array );

		if ( \strlen( $param_string ) > $max_length ) {
			shuffle( $param_array ); // Shuffle to randomize.

			$new_param_string   = '';
			$random_param_array = array();

			foreach ( $param_array as $id ) {
				if ( \strlen( $new_param_string . $id ) < $max_length ) {
					$new_param_string .= $id . ',';
					$random_param_array[] = $id;
				} else {
					break; // Stop when maximum length is reached.
				}
			}

			return $random_param_array;
		}

		return $param_array;
	}

	/**
	 * Check the Authorization header for a Bearer token.
	 *
	 * @param false|int $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return false|int|\WP_Error
	 */
	private function authenticate( $user_id ) {
		// check if there is an auth header.
		$auth_header = $this->get_auth_header();
		if ( ! \is_string( $auth_header ) ) {
			return $user_id;
		}

		// Extract Bearer token from Authorization Header.
		list($token) = sscanf( $auth_header, 'Bearer %s' );

		if ( $token ) {
			$auth_service  = Auth::instance();
			$decoded_token = $auth_service->validate_token( $token );

			// Check if validate_token returned WP_Error and user_id is null.
			if ( is_wp_error( $decoded_token ) && false === $user_id ) {
				return $decoded_token;
			}

			// If the token is valid, set the user_id.
			if ( ! is_wp_error( $decoded_token ) ) {
				$user_id = $decoded_token->data->user->id;

				return absint( $user_id );
			}
		}

		return $user_id;
	}
}
