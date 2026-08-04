<?php
/**
 * POS order audit notes.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves audit-note labels and adds consistently attributed order notes.
 */
class Order_Notes {
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Concise helper summaries and signatures document the parameters.
	/** Resolve a cashier display name. */
	public static function cashier_name( $user_id ): string {
		$user = get_userdata( (int) $user_id );
		return $user ? $user->display_name : /* translators: Fallback cashier name shown when the POS cashier user cannot be found. */ __( 'Unknown', 'woocommerce-pos' );
	}

	/** Resolve a customer display name. */
	public static function customer_name( $customer_id ): string {
		$customer_id = (int) $customer_id;
		if ( 0 === $customer_id ) {
			return /* translators: Customer name shown in an order note for a guest checkout. */ __( 'Guest', 'woocommerce-pos' );
		}
		$user = get_userdata( $customer_id );
		return $user ? $user->display_name : sprintf( '#%d', $customer_id );
	}

	/** Resolve a free or Pro store display name. */
	public static function store_name( $store_id ): string {
		if ( ! is_scalar( $store_id ) || '' === (string) $store_id || ( is_numeric( $store_id ) && (int) $store_id <= 0 ) ) {
			return Store_Defaults::name();
		}
		if ( is_numeric( $store_id ) ) {
			$store_id = (int) $store_id;
			$store = get_post( $store_id );
			if ( $store && 'wcpos_store' === $store->post_type ) {
				return $store->post_title;
			}
			return sprintf(
				/* translators: %d: POS store post ID. */
				__( 'Store #%d', 'woocommerce-pos' ),
				$store_id
			);
		}
		return (string) $store_id;
	}

	/** Add the POS creation audit note. */
	public static function add_creation_note( WC_Order $order, $cashier_id, $store_id ): void {
		$note = sprintf(
			/* translators: 1: POS cashier, 2: POS store. */
			__( 'Order created via POS by %1$s at %2$s.', 'woocommerce-pos' ),
			self::cashier_name( $cashier_id ),
			self::store_name( $store_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the cashier reassignment audit note. */
	public static function add_cashier_change_note( WC_Order $order, $old_user_id, $new_user_id ): void {
		$note = sprintf(
			/* translators: 1: old POS cashier, 2: new POS cashier. */
			__( 'POS cashier changed from %1$s to %2$s.', 'woocommerce-pos' ),
			self::cashier_name( $old_user_id ),
			self::cashier_name( $new_user_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the store reassignment audit note. */
	public static function add_store_change_note( WC_Order $order, $old_store_id, $new_store_id ): void {
		$note = sprintf(
			/* translators: 1: old POS store, 2: new POS store. */
			__( 'POS store changed from %1$s to %2$s.', 'woocommerce-pos' ),
			self::store_name( $old_store_id ),
			self::store_name( $new_store_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the POS re-open audit note. */
	public static function add_reopen_note( WC_Order $order, $cashier_id, $store_id ): void {
		$note = sprintf(
			/* translators: 1: POS cashier, 2: POS store. */
			__( 'Order re-opened via POS by %1$s at %2$s.', 'woocommerce-pos' ),
			self::cashier_name( $cashier_id ),
			self::store_name( $store_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the POS customer reassignment audit note. */
	public static function add_pos_customer_change_note( WC_Order $order, $old_customer_id, $new_customer_id ): void {
		$note = sprintf(
			/* translators: 1: old customer, 2: new customer. */
			__( 'Customer changed from %1$s to %2$s via WCPOS.', 'woocommerce-pos' ),
			self::customer_name( $old_customer_id ),
			self::customer_name( $new_customer_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the admin customer reassignment audit note. */
	public static function add_admin_customer_change_note( WC_Order $order, $old_customer_id, $new_customer_id ): void {
		$note = sprintf(
			/* translators: 1: old customer, 2: new customer. */
			__( 'Customer changed from %1$s to %2$s.', 'woocommerce-pos' ),
			self::customer_name( $old_customer_id ),
			self::customer_name( $new_customer_id )
		);
		$order->add_order_note( $note, 0, true );
	}

	/** Add the cash tender audit note. */
	public static function add_cash_note( WC_Order $order, $tendered, $change ): void {
		$note = sprintf(
			/* translators: 1: cash amount tendered, 2: change given. */
			__( 'Cash payment received — amount tendered: %1$s, change given: %2$s.', 'woocommerce-pos' ),
			wc_price( $tendered ),
			wc_price( $change )
		);
		$order->add_order_note( $note, 0, true );
	}
}
