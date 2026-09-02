<?php
/** Payment method descriptor builder. */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\Logger;

/** Builds the v1 payment method contract from registered gateways. */
class Descriptor_Builder {
	/** @var self|null */
	private static $instance = null;

	/** @var array<string, bool> */
	private static $logged_missing_modes = array();

	/** Get the shared builder. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Build the descriptor envelope for every real gateway. */
	public function all(): array {
		WC()->payment_gateways();
		$settings = (array) wcpos_get_settings( 'payment_gateways' );
		$methods  = array();
		foreach ( WC()->payment_gateways->payment_gateways() as $id => $gateway ) {
			if ( ! $gateway instanceof WC_Payment_Gateway || 'pre_install_woocommerce_payments_promotion' === $id ) {
				continue;
			}
			$methods[] = $this->build( $gateway, $settings );
		}
		usort(
			$methods,
			static function ( array $left, array $right ): int {
				return $left['order'] === $right['order'] ? strcmp( $left['id'], $right['id'] ) : $left['order'] <=> $right['order'];
			}
		);

		return array( 'schema' => 1, 'contract' => '1.0', 'methods' => $methods );
	}

	/** Build one gateway descriptor. */
	public function get( string $gateway_id ): ?array {
		$gateway = $this->gateway( $gateway_id );
		return $gateway ? $this->build( $gateway, (array) wcpos_get_settings( 'payment_gateways' ) ) : null;
	}

	/** Look up a registered gateway. */
	public function gateway( string $id ): ?WC_Payment_Gateway {
		WC()->payment_gateways();
		$gateways = WC()->payment_gateways->payment_gateways();
		return isset( $gateways[ $id ] ) && $gateways[ $id ] instanceof WC_Payment_Gateway ? $gateways[ $id ] : null;
	}

	/** Resolve and validate a gateway kind. */
	public static function resolve_kind( WC_Payment_Gateway $gateway ): string {
		$map  = array( 'pos_cash' => 'cash', 'pos_card' => 'card', 'cod' => 'cash', 'bacs' => 'bank_transfer', 'cheque' => 'other' );
		$kind = $map[ $gateway->id ] ?? 'other';

		/**
		 * Filters the POS payment method kind.
		 *
		 * @since 1.11.0
		 * @hook wcpos_payment_method_kind
		 *
		 * @param string             $kind    Payment method kind.
		 * @param WC_Payment_Gateway $gateway Gateway instance.
		 */
		$kind = apply_filters( 'wcpos_payment_method_kind', $kind, $gateway );
		if ( ! is_string( $kind ) || ! in_array( $kind, Ledger::KINDS, true ) ) {
			Logger::log( sprintf( 'Unknown WCPOS payment method kind returned for gateway "%s"; using "other".', $gateway->id ) );
			return 'other';
		}

		return $kind;
	}

	/** Resolve a gateway capture mode. */
	public static function resolve_mode( WC_Payment_Gateway $gateway ): string {
		$manual = array( 'pos_cash', 'pos_card', 'cod', 'bacs', 'cheque' );
		$mode   = in_array( $gateway->id, $manual, true ) ? 'manual' : 'webview';

		/**
		 * Filters the POS payment method capture mode.
		 *
		 * @since 1.11.0
		 * @hook wcpos_payment_method_capture_mode
		 *
		 * @param string             $mode    Capture mode.
		 * @param WC_Payment_Gateway $gateway Gateway instance.
		 */
		return (string) apply_filters( 'wcpos_payment_method_capture_mode', $mode, $gateway );
	}

	/** Assemble one descriptor in wire-key order. */
	private function build( WC_Payment_Gateway $gateway, array $settings ): array {
		$gateway_settings = (array) ( $settings['gateways'][ $gateway->id ] ?? array() );
		$kind             = self::resolve_kind( $gateway );
		$mode             = self::resolve_mode( $gateway );
		$registry         = Capture_Mode_Registry::instance();
		$handler          = $registry->get( $mode );
		if ( ! $handler ) {
			if ( empty( self::$logged_missing_modes[ $mode ] ) ) {
				Logger::log( sprintf( 'No WCPOS capture-mode handler registered for "%s"; using webview.', $mode ) );
				self::$logged_missing_modes[ $mode ] = true;
			}
			$mode    = 'webview';
			$handler = $registry->get( $mode );
		}
		$described    = $handler ? $handler->describe( $gateway ) : array();
		$capture      = (array) ( $described['capture'] ?? array() );
		$capabilities = (array) ( $described['capabilities'] ?? array() );
		$provider     = $capture['provider'] ?? null;
		$title        = isset( $gateway_settings['title'] ) && is_string( $gateway_settings['title'] ) && '' !== $gateway_settings['title']
			? $gateway_settings['title'] : $gateway->get_title();
		$order_status = isset( $gateway_settings['order_status'] ) && is_string( $gateway_settings['order_status'] )
			? $gateway_settings['order_status'] : 'completed';
		$order_status = 0 === strpos( $order_status, 'wc-' ) ? substr( $order_status, 3 ) : $order_status;
		$provider_data = (array) ( $described['provider_data'] ?? array() );
		$amount        = array( 'partial' => (bool) ( $capabilities['amount']['partial'] ?? false ) );
		foreach ( array( 'min', 'max' ) as $bound ) {
			if ( isset( $capabilities['amount'][ $bound ] ) && is_numeric( $capabilities['amount'][ $bound ] ) ) {
				$amount[ $bound ] = Money::normalize( $capabilities['amount'][ $bound ] );
			}
		}

		return array(
			'schema'        => 1,
			'id'            => $gateway->id,
			'title'         => $title,
			'kind'          => $kind,
			'pos_enabled'   => (bool) ( $gateway_settings['enabled'] ?? false ),
			'order'         => (int) ( $gateway_settings['order'] ?? 999 ),
			'capture'       => array(
				'mode'              => isset( $capture['mode'] ) ? (string) $capture['mode'] : $mode,
				'provider'          => is_string( $provider ) ? $provider : null,
				'hardware'          => isset( $capture['hardware'] ) && is_array( $capture['hardware'] ) ? $capture['hardware'] : null,
				'webview_available' => (bool) ( $capture['webview_available'] ?? false ),
			),
			'capabilities'  => array(
				'amount'  => $amount,
				'change'  => (bool) ( $capabilities['change'] ?? false ),
				'refunds' => array(
					'via'     => (string) ( $capabilities['refunds']['via'] ?? 'none' ),
					'partial' => (bool) ( $capabilities['refunds']['partial'] ?? false ),
				),
				'tips'    => (string) ( $capabilities['tips'] ?? 'none' ),
				'offline' => (string) ( $capabilities['offline'] ?? 'none' ),
				'void'    => (bool) ( $capabilities['void'] ?? false ),
			),
			'defaults'      => array( 'order_status' => $order_status ?: 'completed', 'rounding' => null, 'open_drawer' => 'cash' === $kind ),
			'provider_data' => empty( $provider_data ) ? new \stdClass() : $provider_data,
		);
	}
}
