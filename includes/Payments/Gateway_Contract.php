<?php
/**
 * POS gateway contract helper.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_Payment_Gateway;
use WP_Error;
use WP_REST_Request;

/**
 * Shared helper for the POS payment-gateway contract.
 */
class Gateway_Contract {
	/**
	 * Infer POS type for a gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function infer_pos_type( WC_Payment_Gateway $gateway, WP_REST_Request $request ): string {
		return $this->get_adapter( $gateway )->get_pos_type( $request );
	}

	/**
	 * Provider family identifier.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_provider( WC_Payment_Gateway $gateway, WP_REST_Request $request ): string {
		return $this->get_adapter( $gateway )->get_pos_provider( $request );
	}

	/**
	 * Provider-specific public metadata.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_provider_data( WC_Payment_Gateway $gateway, WP_REST_Request $request ): array {
		return $this->get_adapter( $gateway )->get_pos_provider_data( $request );
	}

	/**
	 * Whether a gateway is enabled for POS.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 */
	public function is_pos_enabled( WC_Payment_Gateway $gateway ): bool {
		$settings = woocommerce_pos_get_settings( 'payment_gateways' );

		if ( is_wp_error( $settings ) ) {
			return wc_string_to_bool( $gateway->enabled );
		}

		$pos_setting = $settings['gateways'][ $gateway->id ] ?? array();

		return isset( $pos_setting['enabled'] ) ? (bool) $pos_setting['enabled'] : wc_string_to_bool( $gateway->enabled );
	}

	/**
	 * Whether a gateway supports the POS checkout contract.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function supports_checkout( WC_Payment_Gateway $gateway, WP_REST_Request $request ): bool {
		return $this->get_adapter( $gateway )->supports_pos_checkout( $request );
	}

	/**
	 * Capabilities exposed to the POS app.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_capabilities( WC_Payment_Gateway $gateway, WP_REST_Request $request ): array {
		$adapter  = $this->get_adapter( $gateway );
		$pos_type = $adapter->get_pos_type( $request );

		return array(
			'supports_checkout'          => $adapter->supports_pos_checkout( $request ),
			'supports_automatic_refunds' => $adapter->supports_pos_automatic_refunds( $request ),
			'supports_provider_refunds'  => $adapter->supports_pos_provider_refunds( $request ),
			'requires_hardware'          => 'terminal' === $pos_type,
		);
	}

	/**
	 * Default bootstrap response.
	 *
	 * The optional gateway parameter allows direct PHP adapters to provide the
	 * response while preserving the existing public method signature for callers
	 * that only have a gateway ID and depend on the legacy filter contract.
	 *
	 * @param string                  $gateway_id Gateway ID.
	 * @param array                   $context    Bootstrap context.
	 * @param WP_REST_Request         $request    Request object.
	 * @param WC_Payment_Gateway|null $gateway    Gateway object.
	 */
	public function get_bootstrap_response( string $gateway_id, array $context, WP_REST_Request $request, ?WC_Payment_Gateway $gateway = null ): array {
		if ( $gateway instanceof WC_Payment_Gateway ) {
			return $this->get_adapter( $gateway )->get_pos_bootstrap_response( $context, $request );
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
		return (array) apply_filters(
			'wcpos_payment_gateway_bootstrap',
			array(
				'gateway_id'    => $gateway_id,
				'status'        => 'ready',
				'expires_at'    => null,
				'provider_data' => array(),
			),
			$gateway_id,
			$context,
			$request
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Process a POS checkout action through the gateway adapter.
	 *
	 * @param WC_Payment_Gateway $gateway      Gateway object.
	 * @param int                $order_id     Order ID.
	 * @param string             $action       Checkout action.
	 * @param array              $payment_data Payment data.
	 * @param WC_Order           $order        Order object.
	 * @param WP_REST_Request    $request      Request object.
	 *
	 * @return array|WP_Error
	 */
	public function process_checkout_action( WC_Payment_Gateway $gateway, int $order_id, string $action, array $payment_data, WC_Order $order, WP_REST_Request $request ) {
		$state = array(
			'checkout_id'   => wp_generate_uuid4(),
			'order_id'      => $order_id,
			'gateway_id'    => $gateway->id,
			'status'        => 'processing',
			'provider_data' => array(),
			'terminal'      => false,
		);

		return $this->get_adapter( $gateway )->process_pos_checkout_action( $state, $action, $payment_data, $order, $request );
	}

	/**
	 * Whether a checkout status is terminal.
	 *
	 * @param string $status Checkout status.
	 */
	public function is_terminal_status( string $status ): bool {
		return in_array( $status, array( 'completed', 'failed', 'cancelled', 'awaiting_customer' ), true );
	}

	/**
	 * Wrap a WooCommerce gateway with the POS adapter shim.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 */
	private function get_adapter( WC_Payment_Gateway $gateway ): Gateway_Adapter_Interface {
		return new Filter_Gateway_Adapter( $gateway );
	}
}
