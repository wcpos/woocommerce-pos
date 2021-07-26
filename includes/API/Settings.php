<?php


namespace WCPOS\WooCommercePOS\API;

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
			'/' . $this->rest_base . '/barcode-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_barcode_fields' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_barcode_field' ),
					'permission_callback' => array( $this, 'update_permission_check' ),
				),
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
			'general' => $this->get_general_settings(),
		);

		return $data;
	}

	/**
	 * @return array
	 */
	public function get_general_settings() {
		$settings = woocommerce_pos_get_settings( 'general' );

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

		return rest_ensure_response( $this->get_general_settings() );

//		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
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
	public function get_barcode_fields( $request ) {
		global $wpdb;

//		$q = isset( $_GET['q'] ) ? $_GET['q'] : '';
		$q = '';

		$result = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT DISTINCT(pm.meta_key)
				FROM $wpdb->postmeta AS pm
				JOIN $wpdb->posts AS p
				ON p.ID = pm.post_id
				WHERE p.post_type IN ('product', 'product_variation')
				AND pm.meta_key LIKE %s
				ORDER BY pm.meta_key
				", '%' . $q . '%'
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 *
	 */
	public function add_barcode_field() {
		
	}
}
