<?php


namespace WCPOS\WooCommercePOS\API;

use WC_Payment_Gateways;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Settings extends Controller {

	/**
	 * @var string
	 */
	private $db_prefix;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 *
	 */
	private $default_settings = array(
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
	);

	/**
	 * @var array
	 */
	private $caps;


	/**
	 * Stores constructor.
	 */
	public function __construct() {
		$this->db_prefix = \WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;

		$this->caps = apply_filters( 'woocommerce_pos_capabilities', array(
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
				'read',
			),
		) );
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
				'permission_callback' => array( $this, 'update_permission_check' ),
//				'args'                => $this->get_access_endpoint_args(),
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
	public function get_all_settings() {
		$data = array(
			'general'  => $this->get_settings( 'general' ),
			'checkout' => $this->get_settings( 'checkout' ),
			'access'   => $this->get_access_settings(),
		);

		return $data;
	}

	/**
	 * @param string $group
	 *
	 * @return array
	 */
	public function get_settings( $group ) {
		$settings = wp_parse_args(
			array_intersect_key(
				woocommerce_pos_get_settings( $group, null, array() ),
				$this->default_settings[ $group ]
			),
			$this->default_settings[ $group ]
		);

		if ( 'checkout' == $group ) {
			$settings['gateways'] = $this->get_gateways();
		}

		return $settings;
	}

	/**
	 *
	 */
	public function get_access_settings() {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ): foreach ( $roles as $slug => $role ):
			$role_caps[ $slug ] = array(
				'name'         => $role['name'],
				'capabilities' => array(
					'wcpos' => array_intersect_key(
						array_merge( array_fill_keys( $this->caps['wcpos'], false ), $role['capabilities'] ),
						array_flip( $this->caps['wcpos'] )
					),
					'wc'    => array_intersect_key(
						array_merge( array_fill_keys( $this->caps['wc'], false ), $role['capabilities'] ),
						array_flip( $this->caps['wc'] )
					),
					'wp'    => array_intersect_key(
						array_merge( array_fill_keys( $this->caps['wp'], false ), $role['capabilities'] ),
						array_flip( $this->caps['wp'] )
					),
				),
			);
		endforeach; endif;

		return $role_caps;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		// Get sent data and set default value
		$value = wp_parse_args(
			array_intersect_key(
				$request->get_params(),
				$this->default_settings['general']
			),
			$this->default_settings['general']
		);

		woocommerce_pos_update_settings( 'general', null, $value );

		return rest_ensure_response( $this->get_settings( 'general' ) );

		//      return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
	}

	/**
	 *
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$value = wp_parse_args(
			array_intersect_key(
				$request->get_params(),
				$this->default_settings['checkout']
			),
			$this->default_settings['checkout']
		);

		woocommerce_pos_update_settings( 'checkout', null, $value );

		return rest_ensure_response( $this->get_settings( 'checkout' ) );
	}

	/**
	 *
	 */
	public function update_access_settings( WP_REST_Request $request ) {
		global $wp_roles;
		$roles = array_intersect_key( $request->get_params(), $wp_roles->roles );

		foreach ( $roles as $slug => $array ):

			$role = get_role( $slug );

			if ( $array['capabilities'] ) : foreach ( $array['capabilities'] as $key => $caps ):
				if ( $caps ): foreach ( $caps as $cap => $grant ):
					if ( in_array( $cap, $this->caps[ $key ] ) ) {
						$grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
					}
				endforeach; endif;
			endforeach; endif;

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
	 *
	 */
	public function get_access_endpoint_args() {
		// validate access settings
	}

	/**
	 * @return array
	 */
	public function get_barcode_fields() {
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
		array_push( $result, woocommerce_pos_get_settings( 'general', 'barcode_field' ) );
		sort( $result );

		return array_unique( $result );
	}

	/**
	 *
	 */
	public function get_gateways() {
		$ordered_gateways = array();
		$gateways         = WC_Payment_Gateways::instance()->payment_gateways;

		foreach ( $gateways as $gateway ) {
			array_push(
				$ordered_gateways,
				array(
					'id'          => $gateway->id,
					'title'       => $gateway->title,
					'description' => $gateway->description,
					'enabled'     => false,

				)
			);
		}

		return $ordered_gateways;
	}
}
