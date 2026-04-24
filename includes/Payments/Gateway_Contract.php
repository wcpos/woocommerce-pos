<?php
/**
 * POS gateway contract helper.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WP_REST_Request;

/**
 * Shared helper for the POS payment-gateway contract.
 */
class Gateway_Contract {
	/**
	 * Built-in gateways with manual POS handling.
	 */
	private const MANUAL_GATEWAYS = array( 'pos_cash', 'pos_card' );

	/**
	 * Infer POS type for a gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function infer_pos_type( WC_Payment_Gateway $gateway, WP_REST_Request $request ): string {
		$default = in_array( $gateway->id, self::MANUAL_GATEWAYS, true ) ? 'manual' : 'manual';

		return (string) apply_filters( 'wcpos_payment_gateway_pos_type', $default, $gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Provider family identifier.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_provider( WC_Payment_Gateway $gateway, WP_REST_Request $request ): string {
		return (string) apply_filters( 'wcpos_payment_gateway_provider', $gateway->id, $gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Provider-specific public metadata.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_provider_data( WC_Payment_Gateway $gateway, WP_REST_Request $request ): array {
		return (array) apply_filters( 'wcpos_payment_gateway_provider_data', array(), $gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
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
		$has_handler = false !== has_action( 'wcpos_process_checkout_action_' . $gateway->id );

		return (bool) apply_filters( 'wcpos_payment_gateway_supports_checkout', $has_handler, $gateway, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
	}

	/**
	 * Capabilities exposed to the POS app.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object.
	 * @param WP_REST_Request    $request Request object.
	 */
	public function get_capabilities( WC_Payment_Gateway $gateway, WP_REST_Request $request ): array {
		$pos_type                  = $this->infer_pos_type( $gateway, $request );
		$supports_provider_refunds = ! in_array( $gateway->id, self::MANUAL_GATEWAYS, true ) && $gateway->supports( 'refunds' );

		return array(
			'supports_checkout'          => $this->supports_checkout( $gateway, $request ),
			'supports_automatic_refunds' => (bool) apply_filters( 'wcpos_payment_gateway_supports_automatic_refunds', $supports_provider_refunds, $gateway, $request ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
			'supports_provider_refunds'  => (bool) apply_filters( 'wcpos_payment_gateway_supports_provider_refunds', $supports_provider_refunds, $gateway, $request ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS gateway contract filter.
			'requires_hardware'          => 'terminal' === $pos_type,
		);
	}

	/**
	 * Default bootstrap response.
	 *
	 * @param string          $gateway_id Gateway ID.
	 * @param array           $context    Bootstrap context.
	 * @param WP_REST_Request $request    Request object.
	 */
	public function get_bootstrap_response( string $gateway_id, array $context, WP_REST_Request $request ): array {
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
	 * Whether a checkout status is terminal.
	 *
	 * @param string $status Checkout status.
	 */
	public function is_terminal_status( string $status ): bool {
		return in_array( $status, array( 'completed', 'failed', 'cancelled', 'awaiting_customer' ), true );
	}
}
