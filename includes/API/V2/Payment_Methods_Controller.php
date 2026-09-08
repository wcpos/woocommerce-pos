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
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)/bootstrap',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'bootstrap' ),
				'permission_callback' => array( $this, 'bootstrap_permissions_check' ),
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

	/**
	 * Check access to the bootstrap route.
	 *
	 * Reading the inventory needs only the baseline gate, but bootstrap mints provider
	 * material, so it takes the same capability as the order payment routes.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function bootstrap_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot bootstrap payment methods.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Mint provider material without an order or order lock.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function bootstrap( $request ) {
		$builder = Descriptor_Builder::instance();
		$descriptor = $builder->get( (string) $request['id'] );
		if ( ! $descriptor ) {
			return new WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$handler = Capture_Mode_Registry::instance()->resolve( $descriptor['capture']['mode'], $descriptor['capture']['provider'] );
		if ( ! $handler ) {
			return new WP_Error( 'wcpos_capture_mode_unsupported', __( 'Payment capture mode is unsupported.', 'woocommerce-pos' ), array( 'status' => 501 ) );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$context = $params['context'] ?? array();
		if ( ! is_array( $context ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Payment context must be an object.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$result = $handler->bootstrap( $builder->gateway( $descriptor['id'] ), $context );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
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
