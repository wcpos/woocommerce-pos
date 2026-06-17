<?php
/**
 * POS payment gateways controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POS payment gateways controller.
 *
 * Exposes a lean POS-facing catalog of payment gateways. Unlike WooCommerce's
 * core payment-gateways controller this deliberately does NOT serialize each
 * gateway's admin settings schema: the POS only needs the capability contract
 * (id/title/enabled/provider/pos_type/capabilities/provider_data), and calling
 * WC_Settings_API::get_settings() forces every gateway's init_form_fields() to
 * run in a non-admin REST context — which fatals on gateways that gate their
 * settings code behind is_admin() (e.g. ToyyibPay).
 */
class Payment_Gateways extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'payment-gateways';

	/**
	 * Shared gateway contract helper.
	 *
	 * @var Gateway_Contract
	 */
	private $gateway_contract;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->gateway_contract = new Gateway_Contract();
	}

	/**
	 * Register routes.
	 *
	 * Only the collection (catalog) route is exposed. Reading or updating an
	 * individual gateway's settings is intentionally not supported here.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Read permissions for payment gateway catalog.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' );
	}

	/**
	 * Return the POS payment gateway catalog.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		WC()->payment_gateways();
		$gateways = WC()->payment_gateways->payment_gateways();
		$data     = array();

		foreach ( $gateways as $gateway ) {
			$response = $this->prepare_item_for_response( $gateway, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepare a gateway for the POS response.
	 *
	 * Builds the payload from the gateway's already-populated public properties
	 * plus the POS contract helper. It never calls get_settings()/init_form_fields().
	 *
	 * @param WC_Payment_Gateway $item    Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array(
			'id'            => $item->id,
			'title'         => $item->get_title(),
			'description'   => $item->get_description(),
			'enabled'       => $this->gateway_contract->is_pos_enabled( $item ),
			'provider'      => $this->gateway_contract->get_provider( $item, $request ),
			'pos_type'      => $this->gateway_contract->infer_pos_type( $item, $request ),
			'capabilities'  => $this->gateway_contract->get_capabilities( $item, $request ),
			'provider_data' => $this->gateway_contract->get_provider_data( $item, $request ),
		);

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Get the POS payment gateway schema.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'pos_payment_gateway',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Payment gateway ID.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'title'         => array(
					'description' => __( 'Payment gateway title shown at the POS.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'description'   => array(
					'description' => __( 'Payment gateway description.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'enabled'       => array(
					'description' => __( 'Whether the gateway is enabled for the POS.', 'woocommerce-pos' ),
					'type'        => 'boolean',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'provider'      => array(
					'description' => __( 'Provider family identifier.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'pos_type'      => array(
					'description' => __( 'POS handling type for the gateway.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'capabilities'  => array(
					'description' => __( 'POS capabilities exposed by the gateway.', 'woocommerce-pos' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'supports_checkout'           => array(
							'type'    => 'boolean',
							'context' => array( 'view' ),
						),
						'supports_automatic_refunds'  => array(
							'type'    => 'boolean',
							'context' => array( 'view' ),
						),
						'supports_provider_refunds'   => array(
							'type'    => 'boolean',
							'context' => array( 'view' ),
						),
						'requires_hardware'           => array(
							'type'    => 'boolean',
							'context' => array( 'view' ),
						),
					),
				),
				'provider_data' => array(
					'description' => __( 'Provider-specific public metadata.', 'woocommerce-pos' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}
