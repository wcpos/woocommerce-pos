<?php
/**
 * WCPOS sync wire normalization.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Logger;

/**
 * Normalizes structured meta values before sync documents are hashed or emitted.
 */
final class Meta_Normalizer {
	/**
	 * Upper bound on array elements and object properties in ONE meta value, counted
	 * across every nesting level. The POS's own structured meta (`_woocommerce_pos_data`,
	 * attribute maps, line-item meta) is a few hundred nodes; anything past this is another
	 * plugin's bulk data and is not served: one store carried a 16.7-million-element meta
	 * value, and the JSON round trip below killed every request that touched the record
	 * (Sentry WOOCOMMERCE-POS-2KQ, ~22 fatals an hour).
	 */
	public const OVERSIZED_META_NODE_LIMIT = 20000;

	/**
	 * Upper bound on string bytes in ONE meta value, summed across nesting (1 MiB) — the
	 * same cut-off for values that are few but enormous strings.
	 */
	public const OVERSIZED_META_BYTE_LIMIT = 1048576;

	/**
	 * Meta keys already reported as oversized in this request (one warning per key).
	 *
	 * @var array<string, true>
	 */
	private static array $oversized_logged = array();

	/**
	 * Clear the per-request set of already-reported keys.
	 *
	 * The dedupe is request-scoped in production, where the process ends with the
	 * response. Long-lived processes and the test suite share one process across many
	 * requests, so they reset at the boundary like the other request-scoped collectors.
	 */
	public static function reset_request_state(): void {
		self::$oversized_logged = array();
	}

	/**
	 * Register the shared pre-stamping normalization seams.
	 */
	public static function register_hooks(): void {
		add_filter( 'woocommerce_pos_sync_proxy_response', array( __CLASS__, 'normalize' ), 5 );
		add_filter( 'woocommerce_pos_sync_serialized_product', array( __CLASS__, 'normalize' ), 5 );
		add_filter( 'woocommerce_pos_sync_serialized_order', array( __CLASS__, 'normalize' ), 5 );
	}

	/**
	 * Unregister the shared pre-stamping normalization seams.
	 */
	public static function unregister_hooks(): void {
		remove_filter( 'woocommerce_pos_sync_proxy_response', array( __CLASS__, 'normalize' ), 5 );
		remove_filter( 'woocommerce_pos_sync_serialized_product', array( __CLASS__, 'normalize' ), 5 );
		remove_filter( 'woocommerce_pos_sync_serialized_order', array( __CLASS__, 'normalize' ), 5 );
	}

	/**
	 * Recursively normalize every meta_data array in a document or payload.
	 *
	 * @param mixed $payload Document or payload being prepared for the wire.
	 *
	 * @return mixed
	 */
	public static function normalize( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		foreach ( $payload as $key => $value ) {
			if ( 'meta_data' === $key && is_array( $value ) ) {
				$value = self::normalize_meta_data( $value );
			}

			$payload[ $key ] = is_array( $value ) ? self::normalize( $value ) : $value;
		}

		return $payload;
	}

	/**
	 * Shape-tolerant reader for structured meta that may be stored in either
	 * form: the historical JSON-encoded string, or (after a typed client push
	 * lands through wc/v3) a native PHP array. Server-side consumers of
	 * `_woocommerce_pos_data`-style meta must read through this — a bare
	 * `json_decode( $raw )` fatals on PHP 8 the moment the storage holds an
	 * array.
	 *
	 * @param mixed $raw Meta value as returned by get_meta().
	 *
	 * @return array|null Decoded associative array, or null when the value is
	 *                    neither a JSON object/array string nor an array.
	 */
	public static function decode_to_array( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_object( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Normalize structured meta values and derived display fields.
	 *
	 * @param array $meta_data Serialized REST meta entries.
	 *
	 * @return array
	 */
	private static function normalize_meta_data( array $meta_data ): array {
		$was_list = array_keys( $meta_data ) === array_keys( array_values( $meta_data ) );
		$dropped  = false;
		foreach ( $meta_data as $index => $entry ) {
			// Top-level entity meta reaches the filters as live WC_Meta_Data objects
			// (they only become arrays at JSON-encode time); convert a copy to the
			// exact shape it would serialize to, and only swap it in when normalization
			// actually happens — untouched entries keep their original form so
			// revision hashes of scalar-only records are unchanged. The budget check
			// runs on the live value BEFORE the round trip: encoding an oversized value
			// is the allocation that took the whole request down.
			$is_meta_object = $entry instanceof \WC_Meta_Data;
			if ( $is_meta_object ) {
				$data = $entry->get_data();
				if ( self::exceeds_value_budget( $data['value'] ?? null ) ) {
					self::drop_oversized( $meta_data, $index, $data );
					$dropped = true;
					continue;
				}
				$entry = json_decode( wp_json_encode( $data ), true );
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( ! $is_meta_object && self::exceeds_value_budget( $entry['value'] ?? null ) ) {
				self::drop_oversized( $meta_data, $index, $entry );
				$dropped = true;
				continue;
			}

			$entry_changed = false;
			if ( isset( $entry['value'] ) && is_string( $entry['value'] ) ) {
				$raw     = $entry['value'];
				$trimmed = trim( $raw );
				$opening = substr( $trimmed, 0, 1 );
				if ( '{' === $opening || '[' === $opening ) {
					$decoded = json_decode( $raw );
					if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || $decoded instanceof \stdClass ) ) {
						$entry['value'] = self::preserve_json_object_shape( $decoded );
						$entry_changed  = true;
					}
				}
			}
			foreach ( array( 'display_key', 'display_value' ) as $display_field ) {
				if ( array_key_exists( $display_field, $entry ) && ! is_string( $entry[ $display_field ] ) ) {
					unset( $entry[ $display_field ] );
					$entry_changed = true;
				}
			}

			if ( ! $is_meta_object || $entry_changed ) {
				$meta_data[ $index ] = $entry;
			}
		}

		if ( $dropped && $was_list ) {
			// A list with a hole JSON-encodes as an object; the wire expects a list.
			$meta_data = array_values( $meta_data );
		}

		return $meta_data;
	}

	/**
	 * Remove an oversized entry from the payload and say so once per key per request.
	 * The value itself is never logged.
	 *
	 * @param array      $meta_data Serialized REST meta entries (by reference).
	 * @param int|string $index     Index of the entry to drop.
	 * @param array      $entry     Array form of the entry (`id`, `key`, `value`).
	 */
	private static function drop_oversized( array &$meta_data, $index, array $entry ): void {
		unset( $meta_data[ $index ] );
		self::note_oversized_meta(
			isset( $entry['key'] ) ? (string) $entry['key'] : '',
			(int) ( $entry['id'] ?? 0 )
		);
	}

	/**
	 * Record that an oversized meta entry was withheld, once per key per request.
	 * The value is never logged. Shared with the v1 lane, which drops the same
	 * entries at its own serializer rather than through this class.
	 *
	 * @param string $key     Meta key that was withheld.
	 * @param int    $meta_id Meta row id, when known.
	 */
	public static function note_oversized_meta( string $key, int $meta_id = 0 ): void {
		if ( isset( self::$oversized_logged[ $key ] ) ) {
			return;
		}
		self::$oversized_logged[ $key ] = true;
		Logger::warning(
			sprintf(
				'WCPOS sync: dropped oversized meta "%s" (meta id %d) from the POS payload; the stored value exceeds %d nodes or %d bytes and cannot be served to the POS.',
				$key,
				$meta_id,
				self::OVERSIZED_META_NODE_LIMIT,
				self::OVERSIZED_META_BYTE_LIMIT
			)
		);
	}

	/**
	 * Whether a meta value is too large to serve. Walks iteratively and stops the moment a
	 * limit is crossed, so the cost is bounded by the limits, not by the value: a
	 * 16-million-element value costs the same as a 20,001-element one. Arrays and stdClass
	 * are expanded in place (no copies); any other object counts as one node.
	 *
	 * @param mixed $value Meta value as stored.
	 *
	 * @return bool
	 */
	public static function exceeds_value_budget( $value ): bool {
		if ( is_string( $value ) ) {
			return \strlen( $value ) > self::OVERSIZED_META_BYTE_LIMIT;
		}
		if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
			return false;
		}

		$nodes = 0;
		$bytes = 0;
		$stack = array( $value );
		while ( array() !== $stack ) {
			$current = array_pop( $stack );
			foreach ( $current as $child ) {
				++$nodes;
				if ( is_string( $child ) ) {
					$bytes += \strlen( $child );
				} elseif ( is_array( $child ) || $child instanceof \stdClass ) {
					$stack[] = $child;
				}
				if ( $nodes > self::OVERSIZED_META_NODE_LIMIT || $bytes > self::OVERSIZED_META_BYTE_LIMIT ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Convert JSON objects to arrays unless doing so would change their wire shape.
	 *
	 * @param mixed $value Decoded JSON value.
	 *
	 * @return mixed
	 */
	private static function preserve_json_object_shape( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, __FUNCTION__ ), $value );
		}

		if ( ! $value instanceof \stdClass ) {
			return $value;
		}

		/**
		 * Decoded object properties.
		 *
		 * @var array<int|string, mixed> $properties
		 */
		$properties = array_map( array( __CLASS__, __FUNCTION__ ), get_object_vars( $value ) );

		return array() === $properties || array_values( $properties ) === $properties
			? (object) $properties
			: $properties;
	}
}
