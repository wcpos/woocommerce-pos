<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Recomputes and stamps fresh variable-product price ranges.
 */
final class Variable_Prices {
	public const META_KEY = '_woocommerce_pos_variable_prices';

	/**
	 * Hook for `woocommerce_pos_sync_proxy_response` (catalog-proxy `/products`): serve a FRESH
	 * variable-product price range. WC caches a variable product's min/max in a
	 * `wc_var_prices_*` transient that goes STALE when a child variation's price changes
	 * (gap-analysis §4.3), so the POS would otherwise show a wrong range. For each
	 * `type: 'variable'` product, recompute the price / regular_price / sale_price ranges by
	 * reading each visible child variation DIRECTLY — NOT via `get_variation_prices()`, which
	 * can hand back that same stale transient (codex review) — and inject
	 * `_woocommerce_pos_variable_prices` into the served meta_data. Non-variable products and
	 * non-array entries pass through untouched.
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_variable_prices( $data, $resource = '', $request = null ) {
		if ( 'products' !== $resource || ! \is_array( $data ) || ! \function_exists( 'wc_get_product' ) ) {
			return $data;
		}
		foreach ( $data as $index => $product ) {
			// Gate on the response `type` BEFORE loading the object — a search/targeted page can be
			// entirely simple products, and loading each just to no-op would defeat the bulk fast path.
			if ( \is_array( $product ) && isset( $product['id'] ) && 'variable' === ( $product['type'] ?? '' ) ) {
				$data[ $index ] = self::inject_variable_price_range( $product, wc_get_product( (int) $product['id'] ) );
			}
		}

		return $data;
	}

	/**
	 * Twin of stamp_proxy_variable_prices for the `woocommerce_pos_sync_serialized_product` filter —
	 * the SINGLE-product serialize path used by `/resolve/barcode`, the per-id read, and the
	 * revision-hash drill-down. Without this a cashier scanning a barcode on a variable parent
	 * (or any per-id serialized read) would miss the fresh range the list proxy injects (codex).
	 * The WC object is passed by the filter; fall back to loading it by id.
	 *
	 * @param mixed      $payload
	 * @param null|mixed $object
	 * @param null|mixed $request
	 */
	public static function stamp_serialized_variable_prices( $payload, $object = null, $request = null ) {
		// Gate on type first so a simple product never triggers an object load here.
		if ( ! \is_array( $payload ) || 'variable' !== ( $payload['type'] ?? '' ) ) {
			return $payload;
		}
		if ( ( ! $object || ! \is_object( $object ) ) && isset( $payload['id'] ) && \function_exists( 'wc_get_product' ) ) {
			$object = wc_get_product( (int) $payload['id'] );
		}

		return self::inject_variable_price_range( $payload, $object );
	}

	/**
	 * Shared per-product stamp: if `$product` is a `type: 'variable'` array and `$object` can
	 * enumerate visible children, inject the freshly-recomputed `_woocommerce_pos_variable_prices`
	 * range. Otherwise pass through untouched. The single source both variable-price filters use.
	 *
	 * @param mixed $object
	 */
	private static function inject_variable_price_range( array $product, $object ): array {
		if ( 'variable' !== ( $product['type'] ?? '' ) || ! \is_object( $object ) || ! \is_callable( array( $object, 'get_children' ) ) ) {
			return $product;
		}
		$ranges = self::visible_variation_price_ranges( $object );

		return null === $ranges ? $product : self::inject_meta_entry( $product, self::META_KEY, $ranges );
	}

	/**
	 * Recompute the price / regular_price / sale_price ranges by reading each VISIBLE child
	 * variation directly (get_price/get_regular_price/get_sale_price) — bypassing the stale
	 * `wc_var_prices_*` transient this fix exists to correct. Each sub-range is omitted when
	 * that field has no values across the visible variations (e.g. nothing on sale). Returns
	 * null when no visible variation carries any price at all.
	 *
	 * @param mixed $object
	 */
	private static function visible_variation_price_ranges( $object ): ?array {
		// Prefer WooCommerce's own visible-children resolution — it applies the store's
		// hide-hidden / hide-out-of-stock rules, which a per-variation `variation_is_visible()`
		// check does not. Fall back to all children + the per-variation check for objects that
		// can't report a visible set.
		if ( \is_callable( array( $object, 'get_visible_children' ) ) ) {
			$child_ids   = (array) $object->get_visible_children();
			$prefiltered = true;
		} else {
			$child_ids   = (array) $object->get_children();
			$prefiltered = false;
		}
		$collected = array(
			'price' => array(),
			'regular_price' => array(),
			'sale_price' => array(),
		);
		foreach ( $child_ids as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ! $variation ) {
				continue;
			}
			// On the fallback path only, still drop a per-variation-hidden child.
			if ( ! $prefiltered && \is_callable( array( $variation, 'variation_is_visible' ) ) && ! $variation->variation_is_visible() ) {
				continue;
			}
			foreach ( array(
				'price' => 'get_price',
				'regular_price' => 'get_regular_price',
				'sale_price' => 'get_sale_price',
			) as $field => $getter ) {
				$value = $variation->$getter();
				if ( '' !== $value && null !== $value ) {
					$collected[ $field ][] = (string) $value;
				}
			}
		}
		$ranges = array();
		foreach ( $collected as $field => $values ) {
			$range = self::min_max_range( $values );
			if ( null !== $range ) {
				$ranges[ $field ] = $range;
			}
		}

		return empty( $ranges ) ? null : $ranges;
	}

	/**
	 * Min/max over a list of price strings. Compares NUMERICALLY (a string min/max would order
	 * '10' before '9') but RETURNS the original string, so no float round-trip mangles decimal
	 * precision (gap-analysis §4.3). Null for an empty list.
	 */
	private static function min_max_range( array $values ): ?array {
		if ( empty( $values ) ) {
			return null;
		}
		$min = null;
		$max = null;
		foreach ( $values as $price ) {
			$value = (float) $price;
			if ( null === $min || $value < (float) $min ) {
				$min = (string) $price;
			}
			if ( null === $max || $value > (float) $max ) {
				$max = (string) $price;
			}
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Replace (or add) a single meta_data entry by key, preserving the others (order-stable).
	 */
	private static function inject_meta_entry( array $payload, string $key, $value ): array {
		$others = array();
		$meta   = ( isset( $payload['meta_data'] ) && \is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		foreach ( $meta as $entry ) {
			$entry_key = \is_array( $entry ) ? ( $entry['key'] ?? null ) : ( \is_object( $entry ) ? ( $entry->key ?? null ) : null );
			if ( $key !== $entry_key ) {
				$others[] = $entry;
			}
		}
		$others[]             = array(
			'key' => $key,
			'value' => $value,
		);
		$payload['meta_data'] = array_values( $others );

		return $payload;
	}
}
