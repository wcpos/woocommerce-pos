<?php
/**
 * Compare settlement intent without treating sparse pushes as claims of money.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

// phpcs:disable Squiz.Commenting -- Pure contract comparisons.
namespace WCPOS\WooCommercePOS\Payments\Contract;

use WC_Order;
use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;

final class Settlement_Change {
	public const TYPES = array(
		'line_items' => 'line_item',
		'fee_lines' => 'fee',
		'shipping_lines' => 'shipping',
		'coupon_lines' => 'coupon',
	);

	public static function meta( array $payload ): array {
		$values = array();
		foreach ( $payload['meta_data'] ?? array() as $entry ) {
			$key = Meta_Entry::key( $entry );
			if ( is_string( $key ) ) {
				$values[ $key ] = Meta_Entry::value( $entry );
			}
		}
		return $values;
	}

	/** Pair by id first, otherwise by a unique item uuid of the same type. */
	public static function item( array $line, array $items ) {
		if ( isset( $line['id'] ) && is_numeric( $line['id'] ) && isset( $items[ (int) $line['id'] ] ) ) {
			return $items[ (int) $line['id'] ];
		}
		$uuid = self::meta( $line )['_woocommerce_pos_uuid'] ?? null;
		$matches = $uuid ? array_filter( $items, static fn( $item ) => $uuid === $item->get_meta( '_woocommerce_pos_uuid' ) ) : array();
		return 1 === count( $matches ) ? reset( $matches ) : null;
	}

	public static function changes( array $payload, WC_Order $stored ): array {
		$changed = array();
		foreach ( self::TYPES as $key => $type ) {
			if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
				continue;
			}
			$items = $stored->get_items( $type );
			$seen = array();
			foreach ( $payload[ $key ] as $line ) {
				$item = self::item( $line, $items );
				if ( ! $item ) {
					$changed[ $key ] = true;
					continue;
				}
				$seen[] = $item->get_id();
				foreach ( array( 'quantity', 'product_id', 'variation_id', 'subtotal', 'total', 'name', 'code', 'tax_class', 'tax_status', 'method_id' ) as $field ) {
					$getter = 'get_' . $field;
					if ( array_key_exists( $field, $line ) && is_callable( array( $item, $getter ) ) ) {
						$equal = in_array( $field, array( 'quantity', 'subtotal', 'total' ), true )
							? Money_Compare::equal( $line[ $field ], $item->$getter() )
							: ( null !== $line[ $field ] && (string) $line[ $field ] === (string) $item->$getter() );
						if ( ! $equal ) {
							$changed[ $key ] = true;
						}
					}
				}
				$meta = self::meta( $line );
				// Stored as JSON text on older orders and as a typed array on newer ones, with no key order promised either way.
				if ( array_key_exists( '_woocommerce_pos_data', $meta ) && self::canonical( Meta_Normalizer::decode_to_array( $meta['_woocommerce_pos_data'] ) ) !== self::canonical( Meta_Normalizer::decode_to_array( $item->get_meta( '_woocommerce_pos_data' ) ) ) ) {
					$changed[ $key ] = true;
				}
				if ( array_key_exists( '_wcpos_quick_discount', $meta ) && $meta['_wcpos_quick_discount'] != $item->get_meta( '_wcpos_quick_discount' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons -- Typed meta accepts JSON numbers.
					$changed[ $key ] = true;
				}
			}
			if ( array_diff( array_keys( $items ), $seen ) ) {
				$changed[ $key ] = true;
			}
		}
		if ( isset( $payload['customer_id'] ) && (int) $payload['customer_id'] !== $stored->get_customer_id() ) {
			$changed['customer_id'] = true;
		}
		foreach ( array( 'billing', 'shipping' ) as $key ) {
			foreach ( $payload[ $key ] ?? array() as $field => $value ) {
				if ( ( $stored->get_address( $key )[ $field ] ?? '' ) !== $value ) {
					$changed[ $key ] = true;
				}
			}
		}
		$meta = self::meta( $payload );
		if ( array_key_exists( '_woocommerce_pos_tax_based_on', $meta ) && $meta['_woocommerce_pos_tax_based_on'] !== $stored->get_meta( '_woocommerce_pos_tax_based_on' ) ) {
			$changed['_woocommerce_pos_tax_based_on'] = true;
		}
		if ( array_key_exists( Ledger::META_KEY, $meta ) ) {
			$copy = new WC_Order();
			$copy->update_meta_data( Ledger::META_KEY, $meta[ Ledger::META_KEY ] );
			$invalid = false;
			$project = static function ( array $rows ) use ( &$invalid ): array {
				$values = array();
				foreach ( $rows as $row ) {
					$amount = Money_Compare::micro( (string) ( $row['amount'] ?? '' ) );
					$refund = Money_Compare::micro( (string) ( $row['refunded_amount'] ?? '0' ) );
					$invalid = $invalid || null === $amount || null === $refund;
					$values[ $row['id'] ] = array( $row['status'] ?? null, $amount, $refund, $row['epoch'] ?? 0 );
				}
				ksort( $values );
				return $values;
			};
			if ( $project( Ledger::instance()->read( $copy ) ) !== $project( Ledger::instance()->read( $stored ) ) || $invalid ) {
				$changed[ Ledger::META_KEY ] = true;
			}
		}
		return array_keys( $changed );
	}

	/** JSON with recursively sorted keys, so two spellings of one value compare equal. */
	private static function canonical( $value ): string {
		if ( is_array( $value ) ) {
			ksort( $value );
			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$value[ $key ] = json_decode( self::canonical( $item ), true );
				}
			}
		}
		return (string) wp_json_encode( $value );
	}
}
