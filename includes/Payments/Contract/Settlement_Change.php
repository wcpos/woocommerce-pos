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
		// The writer reads the flag with rest_sanitize_boolean(); a "false" string is not a paid assertion.
		if ( isset( $payload['set_paid'] ) && rest_sanitize_boolean( $payload['set_paid'] ) ) {
			$changed['set_paid'] = true;
		}
		foreach ( self::TYPES as $key => $type ) {
			if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
				continue;
			}
			$items = $stored->get_items( $type );
			$seen = array();
			foreach ( $payload[ $key ] as $line ) {
				if ( ! is_array( $line ) ) {
					$changed[ $key ] = true;
					continue;
				}
				$item = self::item( $line, $items );
				if ( ! $item ) {
					$changed[ $key ] = true;
					continue;
				}
				$seen[] = $item->get_id();
				foreach ( array( 'quantity', 'product_id', 'variation_id', 'subtotal', 'total', 'subtotal_tax', 'total_tax', 'discount', 'discount_tax', 'name', 'code', 'tax_class', 'tax_status', 'method_id', 'method_title', 'instance_id' ) as $field ) {
					$getter = 'get_' . $field;
					if ( array_key_exists( $field, $line ) && is_callable( array( $item, $getter ) ) ) {
						$equal = in_array( $field, array( 'quantity', 'subtotal', 'total', 'subtotal_tax', 'total_tax', 'discount', 'discount_tax' ), true )
							? Money_Compare::equal( $line[ $field ], $item->$getter() )
							: ( null !== $line[ $field ] && (string) $line[ $field ] === (string) $item->$getter() );
						if ( ! $equal ) {
							$changed[ $key ] = true;
						}
					}
				}
				if ( array_key_exists( 'taxes', $line ) && is_callable( array( $item, 'get_taxes' ) ) && ! self::taxes_equal( $line['taxes'], $item->get_taxes() ) ) {
					$changed[ $key ] = true;
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
		foreach ( array( 'currency', 'payment_method', 'payment_method_title', 'transaction_id', 'status' ) as $field ) {
			if ( ! array_key_exists( $field, $payload ) || ( 'status' === $field && in_array( $payload[ $field ], Ledger::IN_PROGRESS_STATUSES, true ) ) ) {
				continue;
			}
			$getter = 'get_' . $field;
			$value = $stored->$getter();
			if ( $payload[ $field ] !== $value ) {
				$changed[ $field ] = true;
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
			$invalid = null === Ledger::instance()->decode( $meta[ Ledger::META_KEY ] );
			$project = static function ( array $rows ) use ( &$invalid ): array {
				$values = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || '' === trim( $row['id'] ) ) {
						$invalid = true;
						continue;
					}
					$row = array_diff_key( $row, array_flip( array( 'closure_id', 'updated_at_gmt', 'seen_events', 'events', 'refunds' ) ) );
					$row += array(
						'epoch' => 0,
						'refunded_amount' => '0',
					);
					foreach ( array( 'amount', 'refunded_amount', 'tendered', 'change', 'tip' ) as $field ) {
						if ( ! isset( $row[ $field ] ) && ! in_array( $field, array( 'amount', 'refunded_amount' ), true ) ) {
							continue;
						}
						$value = $row[ $field ] ?? '';
						$row[ $field ] = Money_Compare::micro( is_scalar( $value ) ? (string) $value : $value );
						$invalid = $invalid || null === $row[ $field ];
					}
					$values[] = self::canonical( $row );
				}
				sort( $values );
				return $values;
			};
			if ( $project( Ledger::instance()->read( $copy ) ) !== $project( Ledger::instance()->read( $stored ) ) || $invalid ) {
				$changed[ Ledger::META_KEY ] = true;
			}
		}
		return array_keys( $changed );
	}

	/** REST taxes are rate rows; WC stores amount maps, with subtotal only on products. */
	private static function taxes_equal( $taxes, array $stored ): bool {
		if ( ! is_array( $taxes ) ) {
			return false;
		}
		$maps = array_fill_keys( array_keys( $stored ), array() );
		foreach ( $taxes as $tax ) {
			if ( ! is_array( $tax ) || ! isset( $tax['id'] ) || ! is_numeric( $tax['id'] ) ) {
				return false;
			}
			foreach ( $maps as $field => $_ ) {
				$maps[ $field ][ (int) $tax['id'] ] = $tax[ $field ] ?? '0';
			}
		}
		foreach ( $stored as $field => $amounts ) {
			if ( count( $amounts ) !== count( $maps[ $field ] ) ) {
				return false;
			}
			foreach ( $amounts as $id => $amount ) {
				if ( ! array_key_exists( $id, $maps[ $field ] ) || ! Money_Compare::equal( $maps[ $field ][ $id ], $amount ) ) {
					return false;
				}
			}
		}
		return true;
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
