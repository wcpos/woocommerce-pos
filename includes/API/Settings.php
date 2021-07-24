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
		$data = array(
			'pos_only_products' => woocommerce_pos_get_setting( 'general', 'pos_only_products', false ),
			'decimal_qty'       => woocommerce_pos_get_setting( 'general', 'decimal_qty', false ),
		);

		return $data;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		// Get sent data and set default value
		$params = wp_parse_args( $request->get_params(), array() );

		$success = woocommerce_pos_update_setting( 'general', 'pos_only_products', $params['pos_only_products'] );

		if ( $success ) {
			return rest_ensure_response( $this->get_general_settings() );
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
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
		return rest_get_endpoint_args_for_schema( $this->get_general_schema(), WP_REST_Server::EDITABLE );
	}

	/**
	 *
	 */
	public function get_general_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'settings_general',
			'type'       => 'object',
			'properties' => array(
				'pos_only_products' => array(
					'description' => __( 'Enable POS only products', 'woocommerce-pos' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'decimal_qty'       => array(
					'description' => __( 'Enable decimal quantities', 'woocommerce-pos' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $schema;
	}
}
