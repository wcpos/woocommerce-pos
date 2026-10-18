<?php
/**
 * Detect and stamp a server overrule of the till's aggregate.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

// phpcs:disable Squiz.Commenting -- Compact money comparison helpers.
namespace WCPOS\WooCommercePOS\Payments\Contract;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;

final class Money_Owner {
	public const META_KEY = '_wcpos_money_owner';
	public const VALUE_SERVER = 'server';

	public static function divergence( array $pushed_payload, ?array $till_aggregate, WC_Order $saved ): array {
		return array_keys( self::differences( $pushed_payload, $till_aggregate, $saved ) );
	}

	private static function differences( array $payload, ?array $aggregate, WC_Order $saved ): array {
		$diff = array();
		$compare = static function ( string $field, $pushed, $stored ) use ( &$diff ): void {
			$left = Money_Compare::micro( $pushed );
			$right = Money_Compare::micro( (string) $stored );
			if ( null !== $left && null !== $right && ! Money_Compare::near( $left, $right ) ) {
				$diff[ $field ] = $field . ' ' . $stored . ' vs ' . $pushed;
			}
		};
		foreach ( array( 'total', 'total_tax', 'cart_tax', 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax' ) as $field ) {
			if ( isset( $aggregate[ $field ] ) ) {
				$getter = 'get_' . $field;
				$compare( $field, $aggregate[ $field ], $saved->$getter() );
			}
		}
		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines' ) as $key ) {
			$items = $saved->get_items( Settlement_Change::TYPES[ $key ] );
			foreach ( $payload[ $key ] ?? array() as $index => $line ) {
				$item = Settlement_Change::item( $line, $items );
				if ( ! $item ) {
					continue;
				}
				foreach ( 'line_items' === $key ? array( 'subtotal', 'subtotal_tax', 'total', 'total_tax' ) : array( 'total', 'total_tax' ) as $field ) {
					if ( isset( $line[ $field ] ) ) {
						$getter = 'get_' . $field;
						$compare( $key . '[' . $index . '].' . $field, $line[ $field ], $item->$getter() );
					}
				}
			}
		}
		return $diff;
	}


	public static function stamp( array $payload, ?array $aggregate, WC_Order $saved ): void {
		$diff = self::differences( $payload, $aggregate, $saved );
		if ( ! $diff || self::VALUE_SERVER === $saved->get_meta( self::META_KEY ) ) {
			return;
		}
		$list = implode( ', ', array_slice( $diff, 0, 3 ) );
		Money_Meta_Guard::authorised(
			$saved->get_id(),
			static function () use ( $saved, $list ): void {
				$saved->update_meta_data( self::META_KEY, self::VALUE_SERVER );
				$saved->save();
				/* translators: %s: up to three fields with stored and pushed totals. */
				$saved->add_order_note( sprintf( __( "WCPOS: the store's totals replaced the till's (%s).", 'woocommerce-pos' ), $list ) );
			}
		);
		Logger::warning( sprintf( 'WCPOS money overruled on order #%d: %s', $saved->get_id(), $list ) );
	}
}
