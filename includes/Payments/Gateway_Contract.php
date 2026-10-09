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
	 * Statuses that are never a settled outcome — see get_settled_order_status().
	 *
	 * @var string[]
	 */
	private const NEVER_SETTLED_STATUSES = array( 'pos-open', 'pos-partial', 'failed', 'cancelled' );

	/**
	 * Human-readable gateway name for POS and settings display.
	 *
	 * Falls back from the public title to the admin method title to the id,
	 * because some gateways never assign a public title.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 */
	public function get_display_title( WC_Payment_Gateway $gateway ): string {
		foreach ( array( $gateway->get_title(), $gateway->method_title ) as $title ) {
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return $title;
			}
		}

		return (string) $gateway->id;
	}

	/**
	 * Gateway description for POS and settings display, falling back to the
	 * admin method description.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 */
	public function get_display_description( WC_Payment_Gateway $gateway ): string {
		foreach ( array( $gateway->get_description(), $gateway->method_description ) as $description ) {
			if ( is_string( $description ) && '' !== trim( $description ) ) {
				return $description;
			}
		}

		return '';
	}

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
	 * The order status a gateway settles a POS sale to, as the merchant configured it.
	 *
	 * This is the merchant's intent, not WooCommerce's notion of "paid": a gateway
	 * configured to land on Pending payment (a "pay by invoice" flow, where the
	 * customer still pays through the web pay link) or On hold (a bank transfer
	 * awaiting confirmation) has finished its part of the sale once the order
	 * reaches that status, even though is_paid() says otherwise. The received
	 * page and the POS catalog both read this so the till can close the sale,
	 * while an async gateway configured to land on Completed that redirects
	 * early still waits — a pending order has not reached *its* status.
	 *
	 * Only an explicitly stored status on a gateway enabled for POS counts (see
	 * get_stored_order_status()). The value is normalised to the form order
	 * statuses carry at runtime, without the `wc-` prefix, and validated
	 * against the registered statuses.
	 *
	 * Four statuses can never be a settled outcome, whatever is stored: the
	 * parked POS statuses are open carts, and `failed`/`cancelled` are a
	 * payment that did not happen. The settings picker offers every registered
	 * status, so a stored `failed` is reachable through the UI; honouring it
	 * would let a failed payment emit the payment-received message and close
	 * the till on a sale that must stay open for a retry.
	 *
	 * @param string $gateway_id Gateway id.
	 *
	 * @return string The settled status without the `wc-` prefix, or '' when none is configured.
	 */
	public function get_settled_order_status( string $gateway_id ): string {
		$stored = $this->get_stored_order_status( $gateway_id );

		if ( '' === $stored ) {
			return '';
		}

		$status = 0 === strpos( $stored, 'wc-' ) ? substr( $stored, 3 ) : $stored;

		if ( '' === $status || \in_array( $status, self::NEVER_SETTLED_STATUSES, true ) ) {
			return '';
		}

		foreach ( array_keys( wc_get_order_statuses() ) as $registered ) {
			$registered = 0 === strpos( $registered, 'wc-' ) ? substr( $registered, 3 ) : $registered;

			if ( $registered === $status ) {
				return $status;
			}
		}

		return '';
	}

	/**
	 * Read the explicitly stored per-gateway order status.
	 *
	 * Reads the raw options rather than the settings service, because the service
	 * rebuilds its view from the installed gateways and synthesizes a default
	 * status for gateways the merchant has never configured. Only a status the
	 * merchant actually chose, on a gateway they enabled for POS, counts as intent.
	 *
	 * Two places hold such a choice, matching Payment_Gateways_Section::read():
	 * the per-gateway entry, and — on sites upgraded from before per-gateway
	 * statuses — the legacy global `checkout.order_status`, which that section
	 * still applies in memory to any gateway with no explicit status of its own
	 * until the merchant next saves.
	 *
	 * @param string $gateway_id The payment gateway ID.
	 *
	 * @return string The stored status (may include the wc- prefix), or '' when absent.
	 */
	public function get_stored_order_status( string $gateway_id ): string {
		if ( '' === $gateway_id ) {
			return '';
		}

		$stored = get_option( 'woocommerce_pos_settings_payment_gateways', array() );

		if ( ! \is_array( $stored ) || ! isset( $stored['gateways'][ $gateway_id ] ) || ! \is_array( $stored['gateways'][ $gateway_id ] ) ) {
			return '';
		}

		$gateway = $stored['gateways'][ $gateway_id ];

		if ( ! isset( $gateway['enabled'] ) || ! wc_string_to_bool( $gateway['enabled'] ) ) {
			return '';
		}

		if ( isset( $gateway['order_status'] ) && \is_string( $gateway['order_status'] ) && '' !== $gateway['order_status'] ) {
			return $gateway['order_status'];
		}

		$checkout = get_option( 'woocommerce_pos_settings_checkout', array() );

		return \is_array( $checkout ) && isset( $checkout['order_status'] ) && \is_string( $checkout['order_status'] )
			? $checkout['order_status']
			: '';
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
