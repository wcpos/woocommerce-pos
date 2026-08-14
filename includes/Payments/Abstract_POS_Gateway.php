<?php
/**
 * Base class for POS payment gateway adapters.
 *
 * @package WCPOS\WooCommercePOS\Payments
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_Payment_Gateway;
use WP_REST_Request;

/**
 * WC gateway base that also exposes the POS gateway adapter interface.
 */
abstract class Abstract_POS_Gateway extends WC_Payment_Gateway implements Gateway_Adapter_Interface {
	/**
	 * Register the legacy public filter contract for compatibility.
	 */
	protected function register_pos_gateway_contract_hooks(): void {
		add_filter( 'wcpos_payment_gateway_provider', array( $this, 'wcpos_provider' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_pos_type', array( $this, 'wcpos_pos_type' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_provider_data', array( $this, 'wcpos_provider_data' ), 10, 3 );
		add_filter( 'wcpos_payment_gateway_bootstrap', array( $this, 'wcpos_bootstrap' ), 10, 4 );
		add_filter( 'wcpos_process_checkout_action_' . $this->id, array( $this, 'wcpos_process_checkout_action' ), 10, 5 );
	}

	/**
	 * Provider family identifier.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider( ?WP_REST_Request $request = null ): string {
		return $this->id;
	}

	/**
	 * POS handling type.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_type( ?WP_REST_Request $request = null ): string {
		return 'manual';
	}

	/**
	 * Provider-specific public metadata.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider_data( ?WP_REST_Request $request = null ): array {
		return array();
	}

	/**
	 * Whether the gateway supports the POS checkout flow.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_checkout( ?WP_REST_Request $request = null ): bool {
		return true;
	}

	/**
	 * Whether POS may initiate automatic refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_automatic_refunds( ?WP_REST_Request $request = null ): bool {
		return false;
	}

	/**
	 * Whether POS may initiate provider refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_provider_refunds( ?WP_REST_Request $request = null ): bool {
		return false;
	}

	/**
	 * Bootstrap response for the POS app.
	 *
	 * @param array                $context Bootstrap context.
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_bootstrap_response( array $context, ?WP_REST_Request $request = null ): array {
		return array(
			'gateway_id'    => $this->id,
			'status'        => 'ready',
			'expires_at'    => null,
			'provider_data' => $this->get_pos_provider_data( $request ),
		);
	}

	/**
	 * Legacy provider filter callback.
	 *
	 * @param string $provider Provider value.
	 * @param mixed  $gateway  Gateway object.
	 * @param mixed  $request  REST request.
	 */
	public function wcpos_provider( $provider, $gateway, $request = null ) {
		if ( $this->matches_gateway( $gateway ) ) {
			return $this->get_pos_provider( $request instanceof WP_REST_Request ? $request : null );
		}

		return $provider;
	}

	/**
	 * Legacy POS type filter callback.
	 *
	 * @param string $pos_type POS type.
	 * @param mixed  $gateway  Gateway object.
	 * @param mixed  $request  REST request.
	 */
	public function wcpos_pos_type( $pos_type, $gateway, $request = null ) {
		if ( $this->matches_gateway( $gateway ) ) {
			return $this->get_pos_type( $request instanceof WP_REST_Request ? $request : null );
		}

		return $pos_type;
	}

	/**
	 * Legacy provider-data filter callback.
	 *
	 * @param array $provider_data Provider data.
	 * @param mixed $gateway       Gateway object.
	 * @param mixed $request       REST request.
	 */
	public function wcpos_provider_data( $provider_data, $gateway, $request = null ) {
		if ( $this->matches_gateway( $gateway ) ) {
			return $this->get_pos_provider_data( $request instanceof WP_REST_Request ? $request : null );
		}

		return $provider_data;
	}

	/**
	 * Legacy bootstrap filter callback.
	 *
	 * @param array  $response   Bootstrap response.
	 * @param string $gateway_id Gateway ID.
	 * @param array  $context    Bootstrap context.
	 * @param mixed  $request    REST request.
	 */
	public function wcpos_bootstrap( $response, $gateway_id, $context = array(), $request = null ) {
		if ( $this->id === $gateway_id ) {
			return $this->get_pos_bootstrap_response( $context, $request instanceof WP_REST_Request ? $request : null );
		}

		return $response;
	}

	/**
	 * Legacy checkout-action filter callback.
	 *
	 * @param array|\WP_Error $state        Checkout state.
	 * @param string          $action       Checkout action.
	 * @param array           $payment_data Payment data.
	 * @param WC_Order        $order        Order object.
	 * @param mixed           $request      REST request.
	 *
	 * @return array|\WP_Error
	 */
	public function wcpos_process_checkout_action( $state, $action, $payment_data, $order, $request = null ) {
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ! is_array( $state ) || ( $state['gateway_id'] ?? '' ) !== $this->id || ! $order instanceof WC_Order ) {
			return $state;
		}

		return $this->process_pos_checkout_action(
			$state,
			(string) $action,
			$payment_data,
			$order,
			$request instanceof WP_REST_Request ? $request : null
		);
	}

	/**
	 * Whether the filtered gateway is this adapter's gateway.
	 *
	 * @param mixed $gateway Gateway object.
	 */
	private function matches_gateway( $gateway ): bool {
		return $gateway instanceof WC_Payment_Gateway && $this->id === $gateway->id;
	}
}
