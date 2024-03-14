<?php

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_REST_Controller;
use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Class Settings REST API
 */
class Settings extends WP_REST_Controller {
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
	protected $rest_base = 'settings';

	/**
	 * Settings constructor.
	 */
	public function __construct() {
		add_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'payment_gateways_settings' ) );

		// remove this once Pro settings have been moved to the new settings service.
		add_filter( 'pre_update_option_woocommerce_pos_pro_settings_license', array( $this, 'remove_license_transient' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_general_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		// register_rest_route(
		// $this->namespace,
		// '/' . $this->rest_base . '/general/barcodes',
		// array(
		// 'methods' => WP_REST_Server::READABLE,
		// 'callback' => array( $this, 'get_barcodes' ),
		// 'permission_callback' => array( $this, 'read_permission_check' ),
		// )
		// );

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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkout_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/order-statuses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
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
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_access_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tools_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_tools_settings' ),
				'permission_callback' => array( $this, 'access_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/license',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_license_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);
	}

	/**
	 * @return Closure[][]
	 */
	public function get_general_endpoint_args(): array {
		return array(
			'pos_only_products' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'decimal_qty' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'force_ssl' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_customer' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'barcode_field' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'generate_username' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
		);
	}

	/**
	 * @return Closure[][]
	 */
	public function get_checkout_endpoint_args(): array {
		return array(
			'order_status' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'admin_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'customer_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_gateway' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'gateways' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
		);
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_general_settings( WP_REST_Request $request ) {
		$general_settings = woocommerce_pos_get_settings( 'general' );

		if ( is_wp_error( $general_settings ) ) {
			return $general_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $general_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_checkout_settings( WP_REST_Request $request ) {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );

		if ( is_wp_error( $checkout_settings ) ) {
			return $checkout_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $checkout_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_payment_gateways_settings( WP_REST_Request $request ) {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );

		if ( is_wp_error( $payment_gateways_settings ) ) {
			return $payment_gateways_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $payment_gateways_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_access_settings( WP_REST_Request $request ) {
		$access_settings = woocommerce_pos_get_settings( 'access' );

		if ( is_wp_error( $access_settings ) ) {
			return $access_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $access_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_tools_settings( WP_REST_Request $request ) {
		$tools_settings = woocommerce_pos_get_settings( 'tools' );

		if ( is_wp_error( $tools_settings ) ) {
			return $tools_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $tools_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_REST_Response
	 */
	public function get_license_settings( WP_REST_Request $request ) {
		$license_settings = woocommerce_pos_get_settings( 'license' );

		if ( is_wp_error( $license_settings ) ) {
			return $license_settings;
		}

		// Create the response object
		$response = new WP_REST_Response( $license_settings );

		// Set the status code of the response
		$response->set_status( 200 );

		return $response;
	}


	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_payment_gateways_settings( WP_REST_Request $request ) {
		$old_settings     = woocommerce_pos_get_settings( 'payment_gateways' );
		$updated_settings = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();
		return $settings_service->save_settings( 'payment_gateways', $updated_settings );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'general' );
		$settings = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();
		return $settings_service->save_settings( 'general', $settings );
	}



	/**
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'checkout' );
		$settings = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();
		return $settings_service->save_settings( 'checkout', $settings );
	}



	/**
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	public function update_access_settings( WP_REST_Request $request ): array {
		global $wp_roles;
		$data = $request->get_json_params();

		// get all role slugs
		$roles = array_keys( $wp_roles->roles );

		// get property from $data where key is in $roles
		$update = array_intersect_key( $data, array_flip( $roles ) );

		// if $role is array with one property, update the capabilities
		if ( 1 === \count( $update ) ) {
			$slugs = array_keys( $update );
			$slug  = $slugs[0];
			$role  = get_role( $slug );

			// flatten capabilities array from 'wc', 'wp', 'wcpos' grouping
			$flattened_caps = array();
			foreach ( $update[ $slug ]['capabilities'] as $capabilities ) {
				$flattened_caps = array_merge( $flattened_caps, $capabilities );
			}

			// update capabilities for each $flattened_cap (should only be one)
			foreach ( $flattened_caps as $cap => $grant ) {
				// sanity check for admin role, read capability
				if ( 'administrator' === $slug && 'read' === $cap ) {
					continue;
				}
				if ( $grant ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		return woocommerce_pos_get_settings( 'access' );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_tools_settings( WP_REST_Request $request ) {
		$old_settings = woocommerce_pos_get_settings( 'tools' );
		$settings = array_replace_recursive( $old_settings, $request->get_json_params() );

		$settings_service = SettingsService::instance();
		return $settings_service->save_settings( 'tools', $settings );
	}

	/**
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check(): bool {
		// return current_user_can( 'manage_woocommerce_pos' );
		return true;
	}

	/**
	 * @return bool
	 */
	public function update_permission_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * @return bool
	 */
	public function access_permission_check(): bool {
		return current_user_can( 'promote_users' );
	}

	/**
	 * @param mixed $options
	 */
	public function payment_gateways_settings( $options ) {
		foreach ( $options['gateways'] as $gateway_id => &$gateway_data ) {
			if ( ! \in_array( $gateway_id, array( 'pos_cash', 'pos_card' ), true ) ) {
				$gateway_data['enabled'] = false;
			}
		}
		if ( ! \in_array( $options['default_gateway'], array( 'pos_cash', 'pos_card' ), true ) ) {
			$options['default_gateway'] = 'pos_cash';
		}

		return $options;
	}

	/**
	 * Temporary fix for stale license status transient. Remove when possible.
	 */
	public function remove_license_transient( $value ) {
		delete_transient( 'woocommerce_pos_pro_license_status' );
		return $value;
	}
}
