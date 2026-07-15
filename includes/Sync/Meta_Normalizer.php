<?php
/**
 * WCPOS sync wire normalization.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Normalizes structured meta values before sync documents are hashed or emitted.
 */
final class Meta_Normalizer {
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
	 * Decode object and array JSON strings carried as meta values.
	 *
	 * @param array $meta_data Serialized REST meta entries.
	 *
	 * @return array
	 */
	private static function normalize_meta_data( array $meta_data ): array {
		foreach ( $meta_data as $index => $entry ) {
			// Top-level entity meta reaches the filters as live WC_Meta_Data objects
			// (they only become arrays at JSON-encode time); convert a copy to the
			// exact shape it would serialize to, and only swap it in when a decode
			// actually happens — untouched entries keep their original form so
			// revision hashes of scalar-only records are unchanged.
			$is_meta_object = $entry instanceof \WC_Meta_Data;
			if ( $is_meta_object ) {
				$entry = json_decode( wp_json_encode( $entry ), true );
			}

			if ( ! is_array( $entry ) || ! isset( $entry['value'] ) || ! is_string( $entry['value'] ) ) {
				continue;
			}

			$raw     = $entry['value'];
			$trimmed = trim( $raw );
			$opening = substr( $trimmed, 0, 1 );
			if ( '{' !== $opening && '[' !== $opening ) {
				continue;
			}

			$decoded = json_decode( $raw );
			if ( JSON_ERROR_NONE !== json_last_error() || ( ! is_array( $decoded ) && ! $decoded instanceof \stdClass ) ) {
				continue;
			}

			$entry['value'] = self::preserve_json_object_shape( $decoded );

			$meta_data[ $index ] = $entry;
		}

		return $meta_data;
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
