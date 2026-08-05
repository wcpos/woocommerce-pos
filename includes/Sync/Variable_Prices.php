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
	 * Shared per-product stamp: if `$product` is a `type: 'variable'` array and `$object` can
	 * enumerate visible children, inject the freshly-recomputed `_woocommerce_pos_variable_prices`
	 * range. Otherwise pass through untouched. The single source both variable-price filters use.
	 *
	 * @param mixed      $object
	 * @param null|mixed $request
	 */
	private static function inject_variable_price_range( array $product, $object, $request = null ): array {
		if ( 'variable' !== ( $product['type'] ?? '' ) || ! \is_object( $object ) || ! \is_callable( array( $object, 'get_children' ) ) ) {
			return $product;
		}
		$ranges = self::visible_variation_price_ranges( $object );

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
			$product['price'] = wc_format_decimal( $ranges['price']['min'] ?? '', $decimals );
		}

		return self::inject_meta_entry( $product, self::META_KEY, $ranges );
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
		// Review finding 6: WC's visible-children resolution applies the store's
		// WEB visibility rules, NOT the POS `online_only` exclusion. A child
		// hidden from the POS would otherwise leak its price into the served
		// range. Subtract the POS-hidden variation ids (gated on the feature
		// toggle inside Pos_Visibility → empty when the toggle is off).
		$hidden = ( new Pos_Visibility() )->online_only_variation_ids();
		if ( array() !== $hidden ) {
			$child_ids = array_values( array_diff( array_map( 'intval', $child_ids ), $hidden ) );
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
	 * A null value removes the matching entry without adding a replacement.
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
