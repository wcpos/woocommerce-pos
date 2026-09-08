<?php
/**
 * Provider webhook settlement writer.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;

/** Reconciles provider confirmations, including webhooks preceding offline replay. */
class Settlement {
	/** Seven days allows offline devices to replay without retaining abandoned rows forever. */
	public const PARK_TTL = 7 * DAY_IN_SECONDS;
	/** Bound the shared option even when a provider sends unknown payment IDs indefinitely. */
	public const PARK_MAX = 200;
	/** Zero cannot be an order ID; reuse the existing lock for shared-option mutations. */
	private const PARK_LOCK_ID = 0;
	private const OPTION = 'wcpos_pending_settlements';
	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Get the shared settlement writer. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Settle an indexed row or park its confirmation until the device replays.
	 *
	 * @param string $payment_id Payment UUID.
	 * @param array  $patch      Provider confirmation.
	 * @return true|WP_Error
	 */
	public function settle( string $payment_id, array $patch ) {
		$payment_id = strtolower( $payment_id );
		if ( ! Pos_Uuid::is_uuid( $payment_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Payment id must be a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$patch = array_intersect_key( $patch, array_flip( array( 'provider_refs', 'receipt', 'status', 'expires_at', 'amount', 'currency', 'event_id' ) ) );
		$order_id = $this->find_order( $payment_id );
		if ( ! $order_id ) {
			$order_id = Order_Lock::instance()->with_lock(
				self::PARK_LOCK_ID,
				function () use ( $payment_id, $patch ) {
					// Recheck while serialized with record's drain: never park behind a completed drain.
					$found = $this->find_order( $payment_id );
					if ( $found ) {
						return $found;
					}
					$pending = $this->pending();
					$previous = $pending[ $payment_id ]['patch'] ?? array();
					$transition = Ledger::instance()->apply_transition( $previous + array( 'status' => 'pending' ), $patch );
					if ( is_wp_error( $transition ) ) {
						Logger::log( sprintf( 'WCPOS parked settlement %s: %s', $payment_id, $transition->get_error_message() ) );
						return $transition;
					}
					$pending[ $payment_id ] = array(
						'patch' => array_replace( $previous, $patch ),
						'parked_at' => time(),
					);
					uasort( $pending, static fn( $a, $b ) => $a['parked_at'] <=> $b['parked_at'] );
					return $this->write_pending( array_slice( $pending, -self::PARK_MAX, null, true ) );
				}
			);
			if ( ! is_int( $order_id ) ) {
				return $order_id;
			}
		}
		// Never wait for an order while holding the parking lock (record takes them in reverse).
		return Order_Lock::instance()->with_lock(
			$order_id,
			function () use ( $order_id, $payment_id, $patch ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					return new WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
				}
				return $this->apply( $order, $payment_id, $patch );
			}
		);
	}

	/**
	 * Drain an arrival's parked confirmation; record's caller already holds the order lock.
	 *
	 * @param WC_Order $order      Freshly stored order.
	 * @param string   $payment_id Payment UUID.
	 * @return true|WP_Error
	 */
	public function apply_parked( WC_Order $order, string $payment_id ) {
		return Order_Lock::instance()->with_lock(
			self::PARK_LOCK_ID,
			function () use ( $order, $payment_id ) {
				$pending = $this->pending();
				$result = isset( $pending[ $payment_id ] ) ? $this->apply( $order, $payment_id, $pending[ $payment_id ]['patch'] ) : true;
				$pending = $this->pending();
				unset( $pending[ $payment_id ] );
				$saved = $this->write_pending( $pending );
				return is_wp_error( $result ) ? $result : $saved;
			}
		);
	}

	/**
	 * Apply through the ledger's shared transition, verification and persistence path.
	 *
	 * @param WC_Order $order      Locked order.
	 * @param string   $payment_id Payment UUID.
	 * @param array    $patch      Provider confirmation.
	 * @return true|WP_Error
	 */
	private function apply( WC_Order $order, string $payment_id, array $patch ) {
		$result = Ledger::instance()->apply_result( $order, $payment_id, $patch );
		if ( is_wp_error( $result ) ) {
			Logger::log( sprintf( 'WCPOS settlement %s: %s', $payment_id, $result->get_error_message() ) );
			return $result;
		}
		return true;
	}

	/**
	 * Locate an indexed payment in either WooCommerce order storage engine.
	 *
	 * @param string $payment_id Payment UUID.
	 */
	private function find_order( string $payment_id ): int {
		return Payment_Index::order_id_for_payment( $payment_id );
	}

	/** Read unexpired entries while the parking lock is held. */
	private function pending(): array {
		// The lock may have waited behind a writer in another PHP request.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return array_filter( (array) get_option( self::OPTION, array() ), static fn( $entry ) => $entry['parked_at'] > time() - self::PARK_TTL );
	}

	/**
	 * Persist the bounded map without autoloading; do not acknowledge a lost charge.
	 *
	 * @param array $pending Pending confirmations.
	 * @return true|WP_Error
	 */
	private function write_pending( array $pending ) {
		if ( get_option( self::OPTION, array() ) === $pending || update_option( self::OPTION, $pending, false ) ) {
			return true;
		}
		return new WP_Error( 'wcpos_settlement_not_saved', __( 'Could not save the pending payment confirmation.', 'woocommerce-pos' ), array( 'status' => 500 ) );
	}
}
