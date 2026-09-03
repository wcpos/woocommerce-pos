<?php
/**
 * Payment methods contract controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

\defined( 'ABSPATH' ) || die;

use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Exposes the payment methods contract envelope. */
class Payment_Methods_Controller extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'payment-methods';

	/** Register the payment methods collection route. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check access to the payment methods contract.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'access_woocommerce_pos' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view payment methods.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Return all payment method descriptors.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		return rest_ensure_response( Descriptor_Builder::instance()->all() );
	}

	/** Return the payment methods envelope schema. */
	public function get_item_schema(): array {
		$descriptor = array(
			'type'       => 'object',
			'properties' => array(
				'schema'        => array( 'type' => 'integer' ),
				'id'            => array( 'type' => 'string' ),
				'title'         => array( 'type' => 'string' ),
				'kind'          => array( 'type' => 'string' ),
				'pos_enabled'   => array( 'type' => 'boolean' ),
				'order'         => array( 'type' => 'integer' ),
				'capture'       => array( 'type' => 'object' ),
				'capabilities'  => array( 'type' => 'object' ),
				'defaults'      => array( 'type' => 'object' ),
				'provider_data' => array( 'type' => 'object' ),
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wcpos_payment_methods',
			'type'       => 'object',
			'properties' => array(
				'schema'   => array( 'type' => 'integer' ),
				'contract' => array( 'type' => 'string' ),
				'methods'  => array(
					'type'  => 'array',
					'items' => $descriptor,
				),
			),
		);
	}
}
