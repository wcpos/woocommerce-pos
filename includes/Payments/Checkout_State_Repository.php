<?php
/**
 * POS checkout state repository.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

/**
 * Repository for checkout state.
 */
class Checkout_State_Repository {
	/**
	 * Order meta key.
	 */
	private const META_KEY = '_wcpos_checkout_state';

	/**
	 * Persist checkout state on the order.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $state    State payload.
	 */
	public function upsert( int $order_id, array $state ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, $state );
		$order->save_meta_data();
	}

	/**
	 * Get checkout state for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function get( int $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array();
		}

		$state = $order->get_meta( self::META_KEY, true );

		return is_array( $state ) ? $state : array();
	}
}
