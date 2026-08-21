<?php
/**
 * WCPOS sync store scope.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_REST_Request;

/**
 * The till's STORE SCOPE, carried across the v2 sync lane.
 *
 * ## Why this exists
 *
 * On the v1 lane the client's legacy REST http client attached `store_id` as a
 * query param to every request, and the extending controller read it back with
 * `$request->get_param( 'store_id' )`. The v2 sync lanes do not use that http
 * client — they talk to `wcpos/v2` through the engine's own fetcher — so the
 * whole successor surface carried NO store context at all. Anything keyed on
 * the scoped store (Pro's per-store product pricing and taxes) therefore went
 * dark on v2: a till price edit was forwarded to stock `wc/v3` as a plain
 * global price write (pro#425).
 *
 * ## The contract
 *
 * The client sends the scoped store as the `X-WCPOS-Store` request header —
 * a header rather than a query param so nothing has to rewrite sync URLs, and
 * so the scope rides pulls, pushes and acks alike.
 *
 * This class translates that header back into V1'S CONTRACT SHAPE. Every inner
 * request the v2 lane builds — the `wc/v3` write forward, the catalog proxy's
 * read forward, each product/variation serialization — is stamped with a
 * `store_id` param, so a consumer written against v1 (`$request->get_param(
 * 'store_id' )`) keeps working unchanged on v2. {@see self::stamp()}.
 *
 * The ambient {@see self::current()} covers the consumers that have no request
 * to read: WooCommerce's variation price filters
 * (`woocommerce_get_variation_regular_price` and friends) are handed a product
 * and a min/max flag and nothing else.
 *
 * ## What this class deliberately does NOT do
 *
 * It does not authorize the store. Stores are a Pro concept; the free plugin
 * has no store registry and no notion of which stores a cashier may act as.
 * This is the same split as `woocommerce_pos_order_store_reassignment_allowed`
 * (free#1550 / pro#426): free carries and shapes the scalar, Pro rules on it.
 *
 * It also never invents a scope. A missing, blank, zero or non-numeric header
 * resolves to `null`, and a null scope stamps NOTHING — the inner request
 * carries no `store_id` at all, exactly as a v1 request from an unscoped
 * client would. Downstream that reads as "the store is UNKNOWN", which is a
 * materially different state from "the store is global" and must stay
 * distinguishable: a consumer that owns store-scoped data is expected to
 * refuse an ambiguous write rather than fall back to the global fields.
 * Store `0` is the client's single-store sentinel (the same one the order lane
 * tests before stamping `_pos_store`) and is normalized away here.
 */
final class Store_Scope {
	/** The request header carrying the till's store scope. */
	public const HEADER = 'X-WCPOS-Store';

	/** The request param the scope is republished as — v1's contract shape. */
	public const PARAM = 'store_id';

	/**
	 * The scope of the request currently being served, or null when unscoped.
	 *
	 * @var null|int
	 */
	private static $current = null;

	/**
	 * Nesting depth of in-flight v2 lane operations.
	 *
	 * @var int
	 */
	private static $lane_depth = 0;

	/**
	 * Resolve the store scope of an incoming WCPOS request.
	 *
	 * A usable header always wins. When NO header arrived at all, the
	 * `store_id` param is honoured on both lanes — v1's native wire form, and
	 * the v2 fallback for hosts that strip the header (hostile-headers B6,
	 * wcpos-infra#72). A header that was sent but is unusable still resolves
	 * to UNKNOWN on v2 — see the ruling in the resolve() body.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return null|int A positive store id, or null when the scope is unknown.
	 */
	public static function resolve( WP_REST_Request $request ): ?int {
		$header = $request->get_header( self::HEADER );
		$scope  = self::normalize( $header );

		if ( null !== $scope ) {
			return $scope;
		}

		// Absent and malformed are different signals (#1558 review ruling):
		// only a header that never arrived invites another wire form. A header
		// that WAS sent but is unusable stamps NOTHING — the caller believes it
		// named a store, and substituting a scope from elsewhere would land the
		// write somewhere it did not name. Hostile hosts STRIP the header
		// (absence), so the hostile case is exactly the one the param covers
		// (hostile-headers B6, wcpos-infra#72). The v1 lane keeps its legacy
		// behavior: the param IS its native wire form.
		$is_v1 = 0 === strpos( $request->get_route(), '/wcpos/v1/' );
		if ( ! $is_v1 && null !== $header ) {
			return null;
		}

		return self::normalize( $request->get_param( self::PARAM ) );
	}

	/**
	 * Record the scope of the request being served.
	 *
	 * @param null|int $store_id A positive store id, or null when unscoped.
	 */
	public static function set_current( ?int $store_id ): void {
		self::$current = ( null !== $store_id && $store_id > 0 ) ? $store_id : null;
	}

	/**
	 * The scope of the request being served, or null when unknown.
	 *
	 * Null means UNKNOWN, never "global" — see the class docblock.
	 *
	 * @return null|int
	 */
	public static function current(): ?int {
		return self::$current;
	}

	/**
	 * Run a v2 lane operation with the lane marker raised.
	 *
	 * Wrap every inner request the v2 lane dispatches, and every product it
	 * serializes, so a consumer can tell OUR traffic from everyone else's.
	 *
	 * The scope alone cannot answer that: {@see self::current()} is null both
	 * for an unscoped v2 push and for a stock `wc/v3` request from wp-admin or
	 * a third-party integration. A consumer keying only on the scope cannot
	 * distinguish a till that failed to name its store from a caller that was
	 * never a till — and treating the second like the first breaks
	 * WooCommerce's own API for any store-priced product (pro#425 review).
	 *
	 * Scoped around the operation rather than latched for the whole request on
	 * purpose. A latch needs a teardown hook, and there isn't a safe one:
	 * `rest_post_dispatch` fires for embedded-link sub-requests too, so it
	 * would clear the marker in the middle of the very request that owns it.
	 * A depth counter unwound in `finally` cannot leak, nests correctly, and
	 * is true exactly while a v2 operation is on the stack.
	 *
	 * @template T
	 *
	 * @param callable():T $operation The lane operation.
	 *
	 * @return T
	 */
	public static function in_v2_lane( callable $operation ) {
		++self::$lane_depth;

		try {
			return $operation();
		} finally {
			if ( self::$lane_depth > 0 ) {
				--self::$lane_depth;
			}
		}
	}

	/**
	 * Whether a v2 lane operation is currently in flight.
	 */
	public static function is_v2_lane(): bool {
		return self::$lane_depth > 0;
	}

	/**
	 * Forget the current scope (request teardown, and test isolation).
	 */
	public static function reset(): void {
		self::$current    = null;
		self::$lane_depth = 0;
	}

	/**
	 * Republish the ambient scope onto an inner request as `store_id`.
	 *
	 * Called wherever the v2 lane constructs a request it is about to dispatch
	 * or serialize through, so consumers keep reading the scope the v1 way.
	 * A caller that already set an explicit `store_id` wins — stamping is a
	 * default, not an override — and an unknown scope stamps nothing at all.
	 *
	 * STAMP LAST. `set_body_params()` / `set_query_params()` / `set_url_params()`
	 * each REPLACE their whole bag, so a stamp applied before one of them is
	 * silently discarded — and the failure mode is not a crash, it is a price
	 * edit quietly landing on the global fields.
	 *
	 * @param WP_REST_Request $request The inner request to stamp.
	 *
	 * @return WP_REST_Request The same request, for chaining.
	 */
	public static function stamp( WP_REST_Request $request ): WP_REST_Request {
		if ( null === self::$current ) {
			return $request;
		}

		if ( null !== self::normalize( $request->get_param( self::PARAM ) ) ) {
			return $request;
		}

		$request->set_param( self::PARAM, self::$current );

		return $request;
	}

	/**
	 * Narrow a raw wire value to a usable store id.
	 *
	 * Only a positive integer is a store. Blank, `0` (the client's single-store
	 * sentinel) and anything non-numeric are all UNKNOWN — never coerced to a
	 * store, and never coerced to global.
	 *
	 * @param mixed $value The raw header or param value.
	 *
	 * @return null|int
	 */
	private static function normalize( $value ): ?int {
		if ( null === $value || \is_array( $value ) || \is_object( $value ) || \is_bool( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		// Reject decimals, signs and other numeric-ish forms outright: a store id
		// is a post id. `ctype_digit` on the trimmed string is the whole rule.
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}

		$store_id = (int) $value;

		return $store_id > 0 ? $store_id : null;
	}
}
