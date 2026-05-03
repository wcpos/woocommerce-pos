<?php
/**
 * Tax ID Writer.
 *
 * Persists a normalised TaxId[] list onto a WooCommerce order or user. Each
 * entry is dispatched to the meta key resolved by Tax_Id_Detector's write map
 * (settings overrides → active plugin → populated-key inference → defaults).
 *
 * Writer behaviour:
 *   - Per-type dispatch by write_map.
 *   - Aelia EU VAT Assistant detection: when the resolved key is `_eu_vat_data`,
 *     write the structured array form rather than a flat string.
 *   - Ownership tracking: meta keys WCPOS wrote on this object are recorded in
 *     `_wcpos_tax_ids_owned_keys`. Used to safely strip on uninstall without
 *     touching keys owned by other plugins.
 *   - Verification metadata: when present, mirrored into the
 *     `_wcpos_tax_ids_verified` sidecar.
 *
 * Pure-logic helpers are exposed as statics so dispatch behaviour is unit
 * testable without hitting the database.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Abstract_Order;

/**
 * Tax_Id_Writer class.
 */
class Tax_Id_Writer {
	/**
	 * Sidecar meta key tracking which meta keys WCPOS wrote on this object.
	 *
	 * @var string
	 */
	const OWNED_KEYS_META_KEY = '_wcpos_tax_ids_owned_keys';

	/**
	 * Sidecar meta key for verification metadata.
	 *
	 * @var string
	 */
	const VERIFIED_META_KEY = '_wcpos_tax_ids_verified';

	/**
	 * Normalise a raw TaxId[] payload into the canonical shape, dropping invalid
	 * entries.
	 *
	 * @param array<int,mixed> $tax_ids Raw input.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_input( array $tax_ids ): array {
		$out = array();
		foreach ( $tax_ids as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}

			$type  = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			$value = isset( $entry['value'] ) ? (string) $entry['value'] : '';

			if ( '' === $value ) {
				continue;
			}
			if ( ! Tax_Id_Types::is_valid_type( $type ) ) {
				$type = Tax_Id_Types::TYPE_OTHER;
			}

			$normalized = self::normalize_value( $value );
			if ( '' === $normalized ) {
				continue;
			}

			$row = array(
				'type'    => $type,
				'value'   => $normalized,
				'country' => isset( $entry['country'] ) && '' !== $entry['country']
					? strtoupper( (string) $entry['country'] )
					: Tax_Id_Types::country_for_type( $type ),
				'label'   => isset( $entry['label'] ) && '' !== $entry['label'] ? (string) $entry['label'] : null,
			);

			if ( isset( $entry['verified'] ) && \is_array( $entry['verified'] ) ) {
				$row['verified'] = $entry['verified'];
			}

			$out[] = $row;
		}

		return self::dedupe( $out );
	}

	/**
	 * Build the meta updates that should be applied for a given TaxId[] list.
	 *
	 * Returned shape:
	 *   array(
	 *       'updates'   => array<string,mixed>, // meta_key => meta_value to write
	 *       'owned'     => string[],            // keys WCPOS now owns on this object
	 *       'verified'  => array<int,array>,    // verification sidecar payload
	 *   )
	 *
	 * Pure logic — no I/O. The caller is responsible for applying `updates`
	 * (via `update_post_meta` / `update_user_meta`) and persisting `owned` /
	 * `verified` to the sidecar keys.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids   Normalised TaxId[] list.
	 * @param array<string,string>           $write_map Per-type → meta-key map.
	 *
	 * @return array{updates:array<string,mixed>,owned:array<int,string>,verified:array<int,array<string,mixed>>}
	 */
	public static function build_updates( array $tax_ids, array $write_map ): array {
		$updates  = array();
		$owned    = array();
		$verified = array();

		// Group entries by resolved meta key — multiple types can share a key
		// (e.g. eu_vat + gb_vat → _billing_vat_number) but only one value can
		// be persisted there. First-seen wins.
		foreach ( $tax_ids as $entry ) {
			$type     = $entry['type'];
			$meta_key = $write_map[ $type ] ?? '';
			if ( '' === $meta_key ) {
				continue;
			}

			if ( '_eu_vat_data' === $meta_key ) {
				// Aelia structured array.
				if ( ! isset( $updates[ $meta_key ] ) ) {
					$updates[ $meta_key ] = array(
						'vat_number' => $entry['value'],
						'country'    => $entry['country'] ?? '',
						'is_valid'   => isset( $entry['verified']['status'] )
							? 'verified' === $entry['verified']['status']
							: false,
					);
				}
			} elseif ( ! isset( $updates[ $meta_key ] ) ) {
				$updates[ $meta_key ] = self::format_value_with_country( $entry );
			}

			if ( ! \in_array( $meta_key, $owned, true ) ) {
				$owned[] = $meta_key;
			}

			if ( isset( $entry['verified'] ) && \is_array( $entry['verified'] ) ) {
				$verified[] = array(
					'type'     => $type,
					'value'    => $entry['value'],
					'verified' => $entry['verified'],
				);
			}
		}

		return array(
			'updates'  => $updates,
			'owned'    => $owned,
			'verified' => $verified,
		);
	}

	/**
	 * Persist the given TaxId[] list onto a WooCommerce order.
	 *
	 * @param WC_Abstract_Order         $order     Order.
	 * @param array<int,mixed>          $tax_ids   Raw TaxId[] input.
	 * @param null|array<string,string> $write_map Optional override; defaults to detector.
	 *
	 * @return array{updates:array<string,mixed>,owned:array<int,string>,verified:array<int,array<string,mixed>>}
	 */
	public function write_for_order( WC_Abstract_Order $order, array $tax_ids, $write_map = null ): array {
		$normalized = self::normalize_input( $tax_ids );
		$map        = \is_array( $write_map ) ? $write_map : ( new Tax_Id_Detector() )->summary()['write_map'];

		$plan = self::build_updates( $normalized, $map );

		// Wipe stale keys we previously owned but no longer need.
		$previous_owned = (array) $order->get_meta( self::OWNED_KEYS_META_KEY, true );
		$to_clear       = array_diff( $previous_owned, $plan['owned'] );
		foreach ( $to_clear as $stale_key ) {
			$order->delete_meta_data( (string) $stale_key );
		}

		foreach ( $plan['updates'] as $meta_key => $meta_value ) {
			$order->update_meta_data( (string) $meta_key, $meta_value );
		}

		if ( ! empty( $plan['owned'] ) ) {
			$order->update_meta_data( self::OWNED_KEYS_META_KEY, array_values( $plan['owned'] ) );
		} else {
			$order->delete_meta_data( self::OWNED_KEYS_META_KEY );
		}

		if ( ! empty( $plan['verified'] ) ) {
			$order->update_meta_data( self::VERIFIED_META_KEY, $plan['verified'] );
		} else {
			$order->delete_meta_data( self::VERIFIED_META_KEY );
		}

		$order->save();

		return $plan;
	}

	/**
	 * Persist the given TaxId[] list onto a WP user (customer record).
	 *
	 * User meta uses the un-prefixed key (WC convention) for billing_* fields,
	 * so we strip the leading underscore before writing.
	 *
	 * @param int                       $user_id   User ID.
	 * @param array<int,mixed>          $tax_ids   Raw TaxId[] input.
	 * @param null|array<string,string> $write_map Optional override.
	 *
	 * @return array{updates:array<string,mixed>,owned:array<int,string>,verified:array<int,array<string,mixed>>}
	 */
	public function write_for_user( int $user_id, array $tax_ids, $write_map = null ): array {
		if ( $user_id <= 0 ) {
			return array(
				'updates'  => array(),
				'owned'    => array(),
				'verified' => array(),
			);
		}

		$normalized = self::normalize_input( $tax_ids );
		$map        = \is_array( $write_map ) ? $write_map : ( new Tax_Id_Detector() )->summary()['write_map'];

		$plan = self::build_updates( $normalized, $map );

		// Wipe stale keys we previously owned but no longer need (user meta variant).
		$previous_owned = (array) get_user_meta( $user_id, self::OWNED_KEYS_META_KEY, true );
		$to_clear       = array_diff( $previous_owned, $plan['owned'] );
		foreach ( $to_clear as $stale_key ) {
			$user_key = ltrim( (string) $stale_key, '_' );
			delete_user_meta( $user_id, $user_key );
			// Some plugins keep an underscore-prefixed shadow; clear it too.
			delete_user_meta( $user_id, (string) $stale_key );
		}

		foreach ( $plan['updates'] as $meta_key => $meta_value ) {
			$user_key = ltrim( (string) $meta_key, '_' );
			update_user_meta( $user_id, $user_key, $meta_value );
		}

		if ( ! empty( $plan['owned'] ) ) {
			update_user_meta( $user_id, self::OWNED_KEYS_META_KEY, array_values( $plan['owned'] ) );
		} else {
			delete_user_meta( $user_id, self::OWNED_KEYS_META_KEY );
		}

		if ( ! empty( $plan['verified'] ) ) {
			update_user_meta( $user_id, self::VERIFIED_META_KEY, $plan['verified'] );
		} else {
			delete_user_meta( $user_id, self::VERIFIED_META_KEY );
		}

		return $plan;
	}

	/**
	 * Snapshot the customer's tax IDs onto an order at create time. Reads from
	 * the customer record (via Tax_Id_Reader::read_for_user) and then writes the
	 * resulting list onto the order. No-op for guest customers (id <= 0).
	 *
	 * @param WC_Abstract_Order $order   Order being created.
	 * @param int               $user_id Customer ID.
	 *
	 * @return array{updates:array<string,mixed>,owned:array<int,string>,verified:array<int,array<string,mixed>>}
	 */
	public function snapshot_from_user_to_order( WC_Abstract_Order $order, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array(
				'updates'  => array(),
				'owned'    => array(),
				'verified' => array(),
			);
		}

		$reader = new Tax_Id_Reader();
		$list   = $reader->read_for_user( $user_id, $order->get_billing_country() );
		if ( empty( $list ) ) {
			return array(
				'updates'  => array(),
				'owned'    => array(),
				'verified' => array(),
			);
		}

		return $this->write_for_order( $order, $list );
	}

	/**
	 * Format a tax ID value for storage. For VAT types we prefix the country
	 * (e.g. "DE123456789") if not already present, since most VAT-aware plugins
	 * expect that form.
	 *
	 * @param array<string,mixed> $entry Tax ID entry.
	 *
	 * @return string
	 */
	private static function format_value_with_country( array $entry ): string {
		$value   = (string) $entry['value'];
		$country = isset( $entry['country'] ) ? (string) $entry['country'] : '';
		$type    = (string) $entry['type'];

		$is_vat = \in_array(
			$type,
			array( Tax_Id_Types::TYPE_EU_VAT, Tax_Id_Types::TYPE_GB_VAT ),
			true
		);

		if ( $is_vat && '' !== $country && ! preg_match( '/^[A-Z]{2}/', $value ) ) {
			return $country . $value;
		}

		return $value;
	}

	/**
	 * Normalise a tax-ID value: trim, collapse whitespace, uppercase.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function normalize_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$value = (string) preg_replace( '/\s+/', '', $value );

		return strtoupper( $value );
	}

	/**
	 * Dedupe a TaxId[] list by (type, value), keeping first occurrence.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Tax ID list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function dedupe( array $tax_ids ): array {
		$seen = array();
		$out  = array();
		foreach ( $tax_ids as $tax_id ) {
			$key = $tax_id['type'] . '|' . $tax_id['value'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tax_id;
		}

		return $out;
	}
}
