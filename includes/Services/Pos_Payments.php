<?php
/**
 * POS payments list normalization.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * The list of tenders a till took for one order (split tender).
 * Recorded write-once at create as a till audit key through Pos_Order_Audit;
 * payment_method holds the primary tender. Written by TallyUI's WooCommerce
 * transport (TallyUI v5 handoff §6).
 */
final class Pos_Payments {
	/** Payments list audit meta key. */
	public const META_KEY = '_woocommerce_pos_payments';

	/** A sanity bound on one order's tenders, not a business rule. */
	private const MAX_PAYMENTS = 20;

	/**
	 * Validate every tender and return the canonical JSON list.
	 *
	 * @param mixed $value A JSON string or native list of payments.
	 * @return string|null
	 */
	public static function normalize( $value ): ?string {
		$list = \is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! \is_array( $list ) || array() === $list || array_values( $list ) !== $list || \count( $list ) > self::MAX_PAYMENTS ) {
			return null;
		}

		$normalized = array();
		foreach ( $list as $entry ) {
			if ( ! \is_array( $entry ) || ! \is_string( $entry['method'] ?? null ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/i', $entry['method'] ) ) {
				return null;
			}
			if ( ! \is_string( $entry['title'] ?? null ) || ! \is_scalar( $entry['amount'] ?? null ) || 1 !== preg_match( '/^\d+(?:\.\d+)?$/', (string) $entry['amount'] ) ) {
				return null;
			}
			$title = sanitize_text_field( $entry['title'] );
			if ( '' === $title || mb_strlen( $title ) > 255 ) {
				return null;
			}

			$payment = array(
				'method' => $entry['method'],
				'title'  => $title,
				'amount' => (string) $entry['amount'],
			);
			if ( array_key_exists( 'reference', $entry ) ) {
				if ( ! \is_string( $entry['reference'] ) ) {
					return null;
				}
				$reference = sanitize_text_field( $entry['reference'] );
				if ( mb_strlen( $reference ) > 255 ) {
					return null;
				}
				if ( '' !== $reference ) {
					$payment['reference'] = $reference;
				}
			}
			foreach ( array( 'tendered', 'change' ) as $key ) {
				if ( array_key_exists( $key, $entry ) ) {
					if ( ! \is_scalar( $entry[ $key ] ) || 1 !== preg_match( '/^\d+(?:\.\d+)?$/', (string) $entry[ $key ] ) ) {
						return null;
					}
					$payment[ $key ] = (string) $entry[ $key ];
				}
			}
			$normalized[] = $payment;
		}

		return wp_json_encode( $normalized );
	}

	/**
	 * Read a valid payments list from an order.
	 *
	 * @param mixed $order An order exposing get_meta.
	 * @return array
	 */
	public static function from_order( $order ): array {
		if ( ! \is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$normalized = self::normalize( $order->get_meta( self::META_KEY, true ) );

		return null === $normalized ? array() : json_decode( $normalized, true );
	}
}
