<?php
/**
 * POS payment gateway adapter interface.
 *
 * @package WCPOS\WooCommercePOS\Payments
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WP_REST_Request;

/**
 * Declares the POS-facing payment gateway adapter contract.
 */
interface Gateway_Adapter_Interface {
	/**
	 * Provider family identifier.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider( ?WP_REST_Request $request = null ): string;

	/**
	 * POS handling type.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_type( ?WP_REST_Request $request = null ): string;

	/**
	 * Provider-specific public metadata.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider_data( ?WP_REST_Request $request = null ): array;

	/**
	 * Whether the gateway supports the POS checkout flow.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_checkout( ?WP_REST_Request $request = null ): bool;

	/**
	 * Whether POS may initiate automatic refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_automatic_refunds( ?WP_REST_Request $request = null ): bool;

	/**
	 * Whether POS may initiate provider refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_provider_refunds( ?WP_REST_Request $request = null ): bool;

	/**
	 * Bootstrap response for the POS app.
	 *
	 * @param array                $context Bootstrap context.
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_bootstrap_response( array $context, ?WP_REST_Request $request = null ): array;

	/**
	 * Process a POS checkout action.
	 *
	 * @param array                $state        Checkout state.
	 * @param string               $action       Checkout action.
	 * @param array                $payment_data Payment data.
	 * @param WC_Order             $order        Order object.
	 * @param WP_REST_Request|null $request      Request object.
	 *
	 * @return array|\WP_Error
	 */
	public function process_pos_checkout_action( array $state, string $action, array $payment_data, WC_Order $order, ?WP_REST_Request $request = null );
}
