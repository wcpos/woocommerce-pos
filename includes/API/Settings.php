<?php


namespace WCPOS\WooCommercePOS\API;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Settings extends Controller {
	/**
	 * Admin and API Settings classes share the same traits
	 */
	use \WCPOS\WooCommercePOS\Traits\Settings;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';


	/**
	 * Stores constructor.
	 */
	public function __construct() {

	}

	/**
	 *
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_general_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_general_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_checkout_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_access_settings' ),
				'permission_callback' => array( $this, 'access_permission_check' ),
			)
		);

	}

	/**
	 *
	 */
	public function get_general_endpoint_args() {
		$args = array(
			'pos_only_products'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'decimal_qty'                 => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'force_ssl'                   => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_customer'            => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'barcode_field'               => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'generate_username'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
		);

		return $args;
	}

	/**
	 *
	 */
	public function get_checkout_endpoint_args() {
		$args = array(
			'order_status'       => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'admin_emails'       => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'customer_emails'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_gateway'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'gateways'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_array( $param );
				},
			),
		);

		return $args;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function get_items( $request ) {
		$response = rest_ensure_response( $this->get_all_settings() );

		return rest_ensure_response( $response );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$success = $this->update_settings( 'general', $request->get_params() );
		if ( $success ) {
			return rest_ensure_response( $this->get_settings( 'general' ) );
		} else {
			return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
		}
	}

	/**
	 *
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$success = $this->update_settings( 'checkout', $request->get_params() );
		if ( $success ) {
			return rest_ensure_response( $this->get_settings( 'checkout' ) );
		} else {
			return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
		}
	}

	/**
	 *
	 */
	public function update_access_settings( WP_REST_Request $request ) {
		global $wp_roles;
		$roles = array_intersect_key( $request->get_params(), $wp_roles->roles );

		foreach ( $roles as $slug => $array ) :

			$role = get_role( $slug );

			if ( $array['capabilities'] ) :
				foreach ( $array['capabilities'] as $key => $caps ) :
					if ( $caps ) :
						foreach ( $caps as $cap => $grant ) :
							// special case: administrator must have read capability
							if ( 'administrator' == $slug && 'read' == $cap ) {
								continue;
							}
							if ( in_array( $cap, self::$caps[ $key ] ) ) {
								$grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
							}
						endforeach;
					endif;
				endforeach;
			endif;

		endforeach;

		return rest_ensure_response( $this->get_access_settings() );
	}

	/**
	 *
	 */
	public function update_permission_check() {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 *
	 */
	public function access_permission_check() {
		return current_user_can( 'manage_options' );
	}
}
