<?php
/**
 * Payment method descriptor builder.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\Gateways;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Settings\Payment_Gateways_Section;

/** Builds the v1 payment method contract from registered gateways. */
class Descriptor_Builder {
	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Modes already logged as missing.
	 *
	 * @var array<string, bool>
	 */
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
		$settings = (array) wcpos_get_settings( 'payment_gateways' );
		$methods  = array();
		foreach ( Gateways::registered() as $id => $gateway ) {
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

		return array(
			'schema' => 1,
			'contract' => '1.3',
			'methods' => $methods,
		);
	}

	/**
	 * Build one gateway descriptor.
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public function get( string $gateway_id ): ?array {
		$gateway = $this->gateway( $gateway_id );
		return $gateway ? $this->build( $gateway, (array) wcpos_get_settings( 'payment_gateways' ) ) : null;
	}

	/**
	 * Look up a registered gateway.
	 *
	 * @param string $id Gateway ID.
	 */
	public function gateway( string $id ): ?WC_Payment_Gateway {
		$gateways = Gateways::registered();
		return isset( $gateways[ $id ] ) && $gateways[ $id ] instanceof WC_Payment_Gateway ? $gateways[ $id ] : null;
	}

	/**
	 * Resolve and validate a gateway kind.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 */
	public static function resolve_kind( WC_Payment_Gateway $gateway ): string {
		$map  = array(
			'pos_cash' => 'cash',
			'pos_card' => 'card',
			'cod' => 'cash',
			'bacs' => 'bank_transfer',
			'cheque' => 'other',
		);
		$kind = $map[ $gateway->id ] ?? 'other';

		/**
		 * Filters the POS payment method kind.
		 *
		 * @since 2.0.0
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

	/**
	 * Resolve a gateway capture mode.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 */
	public static function resolve_mode( WC_Payment_Gateway $gateway ): string {
		$manual = array( 'pos_cash', 'pos_card', 'cod', 'bacs', 'cheque' );
		$mode   = in_array( $gateway->id, $manual, true ) ? 'manual' : 'webview';

		/**
		 * Filters the POS payment method capture mode.
		 *
		 * @since 2.0.0
		 * @hook wcpos_payment_method_capture_mode
		 *
		 * @param string             $mode    Capture mode.
		 * @param WC_Payment_Gateway $gateway Gateway instance.
		 */
		return (string) apply_filters( 'wcpos_payment_method_capture_mode', $mode, $gateway );
	}

	/**
	 * Look up an allowed connection and its capabilities.
	 *
	 * @param array  $descriptor Payment method descriptor.
	 * @param string $mode       Bare capture mode.
	 */
	public static function capture_entry( array $descriptor, string $mode ): ?array {
		foreach ( $descriptor['capture']['modes'] as $entry ) {
			if ( $mode === $entry['mode'] ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Assemble one descriptor in wire-key order.
	 *
	 * @param WC_Payment_Gateway $gateway  Gateway instance.
	 * @param array              $settings Payment gateway settings.
	 */
	private function build( WC_Payment_Gateway $gateway, array $settings ): array {
		$gateway_settings = (array) ( $settings['gateways'][ $gateway->id ] ?? array() );
		$kind             = self::resolve_kind( $gateway );
		$increment        = $gateway_settings['rounding_increment'] ?? '';
		$rounding         = 'cash' === $kind && '' !== $increment && in_array( $increment, Payment_Gateways_Section::ROUNDING_INCREMENTS, true )
			? array(
				'increment' => $increment,
				'mode'      => 'nearest',
			) : null;
		// The filter may answer with a provider-scoped key (`device:stripe`); the
		// wire mode stays bare and the provider only steers the handler lookup.
		$mode_keys = array( self::resolve_mode( $gateway ) );
		/**
		 * Filters the ordered capture modes allowed for a POS payment method.
		 *
		 * @since 2.0.0
		 * @hook wcpos_payment_method_capture_modes
		 *
		 * @param array              $mode_keys Capture mode keys, optionally provider-scoped.
		 * @param WC_Payment_Gateway $gateway   Gateway instance.
		 */
		// A filter answering a bare string (the singular hook's shape) must not fatal every payment.
		$mode_keys = array_filter( (array) apply_filters( 'wcpos_payment_method_capture_modes', $mode_keys, $gateway ), 'is_string' );
		$registry  = Capture_Mode_Registry::instance();
		$resolved  = array();
		foreach ( array_unique( $mode_keys ) as $mode_key ) {
			$mode_parts = explode( ':', $mode_key, 2 );
			$mode       = $mode_parts[0];
			$handler    = $registry->resolve( $mode, $mode_parts[1] ?? null );
			if ( ! $handler ) {
				if ( empty( self::$logged_missing_modes[ $mode ] ) ) {
					Logger::log( sprintf( 'No WCPOS capture-mode handler registered for "%s"; ignoring mode.', $mode ) );
					self::$logged_missing_modes[ $mode ] = true;
				}
				continue;
			}
			$resolved[ $mode_key ] = $handler->describe( $gateway );
		}
		if ( empty( $resolved ) ) {
			$handler = $registry->get( 'webview' );
			$resolved['webview'] = $handler ? $handler->describe( $gateway ) : array();
		}
		$modes = array();
		foreach ( $resolved as $key => $description ) {
			$entry = (array) ( $description['capture'] ?? array() );
			$bare  = isset( $entry['mode'] ) ? (string) $entry['mode'] : explode( ':', $key, 2 )[0];
			if ( in_array( $bare, array_column( $modes, 'mode' ), true ) ) {
				continue; // `device` and `device:stripe` name one connection; the till picks by bare mode.
			}
			$modes[] = array(
				'mode'     => $bare,
				'provider' => isset( $entry['provider'] ) && is_string( $entry['provider'] ) ? $entry['provider'] : null,
				'hardware' => isset( $entry['hardware'] ) && is_array( $entry['hardware'] ) ? $entry['hardware'] : null,
				'capabilities' => self::normalize_capabilities( (array) ( $description['capabilities'] ?? array() ) ),
				'provider_data' => (array) ( $description['provider_data'] ?? array() ),
			);
		}
		$mode         = $modes[0]['mode'];
		$described    = reset( $resolved );
		$capture      = (array) ( $described['capture'] ?? array() );
		$capabilities = (array) ( $described['capabilities'] ?? array() );
		$provider     = $capture['provider'] ?? null;
		$title        = isset( $gateway_settings['title'] ) && is_string( $gateway_settings['title'] ) && '' !== $gateway_settings['title']
			? $gateway_settings['title'] : $gateway->get_title();
		$order_status = isset( $gateway_settings['order_status'] ) && is_string( $gateway_settings['order_status'] )
			? $gateway_settings['order_status'] : 'completed';
		$order_status = 0 === strpos( $order_status, 'wc-' ) ? substr( $order_status, 3 ) : $order_status;
		$provider_data = (array) ( $described['provider_data'] ?? array() );

		$descriptor = array(
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
				'modes'             => $modes,
			),
			'capabilities'  => self::normalize_capabilities( $capabilities ),
			'defaults'      => array(
				'order_status' => $order_status ? $order_status : 'completed',
				'rounding' => $rounding,
				'open_drawer' => 'cash' === $kind,
			),
			'provider_data' => empty( $provider_data ) ? new \stdClass() : $provider_data,
		);
		$fields = Declared_Fields::for_gateway( $gateway );
		if ( null !== $fields ) {
			$descriptor['fields'] = $fields;
		}
		return $descriptor;
	}

	/**
	 * Normalize a handler's capabilities with the contract defaults.
	 *
	 * @param array $capabilities Handler capabilities.
	 */
	private static function normalize_capabilities( array $capabilities ): array {
		$amount = array( 'partial' => (bool) ( $capabilities['amount']['partial'] ?? false ) );
		foreach ( array( 'min', 'max' ) as $bound ) {
			if ( isset( $capabilities['amount'][ $bound ] ) && is_numeric( $capabilities['amount'][ $bound ] ) ) {
				$amount[ $bound ] = Money::normalize( $capabilities['amount'][ $bound ] );
			}
		}

		return array(
			'amount'  => $amount,
			'change'  => (bool) ( $capabilities['change'] ?? false ),
			'refunds' => array(
				'via'     => (string) ( $capabilities['refunds']['via'] ?? 'none' ),
				'partial' => (bool) ( $capabilities['refunds']['partial'] ?? false ),
			),
			'tips'    => (string) ( $capabilities['tips'] ?? 'none' ),
			'offline' => (string) ( $capabilities['offline'] ?? 'none' ),
			'void'    => (bool) ( $capabilities['void'] ?? false ),
		);
	}
}
