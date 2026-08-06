<?php
/**
 * POS order audit-meta authority.
 *
 * Single source of truth for the `_pos_*` audit keys (who rang up the sale,
 * which store, the cash amounts) used by the wcpos/v1 orders controller. The
 * server is the sole authoritative writer of this trail: `_pos_user` and
 * `_woocommerce_pos_version` are always server-derived, and the till-sourced
 * values (`_pos_store`, cash-tender) are accepted from the client payload
 * only at create and only when valid.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Pos_Order_Audit service.
 */
final class Pos_Order_Audit {
	/**
	 * Audit keys the SERVER derives — a client-supplied value is never accepted.
	 *
	 * @var string[]
	 */
	private const SERVER_META_KEYS = array( '_pos_user', '_woocommerce_pos_version' );

	/**
	 * Audit keys sourced from the till at the sale (client-supplied, validated,
	 * write-once).
	 *
	 * @var string[]
	 */
	private const TILL_META_KEYS = array( '_pos_store', '_pos_cash_amount_tendered', '_pos_cash_change', '_pos_card_cashback' );

	/**
	 * The subset of till keys that are monetary AMOUNTS (must be numeric);
	 * `_pos_store` is an identifier, not an amount.
	 *
	 * @var string[]
	 */
	private const CASH_META_KEYS = array( '_pos_cash_amount_tendered', '_pos_cash_change', '_pos_card_cashback' );

	/**
	 * Every audit key: the server-derived and the till-sourced set.
	 *
	 * @return string[]
	 */
	public static function audit_meta_keys(): array {
		return array_merge( self::SERVER_META_KEYS, self::TILL_META_KEYS );
	}

	/**
	 * A `meta_data` array safe to apply on order CREATE: the server-derived keys
	 * are removed (the write path re-stamps them from the authenticated request),
	 * and till entries use the same last-entry-wins parser as the v2 create path.
	 *
	 * @param array $meta_data REST `meta_data` entries (arrays or objects with key/value).
	 *
	 * @return array
	 */
	public static function sanitize_create_meta( array $meta_data ): array {
		$sanitized = self::strip_audit_meta( $meta_data );
		foreach ( self::till_meta_from_payload( $meta_data ) as $key => $value ) {
			$sanitized[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $sanitized;
	}

	/**
	 * A `meta_data` array with every audit key removed — used by the update
	 * path. The audit trail is write-once at the sale: an edit under a
	 * different cashier/store (or a forged payload) must not rewrite it.
	 *
	 * WooCommerce resolves an entry by its `id` BEFORE its `key` and overwrites
	 * both the row's key and value — so an id-addressed entry under a harmless
	 * key would rename an audit row away. Pass the order's audit-row ids as
	 * `$protected_meta_ids` and such entries are dropped too.
	 *
	 * @param array $meta_data          REST `meta_data` entries (arrays or objects with key/value).
	 * @param int[] $protected_meta_ids Existing audit-row meta ids on the target order (see audit_meta_ids()).
	 *
	 * @return array
	 */
	public static function strip_audit_meta( array $meta_data, array $protected_meta_ids = array() ): array {
		$strip = self::audit_meta_keys();

		return array_values(
			array_filter(
				$meta_data,
				static function ( $entry ) use ( $strip, $protected_meta_ids ) {
					if ( \in_array( self::entry_key( $entry ), $strip, true ) ) {
						return false;
					}
					$id = \is_array( $entry ) ? ( $entry['id'] ?? null ) : ( \is_object( $entry ) ? ( $entry->id ?? null ) : null );

					return ! ( is_numeric( $id ) && \in_array( (int) $id, $protected_meta_ids, true ) );
				}
			)
		);
	}

	/**
	 * Meta ids of the order's existing audit rows — the rows an id-addressed
	 * `meta_data` entry could target (see strip_audit_meta()).
	 *
	 * @param mixed $order A WC_Order (or false/null when lookup failed — safe no-op).
	 *
	 * @return int[]
	 */
	public static function audit_meta_ids( $order ): array {
		if ( ! \is_object( $order ) || ! method_exists( $order, 'get_meta_data' ) ) {
			return array();
		}
		$keys = self::audit_meta_keys();
		$ids  = array();
		foreach ( $order->get_meta_data() as $meta ) {
			$data = \is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : array();
			if ( isset( $data['id'], $data['key'] ) && \in_array( (string) $data['key'], $keys, true ) ) {
				$ids[] = (int) $data['id'];
			}
		}

		return $ids;
	}

	/**
	 * The validated till key⇒value map from a client `meta_data` array (last
	 * entry wins for a repeated key, invalid values dropped). This is the one
	 * parser for till values — callers must not rebuild the extraction loop.
	 *
	 * @param array $meta_data REST `meta_data` entries (arrays or objects with key/value).
	 *
	 * @return array<string, string>
	 */
	public static function till_meta_from_payload( array $meta_data ): array {
		$client = array();
		foreach ( $meta_data as $entry ) {
			$key = self::entry_key( $entry );
			if ( null !== $key ) {
				$client[ $key ] = self::entry_value( $entry );
			}
		}
		$till = array();
		foreach ( self::TILL_META_KEYS as $key ) {
			if ( array_key_exists( $key, $client ) && self::is_valid_till_value( $key, $client[ $key ] ) ) {
				$till[ $key ] = (string) $client[ $key ];
			}
		}

		return $till;
	}

	/**
	 * Whether a till value may persist: never an empty/non-scalar value, and the
	 * cash AMOUNTS must be numeric (a malformed amount would break Pro analytics
	 * aggregations). `_pos_store` is an identifier — the store-scope model allows
	 * numeric ids, uuids, or slugs — so any non-empty scalar is kept.
	 *
	 * @param string $key   The till meta key.
	 * @param mixed  $value The client-supplied value.
	 *
	 * @return bool
	 */
	public static function is_valid_till_value( string $key, $value ): bool {
		if ( ! \is_scalar( $value ) || '' === (string) $value ) {
			return false;
		}
		if ( \in_array( $key, self::CASH_META_KEYS, true ) && ! is_numeric( $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The entry's meta key, or null for a malformed entry. A `key` that is an
	 * array/object must not be used for comparisons (PHP "Illegal offset type"
	 * territory) — treat it as unrecognized rather than crash the write.
	 *
	 * @param mixed $entry A REST `meta_data` entry.
	 *
	 * @return string|null
	 */
	private static function entry_key( $entry ) {
		$key = \is_array( $entry ) ? ( $entry['key'] ?? null ) : ( \is_object( $entry ) ? ( $entry->key ?? null ) : null );

		return \is_scalar( $key ) ? (string) $key : null;
	}

	/**
	 * The entry's value ('' when absent or malformed).
	 *
	 * @param mixed $entry A REST `meta_data` entry.
	 *
	 * @return mixed
	 */
	private static function entry_value( $entry ) {
		return \is_array( $entry ) ? ( $entry['value'] ?? '' ) : ( \is_object( $entry ) ? ( $entry->value ?? '' ) : '' );
	}
}
