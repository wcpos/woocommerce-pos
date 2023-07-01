<?php

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use function in_array;
use function is_array;
use function is_bool;
use function is_integer;
use function is_string;

class Settings extends Abstracts\Controller {


	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

    /**
     *
     */
    protected $settings_service;

	/**
	 * Settings constructor.
	 */
	public function __construct() {
        $this->settings_service = new \WCPOS\WooCommercePOS\Services\Settings();
		add_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'payment_gateways_settings' ) );
	}

    /**
     * BACKWARD COMPATIBILITY: Remove this method in the future
     */
//    public function get_settings( $id ) {
//      $settings = $this->settings_service->get_settings( $id );
//      return $settings;
//    }

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_general_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general/barcodes',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_barcodes' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_general_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_general_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_checkout_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/order-statuses',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_checkout_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_access_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_access_settings' ),
				'permission_callback' => array( $this, 'access_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/license',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_license_settings' ),
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
					return is_bool( $param );
				},
			),
			'decimal_qty' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'force_ssl' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_customer' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'barcode_field' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'generate_username' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
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
					return is_string( $param );
				},
			),
			'admin_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'customer_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_gateway' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'gateways' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_array( $param );
				},
			),
		);
	}



	/**
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function update_payment_gateways_settings( WP_REST_Request $request ) {
        $old_settings = $this->settings_service->get_payment_gateways_settings();
		$updated_settings = array_replace_recursive( $old_settings, $request->get_json_params() );
		return $this->settings_service->save_settings( 'payment_gateways', $updated_settings );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$settings = array_replace_recursive( $this->get_general_settings(), $request->get_json_params() );
		return $this->settings_service->save_settings( 'general', $settings );
	}



	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$settings = array_replace_recursive( $this->get_checkout_settings(), $request->get_json_params() );
		return $this->settings_service->save_settings( 'checkout', $settings );
	}



	/**
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
		if ( 1 === count( $update ) ) {
			$slugs = array_keys( $update );
			$slug = $slugs[0];
			$role = get_role( $slug );

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

		return $this->settings_service->get_access_settings();
	}



	/**
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check(): bool {
		//		return current_user_can( 'manage_woocommerce_pos' );
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
	 *
	 */
	public function payment_gateways_settings( $options ) {
		foreach ( $options['gateways'] as $gateway_id => &$gateway_data ) {
			if ( ! in_array( $gateway_id, array( 'pos_cash', 'pos_card' ) ) ) {
				$gateway_data['enabled'] = false;
			}
		}
		if ( ! in_array( $options['default_gateway'], array( 'pos_cash', 'pos_card' ) ) ) {
			$options['default_gateway'] = 'pos_cash';
		}
		return $options;
	}
}
