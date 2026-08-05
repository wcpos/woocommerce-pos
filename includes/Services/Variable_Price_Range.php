<?php
/**
 * Canonical variable-product price range.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Product_Variable;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;

/**
 * THE variable-product price range.
 *
 * A variable product's min/max price is the number a cashier reads off the till,
 * and it used to be computed twice: once by the V1 products controller (which
 * persists it to `_woocommerce_pos_variable_prices` postmeta) and once by the sync
 * read stampers (which inject it into the served payload). The two implementations
 * disagreed on which WooCommerce filters run and on when a sale price counts, so
 * the same product could show two different ranges depending on which lane served
 * it. This class is the single computation; the two consumers are thin adapters
 * that differ ONLY in how the numbers are rendered on the wire.
 *
 * It lives in Services rather than Sync because it is a WooCommerce pricing fact
 * shared by the V1 REST surface and the V2 sync surface — V1 must not have to
 * depend on the sync subsystem to price a product.
 *
 * Canonical semantics (all lanes):
 *
 * - Children come from WooCommerce's own visible-children resolution (which applies
 *   the store's hide-hidden / hide-out-of-stock rules), MINUS the WCPOS `online_only`
 *   variations, which are hidden from the POS and must not leak a price into the range.
 * - Prices are read per child in EDIT context and pushed through WooCommerce's six
 *   variation-price filters — the three per-variation ones
 *   (`woocommerce_variation_prices_{price,regular_price,sale_price}`) and the three
 *   min/max ones (`woocommerce_get_variation_{price,regular_price,sale_price}`).
 *   Extensions that reprice variations (WCPOS Pro's store pricing among them) hook
 *   exactly these.
 * - Children are read DIRECTLY, never via `get_variation_prices()`, whose
 *   `wc_var_prices_*` transient goes stale the moment a child price changes
 *   (gap-analysis §4.3) — the stale range this module exists to prevent.
 * - A child with no active price is skipped entirely.
 * - A sale price counts ONLY when the sale is ACTIVE: it must differ from the
 *   child's regular price AND equal the child's current price. WooCommerce leaves
 *   `_sale_price` on a variation after a scheduled sale ends, so a non-empty sale
 *   price is not proof of a sale.
 *
 * Two renderings, selected by `$format`:
 *
 * - FORMAT_DECIMAL — what V1 persists to postmeta: every value run through
 *   `wc_format_decimal()` at the store's price precision, all three sub-ranges
 *   always present, an absent sub-range rendered as empty strings.
 * - FORMAT_RAW — what the sync lane puts on the wire: the child's own price
 *   strings, untouched, so no float round-trip mangles decimal precision.
 *
 * The active-sale test is always made on FORMATTED values in both renderings, so
 * `'5.0'` and `'5.00'` cannot disagree about whether a sale is running.
 */
final class Variable_Price_Range {
	/**
	 * Render values through `wc_format_decimal()` (the V1 / postmeta shape).
	 */
	public const FORMAT_DECIMAL = 'decimal';

	/**
	 * Render the child's own unformatted price strings (the sync wire shape).
	 */
	public const FORMAT_RAW = 'raw';

	/**
	 * The price sub-ranges, in wire order.
	 */
	private const FIELDS = array( 'price', 'regular_price', 'sale_price' );

	/**
	 * Compute the canonical price range for a variable product.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @param string              $format  One of FORMAT_DECIMAL or FORMAT_RAW.
	 *
	 * @return array{
	 *   ranges: array<string, array{min: string, max: string}>,
	 *   minimum_price: string,
	 *   has_prices: bool
	 * } `ranges` always carries all three sub-ranges, empty ones as empty strings.
	 *   `minimum_price` is the filtered minimum BEFORE any decimal formatting — the
	 *   value a caller should use for the parent's own `price` field, so it can apply
	 *   the request's own `dp` precision. `has_prices` is false when no visible child
	 *   carries a price at all.
	 */
	public static function for( WC_Product_Variable $product, string $format = self::FORMAT_DECIMAL ): array {
		$as_decimal = self::FORMAT_DECIMAL === $format;
		$decimals   = \function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$collected  = array_fill_keys( self::FIELDS, array() );

		foreach ( self::visible_child_ids( $product ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$price = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally invoking WC core filter.
				'woocommerce_variation_prices_price',
				$variation->get_price( 'edit' ),
				$variation,
				$product
			);

			if ( '' === $price || null === $price ) {
				continue;
			}

			$regular_price = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally invoking WC core filter.
				'woocommerce_variation_prices_regular_price',
				$variation->get_regular_price( 'edit' ),
				$variation,
				$product
			);
			$sale_price = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally invoking WC core filter.
				'woocommerce_variation_prices_sale_price',
				$variation->get_sale_price( 'edit' ),
				$variation,
				$product
			);

			$formatted_price         = wc_format_decimal( $price, $decimals );
			$formatted_regular_price = wc_format_decimal( $regular_price, $decimals );
			$formatted_sale_price    = wc_format_decimal( $sale_price, $decimals );

			// The active price keeps the child's own string in BOTH renderings; the
			// decimal rendering formats it once at the end, after the min/max filter,
			// so a filtered minimum is never rounded twice.
			$collected['price'][ $variation_id ] = (string) $price;

			if ( $as_decimal ) {
				$collected['regular_price'][ $variation_id ] = $formatted_regular_price;
			} elseif ( '' !== $regular_price && null !== $regular_price ) {
				$collected['regular_price'][ $variation_id ] = (string) $regular_price;
			}

			if ( '' !== $sale_price && null !== $sale_price
				&& $formatted_sale_price !== $formatted_regular_price
				&& $formatted_sale_price === $formatted_price ) {
				$collected['sale_price'][ $variation_id ] = $as_decimal ? $formatted_sale_price : (string) $sale_price;
			}
		}

		foreach ( $collected as $field => $values ) {
			asort( $values, SORT_NUMERIC );
			$collected[ $field ] = $values;
		}

		$price_range   = self::apply_range_filter( 'woocommerce_get_variation_price', self::min_max( $collected['price'] ), $product );
		$minimum_price = $price_range['min'];
		if ( $as_decimal ) {
			$price_range = array(
				'min' => '' === $price_range['min'] ? '' : wc_format_decimal( $price_range['min'], $decimals ),
				'max' => '' === $price_range['max'] ? '' : wc_format_decimal( $price_range['max'], $decimals ),
			);
		}

		$ranges = array(
			'price'         => $price_range,
			'regular_price' => self::apply_range_filter(
				'woocommerce_get_variation_regular_price',
				self::min_max( $collected['regular_price'] ),
				$product
			),
			'sale_price'    => self::apply_range_filter(
				'woocommerce_get_variation_sale_price',
				self::min_max( $collected['sale_price'] ),
				$product
			),
		);

		return array(
			'ranges'        => $ranges,
			'minimum_price' => $minimum_price,
			'has_prices'    => self::any_range_populated( $ranges ),
		);
	}

	/**
	 * The visible child variation ids, minus the ones hidden from the POS.
	 *
	 * WooCommerce's visible-children resolution applies the store's WEB visibility
	 * rules, NOT the WCPOS `online_only` exclusion — a child hidden from the POS
	 * would otherwise leak its price into the served range. The subtraction is gated
	 * on the feature toggle inside Pos_Visibility, which returns an empty list when
	 * the toggle is off.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 *
	 * @return array<int, int>
	 */
	private static function visible_child_ids( WC_Product_Variable $product ): array {
		return ( new Pos_Visibility() )->filter_visible_children( $product->get_visible_children() );
	}

	/**
	 * Apply a WooCommerce min/max variation price filter to a computed range.
	 *
	 * An empty end is left alone: an absent sub-range must stay absent, and handing
	 * `''` to a repricing filter would invite a `0.00` back.
	 *
	 * @param string                          $hook_name   WooCommerce price range filter name.
	 * @param array{min: string, max: string} $price_range Computed range.
	 * @param WC_Product_Variable             $product     Variable product.
	 *
	 * @return array{min: string, max: string}
	 */
	private static function apply_range_filter( string $hook_name, array $price_range, WC_Product_Variable $product ): array {
		if ( '' !== $price_range['min'] ) {
			$price_range['min'] = (string) apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally invoking WC core filter.
				$hook_name,
				$price_range['min'],
				$product,
				'min',
				false
			);
		}

		if ( '' !== $price_range['max'] ) {
			$price_range['max'] = (string) apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally invoking WC core filter.
				$hook_name,
				$price_range['max'],
				$product,
				'max',
				false
			);
		}

		return $price_range;
	}

	/**
	 * Convert a numerically-sorted variation price list to a min/max range.
	 *
	 * @param array<int, string> $prices Prices keyed by variation id, sorted ascending.
	 *
	 * @return array{min: string, max: string}
	 */
	private static function min_max( array $prices ): array {
		if ( empty( $prices ) ) {
			return array(
				'min' => '',
				'max' => '',
			);
		}

		return array(
			'min' => (string) reset( $prices ),
			'max' => (string) end( $prices ),
		);
	}

	/**
	 * Whether any sub-range carries a value.
	 *
	 * @param array<string, array{min: string, max: string}> $ranges Computed sub-ranges.
	 */
	private static function any_range_populated( array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( '' !== $range['min'] || '' !== $range['max'] ) {
				return true;
			}
		}

		return false;
	}
}
