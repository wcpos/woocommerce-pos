<?php

namespace WCPOS\WooCommercePOS\API;

use WC_Payment_Gateway;
use WP_REST_Request;
use WP_REST_Response;

class Payment_Gateways {
	/* @var WP_REST_Request $request */
	private $request;

	/* @var $settings */
	private $settings;

	/**
	 * Payment Gateways constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->settings = woocommerce_pos_get_settings( 'payment_gateways' );

		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'check_permissions' ), 10, 4 );
		add_filter( 'woocommerce_rest_prepare_payment_gateway', array( $this, 'prepare_payment_gateway' ), 10, 3 );
	}

	/**
	 * Authorize payment_gateways API (read only) for cashiers.
	 *
	 * @param mixed $permission
	 * @param mixed $context
	 * @param mixed $object_id
	 * @param mixed $object
	 */
	public function check_permissions( $permission, $context, $object_id, $object ) {
		if ( ! $permission && 'payment_gateways' === $object && 'read' === $context ) {
			$permission = current_user_can( 'publish_shop_orders' );
		}

		return $permission;
	}

	/**
	 * Filter payment gateway objects returned from the REST API.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Payment_Gateway $gateway  Payment gateway object.
	 * @param WP_REST_Request $request  Request object.
	 */
	public function prepare_payment_gateway( WP_REST_Response $response, WC_Payment_Gateway $gateway, WP_REST_Request $request ): WP_REST_Response {
		$pos_setting = $this->settings['gateways'][ $gateway->id ] ?? null;
		$data  = $response->get_data();

		if ( $pos_setting ) {
			$data['enabled'] = $pos_setting['enabled'];
			$data['order']   = $pos_setting['order'];
		} else {
			$data['enabled'] = false;
		}

		$response->set_data( $data );

		return $response;
	}
}
