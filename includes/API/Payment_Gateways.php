<?php
/**
 * POS payment gateways controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Payment_Gateways_Controller' ) ) {
	return;
}

use WC_Payment_Gateway;
use WC_REST_Payment_Gateways_Controller;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POS payment gateways controller.
 */
class Payment_Gateways extends WC_REST_Payment_Gateways_Controller {
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
	 * Read permissions for payment gateway catalog.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' );
	}

	/**
	 * Return the POS payment gateway catalog.
	 *
	 * @param WP_REST_Request $request Request object.
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
	 * Prepare gateway item for response.
	 *
	 * @param WC_Payment_Gateway $item    Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$response   = parent::prepare_item_for_response( $item, $request );
		$data       = $response->get_data();
		$data['enabled']       = $this->gateway_contract->is_pos_enabled( $item );
		$data['provider']      = $this->gateway_contract->get_provider( $item, $request );
		$data['pos_type']      = $this->gateway_contract->infer_pos_type( $item, $request );
		$data['capabilities']  = $this->gateway_contract->get_capabilities( $item, $request );
		$data['provider_data'] = $this->gateway_contract->get_provider_data( $item, $request );

		$response->set_data( $data );

		return $response;
	}
}
