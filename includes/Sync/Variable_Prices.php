<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_Product_Variable;
use WCPOS\WooCommercePOS\Services\Variable_Price_Range;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Recomputes and stamps fresh variable-product price ranges.
 */
final class Variable_Prices {
	public const META_KEY = '_woocommerce_pos_variable_prices';

	/**
	 * THE variable-price augmentation, registered ONCE with {@see Augmentation_Pipeline}
	 * and projected onto both read lanes (the catalog-proxy `/products` batch and the
	 * per-object serialize path behind `/resolve/barcode`, the targeted read and the
	 * revision-hash drill-down).
	 *
	 * WC caches a variable product's min/max in a `wc_var_prices_*` transient that goes
	 * STALE when a child variation's price changes (gap-analysis §4.3), so the POS would
	 * otherwise show a wrong range. For each `type: 'variable'` product, recompute the
	 * price / regular_price / sale_price ranges by reading each visible child variation
	 * DIRECTLY — NOT via `get_variation_prices()`, which can hand back that same stale
	 * transient (codex review) — and inject `_woocommerce_pos_variable_prices` into the
	 * served meta_data. Non-variable products and non-array entries pass through untouched.
	 *
	 * The `type` gate comes FIRST so a page of simple products never triggers an object
	 * load; the batch lane hands over a null `$object` and relies on that gate plus the
	 * lazy load by id below.
	 *
	 * @param mixed      $payload Serialized product record.
	 * @param null|mixed $object  Product object, when the lane has one loaded.
	 * @param null|mixed $request Request context.
	 */
	public static function augment_record( $payload, $object = null, $request = null ) {
		if ( ! \is_array( $payload ) || 'variable' !== ( $payload['type'] ?? '' ) ) {
			return $payload;
		}
		if ( ( ! $object || ! \is_object( $object ) ) && isset( $payload['id'] ) && \function_exists( 'wc_get_product' ) ) {
			$object = wc_get_product( (int) $payload['id'] );
		}

		return self::inject_variable_price_range( $payload, $object, $request );
	}

	/**
	 * Backward-compatible batch-lane entry point.
	 *
	 * Kept for third-party code hooked to `woocommerce_pos_sync_proxy_response`
	 * with this callback; the pipeline no longer registers it.
	 *
	 * @param mixed      $data     Response data.
	 * @param mixed      $resource Resource name.
	 * @param null|mixed $request  Request context.
	 */
	public static function stamp_proxy_variable_prices( $data, $resource = '', $request = null ) {
		if ( 'products' !== $resource || ! \is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $index => $product ) {
			if ( \is_array( $product ) ) {
				$data[ $index ] = self::augment_record( $product, null, $request );
			}
		}

		return $data;
	}

	/**
	 * Backward-compatible per-object-lane entry point.
	 *
	 * @param mixed      $payload Response payload.
	 * @param null|mixed $object  Product object.
	 * @param null|mixed $request Request context.
	 */
	public static function stamp_serialized_variable_prices( $payload, $object = null, $request = null ) {
		return self::augment_record( $payload, $object, $request );
	}

	/**
	 * Shared per-product stamp: if `$product` is a `type: 'variable'` array backed by a
	 * variable product object, inject the freshly-recomputed
	 * `_woocommerce_pos_variable_prices` range. Otherwise pass through untouched.
	 *
	 * The range itself comes from {@see Variable_Price_Range}, THE canonical
	 * computation shared with the V1 products controller; this method only renders
	 * it onto the wire — raw child strings, and a sub-range omitted entirely when it
	 * has no values (e.g. nothing on sale) rather than emitted as empty strings.
	 *
	 * @param mixed      $object  Product object backing the payload.
	 * @param null|mixed $request Request context, read for the `dp` precision.
	 */
	private static function inject_variable_price_range( array $product, $object, $request = null ): array {
		if ( 'variable' !== ( $product['type'] ?? '' ) || ! $object instanceof WC_Product_Variable ) {
			return $product;
		}
		$computed = Variable_Price_Range::for( $object, Variable_Price_Range::FORMAT_RAW );
		$ranges   = self::wire_ranges( $computed );

		// A simple-to-variable conversion can leave old simple price fields on the
		// parent. Clear them even when no visible child has a price. When prices do
		// exist, serve the formatted current minimum without pairing independent
		// regular and sale minima from different variations into a false sale.
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $price_key ) {
			if ( array_key_exists( $price_key, $product ) ) {
				$product[ $price_key ] = '';
			}
		}
		if ( null === $ranges ) {
			return self::inject_meta_entry( $product, self::META_KEY, null );
		}
		if ( array_key_exists( 'price', $product ) ) {
			$decimals = \function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
			if ( $request instanceof \WP_REST_Request && null !== $request->get_param( 'dp' ) ) {
				$decimals = absint( $request->get_param( 'dp' ) );
			}
			$product['price'] = wc_format_decimal( $computed['minimum_price'], $decimals );
		}

		return self::inject_meta_entry( $product, self::META_KEY, $ranges );
	}

	/**
	 * Render the canonical range onto the sync wire: drop every empty sub-range, and
	 * report null when no visible variation carries any price at all.
	 *
	 * @param array $computed The Variable_Price_Range result.
	 */
	private static function wire_ranges( array $computed ): ?array {
		if ( ! $computed['has_prices'] ) {
			return null;
		}
		$ranges = array();
		foreach ( $computed['ranges'] as $field => $range ) {
			if ( '' === $range['min'] && '' === $range['max'] ) {
				continue;
			}
			$ranges[ $field ] = $range;
		}

		return empty( $ranges ) ? null : $ranges;
	}

	/**
	 * Replace (or add) a single meta_data entry by key, preserving the others (order-stable).
	 * A null value removes the matching entry without adding a replacement.
	 */
	private static function inject_meta_entry( array $payload, string $key, $value ): array {
		$others = array();
		$meta   = ( isset( $payload['meta_data'] ) && \is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		foreach ( $meta as $entry ) {
			$entry_key = Meta_Entry::key( $entry );
			if ( $key !== $entry_key ) {
				$others[] = $entry;
			}
		}
		if ( null !== $value ) {
			$others[] = array(
				'key' => $key,
				'value' => $value,
			);
		}
		$payload['meta_data'] = array_values( $others );

		return $payload;
	}
}
