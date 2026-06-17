<?php
/**
 * Filter-backed POS payment gateway adapter.
 *
 * @package WCPOS\WooCommercePOS\Payments
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_Payment_Gateway;
use WP_REST_Request;

/**
 * Bridges the PHP adapter interface to the frozen WordPress filter contract.
 */
class Filter_Gateway_Adapter implements Gateway_Adapter_Interface {
	/**
	 * Built-in gateways with manual POS handling.
	 */
	private const MANUAL_GATEWAYS = array( 'pos_cash', 'pos_card' );

	/**
	 * Wrapped WooCommerce gateway.
	 *
	 * @var WC_Payment_Gateway
	 */
	private $gateway;

	/**
	 * Direct POS gateway adapter, when the gateway implements it itself.
	 *
	 * @var Gateway_Adapter_Interface|null
	 */
	private $direct_adapter;

	/**
	 * Constructor.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 */
	public function __construct( WC_Payment_Gateway $gateway ) {
		$this->gateway        = $gateway;
		$this->direct_adapter = $gateway instanceof Gateway_Adapter_Interface ? $gateway : null;
	}

	/**
	 * Provider family identifier.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider( ?WP_REST_Request $request = null ): string {
		$default = $this->direct_adapter ? $this->direct_adapter->get_pos_provider( $request ) : $this->gateway->id;

		return (string) apply_filters( 'wcpos_payment_gateway_provider', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * POS handling type.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_type( ?WP_REST_Request $request = null ): string {
		$default = $this->direct_adapter ? $this->direct_adapter->get_pos_type( $request ) : 'manual';

		return (string) apply_filters( 'wcpos_payment_gateway_pos_type', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Provider-specific public metadata.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_provider_data( ?WP_REST_Request $request = null ): array {
		$default = $this->direct_adapter ? $this->direct_adapter->get_pos_provider_data( $request ) : array();

		return (array) apply_filters( 'wcpos_payment_gateway_provider_data', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Whether the gateway supports the POS checkout flow.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_checkout( ?WP_REST_Request $request = null ): bool {
		$has_handler = false !== has_action( 'wcpos_process_checkout_action_' . $this->gateway->id );
		$default     = $this->direct_adapter ? $this->direct_adapter->supports_pos_checkout( $request ) : $has_handler;

		return (bool) apply_filters( 'wcpos_payment_gateway_supports_checkout', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Whether POS may initiate automatic refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_automatic_refunds( ?WP_REST_Request $request = null ): bool {
		$default = $this->direct_adapter
			? $this->direct_adapter->supports_pos_automatic_refunds( $request )
			: $this->get_default_refund_support();

		return (bool) apply_filters( 'wcpos_payment_gateway_supports_automatic_refunds', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Whether POS may initiate provider refunds for this gateway.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function supports_pos_provider_refunds( ?WP_REST_Request $request = null ): bool {
		$default = $this->direct_adapter
			? $this->direct_adapter->supports_pos_provider_refunds( $request )
			: $this->get_default_refund_support();

		return (bool) apply_filters( 'wcpos_payment_gateway_supports_provider_refunds', $default, $this->gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Bootstrap response for the POS app.
	 *
	 * @param array                $context Bootstrap context.
	 * @param WP_REST_Request|null $request Request object.
	 */
	public function get_pos_bootstrap_response( array $context, ?WP_REST_Request $request = null ): array {
		$default = $this->direct_adapter
			? $this->direct_adapter->get_pos_bootstrap_response( $context, $request )
			: array(
				'gateway_id'    => $this->gateway->id,
				'status'        => 'ready',
				'expires_at'    => null,
				'provider_data' => array(),
			);

		return (array) apply_filters( 'wcpos_payment_gateway_bootstrap', $default, $this->gateway->id, $context, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

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
	public function process_pos_checkout_action( array $state, string $action, array $payment_data, WC_Order $order, ?WP_REST_Request $request = null ) {
		$hook = 'wcpos_process_checkout_action_' . $this->gateway->id;

		if ( $this->direct_adapter && false === $this->get_direct_checkout_shim_priority( $hook ) ) {
			$state = $this->direct_adapter->process_pos_checkout_action( $state, $action, $payment_data, $order, $request );

			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS checkout contract filter.
		return apply_filters( $hook, $state, $action, $payment_data, $order, $request );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Get this adapter's base-class compatibility shim priority, when present.
	 *
	 * @param string $hook Checkout hook name.
	 *
	 * @return int|false
	 */
	private function get_direct_checkout_shim_priority( string $hook ) {
		if ( ! $this->direct_adapter || ! method_exists( $this->direct_adapter, 'wcpos_process_checkout_action' ) ) {
			return false;
		}

		return has_filter( $hook, array( $this->direct_adapter, 'wcpos_process_checkout_action' ) );
	}

	/**
	 * Default refund support for the gateway.
	 */
	private function get_default_refund_support(): bool {
		return ! in_array( $this->gateway->id, self::MANUAL_GATEWAYS, true ) && $this->gateway->supports( 'refunds' );
	}
}
