<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Narrows a variable product's advertised child list to what the POS may actually sell.
 *
 * # Why
 *
 * `product.variations[]` is the client's ONLY discovery list for a variable product: the row
 * expansion, the POS variations popover, the "Showing N of M" footer and the idle prefetch walk all
 * read it and nothing else. WooCommerce fills it from `WC_Product_Variable::get_children()`, which
 * returns every child with `post_status IN ( 'publish', 'private' )` and knows nothing about POS
 * visibility.
 *
 * Every other component that answers "which children exist" already narrows the set — the
 * variations endpoint refuses to hydrate a hidden or disabled child, {@see Variable_Price_Range}
 * computes its range from `get_visible_children()`, and the census counts only servable rows. The
 * parent payload was the one that did not, so a store hiding or disabling a variation got four
 * components giving three answers:
 *
 *  - the footer renders "Showing 2 of 3" forever, with no way to reach the third;
 *  - the popover offers a row that cannot be added;
 *  - the row expansion asks for the id, receives a shortfall, prunes it, and asks again on the next
 *    expansion — permanent churn;
 *  - the prefetch walk spends one request per walk on an id the server will never serve.
 *
 * Filtering here fixes all four at once, because it fixes the fact they all read.
 *
 * # What is filtered
 *
 * Two rules, both WooCommerce's own:
 *
 *  - **POS visibility** — `online_only` children, per {@see Pos_Visibility}. Ours, and additive: it
 *    changes which records are served, never what a field means.
 *  - **Disabled children** — `post_status !== 'publish'`. WooCommerce's Enabled checkbox on the
 *    variation metabox writes `post_status = private` when unchecked, and WooCommerce honours that
 *    everywhere a customer can reach (`get_visible_children()`, `get_available_variations()`). A
 *    cashier must not be offered a variation the owner switched off.
 *
 * # Ordering
 *
 * Registered as a record augmenter at priority 10 — AFTER the revision stamper at 9. The parent's
 * `_rxdb_revision` therefore stays a hash of the bare wc/v3 bytes, which is what the write path
 * recomputes from a bare re-read, so narrowing this list cannot perturb optimistic concurrency.
 * Same ordering {@see Variable_Prices} already relies on when it blanks the parent's price fields.
 *
 * # Why round-tripping the narrowed list is safe
 *
 * A client's product update layers its resident stored payload, so the narrowed array does travel
 * back to wc/v3. `variations` is `readonly => true` in WooCommerce's product schema (v2 and v3), so
 * WooCommerce ignores it on write and no child can be removed by omission. Anything that changes
 * this class must re-verify that property.
 */
final class Variable_Children {
	/**
	 * Narrow `variations[]` on a serialized variable product.
	 *
	 * Non-variable products and payloads without a usable list pass through untouched. The `type`
	 * gate comes first so a page of simple products never pays for a lookup.
	 *
	 * @param mixed      $payload Serialized product record.
	 * @param null|mixed $object  Product object, when the lane has one loaded.
	 * @param null|mixed $request Request context.
	 */
	public static function augment_record( $payload, $object = null, $request = null ) {
		if ( ! \is_array( $payload ) || 'variable' !== ( $payload['type'] ?? '' ) ) {
			return $payload;
		}
		if ( ! isset( $payload['variations'] ) || ! \is_array( $payload['variations'] ) ) {
			return $payload;
		}

		$children = array_values( array_filter( array_map( 'intval', $payload['variations'] ) ) );
		if ( array() === $children ) {
			return $payload;
		}

		$payload['variations'] = array_values(
			array_filter(
				( new Pos_Visibility() )->filter_visible_children( $children ),
				static function ( int $id ): bool {
					return 'publish' === get_post_status( $id );
				}
			)
		);

		return $payload;
	}
}
