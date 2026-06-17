<?php
/**
 * Settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Tax_Id_Detector;
use WCPOS\WooCommercePOS\Services\Tax_Id_Settings;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Class Settings REST API.
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
	 * Register routes.
	 *
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
		// );.

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
				'args'                => $this->get_payment_gateways_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax_ids_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_tax_ids_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args'                => $this->get_tax_ids_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tax_ids/detection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax_ids_detection' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
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
				'permission_callback' => array( $this, 'update_access_permission_check' ),
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
				'permission_callback' => array( $this, 'update_permission_check' ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cloud-print',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cloud_print_settings' ),
					'permission_callback' => array( $this, 'cloud_print_read_permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_cloud_print_settings' ),
					'permission_callback' => array( $this, 'update_permission_check' ),
				),
			)
		);
	}

	/**
	 * Get general endpoint arguments.
	 *
	 * Delegates to the registered General Settings Section.
	 *
	 * @return Closure[][]
	 */
	public function get_general_endpoint_args(): array {
		$section = SettingsService::instance()->sections()->get( 'general' );

		return $section ? $section->endpoint_args() : array();
	}

	/**
	 * Get tax IDs endpoint arguments.
	 *
	 * Delegates to the registered Tax IDs Settings Section.
	 *
	 * @return Closure[][]
	 */
	public function get_tax_ids_endpoint_args(): array {
		$section = SettingsService::instance()->sections()->get( 'tax_ids' );

		return $section ? $section->endpoint_args() : array();
	}

	/**
	 * Get checkout endpoint arguments.
	 *
	 * Delegates to the registered Checkout Settings Section.
	 *
	 * @return Closure[][]
	 */
	public function get_checkout_endpoint_args(): array {
		$section = SettingsService::instance()->sections()->get( 'checkout' );

		return $section ? $section->endpoint_args() : array();
	}

	/**
	 * Get payment gateways endpoint arguments.
	 *
	 * Delegates to the registered Payment Gateways Settings Section.
	 *
	 * @return Closure[][]
	 */
	public function get_payment_gateways_endpoint_args(): array {
		$section = SettingsService::instance()->sections()->get( 'payment_gateways' );

		return $section ? $section->endpoint_args() : array();
	}

	/**
	 * Get general settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_general_settings( WP_REST_Request $request ) {
		$general_settings = woocommerce_pos_get_settings( 'general' );

		if ( is_wp_error( $general_settings ) ) {
			return $general_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $general_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get checkout settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_checkout_settings( WP_REST_Request $request ) {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );

		if ( is_wp_error( $checkout_settings ) ) {
			return $checkout_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $checkout_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get payment gateways settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_payment_gateways_settings( WP_REST_Request $request ) {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );

		if ( is_wp_error( $payment_gateways_settings ) ) {
			return $payment_gateways_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $payment_gateways_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get tax IDs settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_tax_ids_settings( WP_REST_Request $request ) {
		$tax_ids_settings = woocommerce_pos_get_settings( 'tax_ids' );

		if ( is_wp_error( $tax_ids_settings ) ) {
			return $tax_ids_settings;
		}

		$response = new WP_REST_Response( $tax_ids_settings );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Update tax IDs settings. POST data is treated as PATCH (partial), merged
	 * with existing settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_tax_ids_settings( WP_REST_Request $request ) {
		$settings_service = SettingsService::instance();
		$section          = $settings_service->sections()->get( 'tax_ids' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}
		$settings = $section->merge( $section->read(), (array) $request->get_json_params() );

		return $settings_service->save_settings( 'tax_ids', $settings );
	}

	/**
	 * Get tax-ID auto-detection summary for the Compatibility tab.
	 *
	 * Returns the active third-party plugin ids, the per-type defaults, and the
	 * fully composed write_map (defaults < inferred < plugin claims < user
	 * overrides). The UI renders the composed map and surfaces overrides
	 * inline.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tax_ids_detection( WP_REST_Request $request ) {
		$summary = ( new Tax_Id_Detector() )->summary();

		$response = new WP_REST_Response(
			array(
				'plugins'           => $summary['plugins'],
				'default_write_map' => Tax_Id_Settings::default_write_map(),
				'composed_write_map' => $summary['write_map'],
				// Only customer-applicable types are surfaced: business-register
				// identifiers (DE/NL/FR/CH commercial-register types) live on the
				// store, not on customers, so they have no write-map row.
				'types'             => Tax_Id_Types::customer_applicable_types(),
			)
		);
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get access settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_access_settings( WP_REST_Request $request ) {
		$access_settings = woocommerce_pos_get_settings( 'access' );

		if ( is_wp_error( $access_settings ) ) {
			return $access_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $access_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get tools settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_tools_settings( WP_REST_Request $request ) {
		$tools_settings = woocommerce_pos_get_settings( 'tools' );

		if ( is_wp_error( $tools_settings ) ) {
			return $tools_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $tools_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get license settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error|WP_REST_Response
	 */
	public function get_license_settings( WP_REST_Request $request ) {
		$license_settings = woocommerce_pos_get_settings( 'license' );

		if ( is_wp_error( $license_settings ) ) {
			return $license_settings;
		}

		// Create the response object.
		$response = new WP_REST_Response( $license_settings );

		// Set the status code of the response.
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get cloud-print settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_cloud_print_settings() {
		$section = SettingsService::instance()->sections()->get( 'cloud_print' );
		if ( ! $section ) {
			return new WP_REST_Response( array(), 200 );
		}

		return new WP_REST_Response( $section->read(), 200 );
	}

	/**
	 * Replace cloud-print settings.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function update_cloud_print_settings( WP_REST_Request $request ) {
		$section = SettingsService::instance()->sections()->get( 'cloud_print' );
		if ( ! $section ) {
			return new WP_REST_Response(
				array(
					'code'    => 'woocommerce_pos_settings_error',
					'message' => 'Cloud print section not registered.',
				),
				500
			);
		}

		$payload = $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$result = $section->write( (array) $payload );

		if ( is_wp_error( $result ) ) {
			// Keep the historical error body shape {code, message} — clients do
			// not expect WP_Error's extra data envelope here.
			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Sanitize a cloud assignment entry.
	 *
	 * Kept for backward compatibility — the Settings_CloudPrint_Test conformance
	 * gate exercises this method directly via ReflectionMethod. Delegates to
	 * Cloud_Print_Section::sanitize_assignment() so the schema has exactly one
	 * owner.
	 *
	 * @param mixed $assignment Assignment.
	 *
	 * @return array
	 *
	 * @phpstan-ignore-next-line
	 */
	private function sanitize_cloud_assignment( $assignment ): array {
		$section = SettingsService::instance()->sections()->get( 'cloud_print' );

		if ( $section instanceof \WCPOS\WooCommercePOS\Services\Settings\Cloud_Print_Section ) {
			return $section->sanitize_assignment( $assignment );
		}

		return \is_array( $assignment ) ? $assignment : array();
	}


	/**
	 * Update payment gateways settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_payment_gateways_settings( WP_REST_Request $request ) {
		$settings_service = SettingsService::instance();
		$section          = $settings_service->sections()->get( 'payment_gateways' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}
		$settings = $section->merge( $section->read(), (array) $request->get_json_params() );

		return $settings_service->save_settings( 'payment_gateways', $settings );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$settings_service = SettingsService::instance();
		$section          = $settings_service->sections()->get( 'general' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}
		$settings = $section->merge( $section->read(), (array) $request->get_json_params() );

		return $settings_service->save_settings( 'general', $settings );
	}



	/**
	 * Update checkout settings.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$settings_service = SettingsService::instance();
		$section          = $settings_service->sections()->get( 'checkout' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}
		$settings = $section->merge( $section->read(), (array) $request->get_json_params() );

		return $settings_service->save_settings( 'checkout', $settings );
	}



	/**
	 * Update access settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_access_settings( WP_REST_Request $request ) {
		$section = SettingsService::instance()->sections()->get( 'access' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}

		return $section->write( (array) $request->get_json_params() );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @TODO - shouldn't the update return a WP_REST_Response?
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	public function update_tools_settings( WP_REST_Request $request ) {
		$settings_service = SettingsService::instance();
		$section          = $settings_service->sections()->get( 'tools' );
		if ( ! $section ) {
			return new WP_Error( 'woocommerce_pos_settings_error', __( 'Settings section not registered.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}
		$settings = $section->merge( $section->read(), (array) $request->get_json_params() );

		return $settings_service->save_settings( 'tools', $settings );
	}

	/**
	 * Check read permissions.
	 *
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Check Cloud Print read permissions.
	 *
	 * POS clients need to read server-owned Cloud Printer targets so they can route
	 * receipts to printers configured by a manager. Updating the server-owned
	 * settings still requires manage_woocommerce_pos via update_permission_check().
	 *
	 * @return bool
	 */
	public function cloud_print_read_permission_check(): bool {
		return current_user_can( 'access_woocommerce_pos' );
	}

	/**
	 * Check update permissions.
	 *
	 * @return bool
	 */
	public function update_permission_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Check access update permissions.
	 *
	 * @return bool
	 */
	public function update_access_permission_check(): bool {
		return current_user_can( 'edit_users' ) && current_user_can( 'promote_users' );
	}

	/**
	 * Filter payment gateways settings.
	 *
	 * @param mixed $options The gateway options.
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
	 *
	 * @param mixed $value The option value.
	 */
	public function remove_license_transient( $value ) {
		delete_transient( 'woocommerce_pos_pro_license_status' );

		return $value;
	}
}
