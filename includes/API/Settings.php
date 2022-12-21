<?php

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Settings extends Controller {
	/**
	 * Prefix for the $wpdb->options table.
	 *
	 * @var string
	 */
	protected static $db_prefix = 'woocommerce_pos_settings_';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	protected static $default_settings = array(
		'general'  => array(
			'pos_only_products'           => false,
			'decimal_qty'                 => false,
			'force_ssl'                   => true,
			'default_customer'            => 0,
			'default_customer_is_cashier' => false,
			'barcode_field'               => '_sku',
			'generate_username'           => true,
		),
		'checkout' => array(
			'order_status'       => 'wc-completed',
			'admin_emails'       => true,
			'customer_emails'    => true,
			'auto_print_receipt' => false,
			'default_gateway'    => 'pos_cash',
			'gateways'           => array(),
		),
		'license' => array(
			'key' => '',
			'activated' => false,
		),
	);

	/**
	 * @var array
	 */
	private static $caps = array(
		'wcpos' => array(
			'access_woocommerce_pos',  // pos frontend
			'manage_woocommerce_pos', // pos admin
		),
		'wc'    => array(
			'create_users',
			'edit_others_products',
			'edit_product',
			'edit_published_products',
			'edit_users',
			'list_users',
			'publish_shop_orders',
			'read_private_products',
			'read_private_shop_coupons',
			'read_private_shop_orders',
		),
		'wp'    => array(
			'read', // wp-admin access
		),
	);

	/**
	 * Settings constructor.
	 */
	public function __construct() {
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general/barcodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_barcodes' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkout_settings' ),
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
			'/' . $this->rest_base . '/license',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_license_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);
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
	 * @return array
	 */
	public function get_general_settings(): array {
		$general_settings = $this->merge_settings(
			get_option( self::$db_prefix . 'general', array() ),
			self::$default_settings['general']
		);

		return apply_filters( 'woocommerce_pos_general_settings', $general_settings );
	}

	/**
	 * @return array
	 */
	public function get_checkout_settings(): array {
		$checkout_settings = $this->merge_settings(
			get_option( self::$db_prefix . 'checkout', array() ),
			self::$default_settings['checkout']
		);

		return apply_filters( 'woocommerce_pos_checkout_settings', $checkout_settings );
	}

	/**
	 *
	 */
	public function get_access_settings(): array {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ) {
			foreach ( $roles as $slug => $role ) {
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wcpos'] )
						),
						'wc'    => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wc'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wc'] )
						),
						'wp'    => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wp'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wp'] )
						),
					),
				);
			}
		}

		return apply_filters( 'woocommerce_pos_access_settings', $role_caps );
	}

	/**
	 *
	 */
	public function get_license_settings() {
		$license_settings = $this->merge_settings(
			get_option( self::$db_prefix . 'license', array() ),
			self::$default_settings['license']
		);

		return apply_filters( 'woocommerce_pos_license_settings', $license_settings );
	}

	/**
	 * @return array
	 */
	public function get_barcodes(): array {
		global $wpdb;

		$result = $wpdb->get_col(
			"
			SELECT DISTINCT(pm.meta_key)
			FROM $wpdb->postmeta AS pm
			JOIN $wpdb->posts AS p
			ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			ORDER BY pm.meta_key
			"
		);

		// maybe add custom barcode field
		$custom_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		if ( ! empty( $custom_field ) ) {
			$result[] = $custom_field;
		}

		sort( $result );

		return array_unique( $result );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$settings = $this->merge_settings( $request->get_params(), $this->get_general_settings() );
		$success = update_option(
			self::$db_prefix . 'general',
			array_merge(
				array( 'date_modified_gmt' => current_time( 'mysql', true ) ),
				$settings
			),
			false
		);

		if ( $success ) {
			return $this->get_general_settings();
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$success = update_option( self::$db_prefix . 'checkout', $request->get_params() );

		if ( $success ) {
			return $this->get_checkout_settings();
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}



	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_access_settings( WP_REST_Request $request ) {
		global $wp_roles;
		$roles = array_intersect_key( $request->get_params(), $wp_roles->roles );

		foreach ( $roles as $slug => $array ) {
			$role = get_role( $slug );

			if ( $array['capabilities'] ) {
				foreach ( $array['capabilities'] as $key => $caps ) {
					if ( $caps ) {
						foreach ( $caps as $cap => $grant ) {
							// special case: administrator must have read capability
							if ( 'administrator' == $slug && 'read' == $cap ) {
								continue;
							}
							if ( \in_array( $cap, self::$caps[ $key ], true ) ) {
								$grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
							}
						}
					}
				}
			}
		}

		return $this->get_access_settings();
	}

	/**
	 * Merges the given array settings with the defaults.
	 *
	 * @param string $group
	 * @param array  $settings
	 *
	 * @return array
	 */
	public function merge_settings( array $settings, array $default ): array {
		return wp_parse_args( array_intersect_key( $settings, $default ), $default );
	}

	/**
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check() {
		//		return current_user_can( 'manage_woocommerce_pos' );
		return true;
	}


	/**
	 * @return bool
	 */
	public function update_permission_check() {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * @return bool
	 */
	public function access_permission_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return Closure[][]
	 */
	public function get_general_endpoint_args(): array {
		return array(
			'pos_only_products'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'decimal_qty'                 => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'force_ssl'                   => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_customer'            => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'barcode_field'               => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'generate_username'           => array(
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
			'order_status'       => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'admin_emails'       => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'customer_emails'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_gateway'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'gateways'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
		);
	}
}
