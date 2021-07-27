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
			'enabled'            => array(
				'pos_cash',
			),
		),
	);

	/**
	 * Stores constructor.
	 */
	public function __construct() {
		$this->db_prefix = \WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;
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

		//      register_rest_route(
		//          $this->namespace,
		//          '/' . $this->rest_base . '/barcode-fields',
		//          array(
		//              'methods'             => WP_REST_Server::READABLE,
		//              'callback'            => array( $this, 'get_barcode_fields' ),
		//              'permission_callback' => '__return_true',
		//          )
		//      );
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

		return $settings;
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
//			'enabled'           => array(
//				'validate_callback' => function ( $param, $request, $key ) {
//					return is_string( $param );
//				},
//			),
		);

		return $args;
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
				)
			);
		}

		return $ordered_gateways;
	}
}
