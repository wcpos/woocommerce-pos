<?php
/**
 * POS gateway bootstrap controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * POS gateway bootstrap controller.
 */
class Gateway_Bootstrap_Controller extends WC_REST_Controller {
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
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<gateway_id>[a-zA-Z0-9_-]+)/bootstrap',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'context' => array(
							'type'              => 'array',
							'validate_callback' => array( $this, 'validate_context_param' ),
							'sanitize_callback' => array( $this, 'sanitize_context_param' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Create permissions check.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot bootstrap payment gateways.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Return bootstrap data for a gateway.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item( $request ) {
		$gateway_id = (string) $request['gateway_id'];
		$gateway    = $this->get_gateway( $gateway_id );

		if ( ! $gateway ) {
			return new WP_Error(
				'wcpos_payment_gateway_not_found',
				/* translators: REST API schema field label or error message. */
				__( 'Payment gateway not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$context = (array) $request->get_param( 'context' );
		$data    = $this->gateway_contract->get_bootstrap_response( $gateway_id, $context, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Validate bootstrap context input.
	 *
	 * @param mixed $value Context value.
	 */
	public function validate_context_param( $value ): bool {
		return is_array( $value );
	}

	/**
	 * Sanitize bootstrap context input.
	 *
	 * @param mixed $value Context value.
	 *
	 * @return array
	 */
	public function sanitize_context_param( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Get a gateway by ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	private function get_gateway( string $gateway_id ): ?WC_Payment_Gateway {
		WC()->payment_gateways();
		$gateways = WC()->payment_gateways->payment_gateways();

		return $gateways[ $gateway_id ] ?? null;
	}
}
