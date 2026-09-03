<?php
/**
 * Classify the current request into the lane that decides which POS services it needs.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Admin\Permalink;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * One classifier for "what kind of request is this?", computed once per request.
 *
 * Why it exists: `Init::init_common()` used to construct every POS service on
 * every request. On a storefront page that was ~80 plugin files and 22 objects
 * for hooks that never fire there (measured 2026-09-03, see
 * .claude/research/2026-09-03-online-store-footprint.md and the
 * lazy-service-construction spec beside it). The lane is an OPTIMISATION, not a
 * correctness gate: order services are also constructed late, on the first
 * order write of any request (see `Init::ensure_order_services()`), so a lane
 * misclassified as storefront still gets every observer before an order is
 * touched.
 *
 * Detection runs on `init`, before `REST_REQUEST` is defined and before the
 * rewrite rules populate the POS query vars, so REST and the browser-loaded
 * POS routes are matched from the request path. WooCommerce's `wc-ajax`
 * endpoints (cart fragments, add to cart, the classic checkout) are shopper
 * traffic and stay on the storefront lane even though WooCommerce marks them
 * DOING_AJAX; admin-ajax.php is covered by `is_admin()`.
 */
final class Request_Lane {
	public const ADMIN      = 'admin';
	public const CRON       = 'cron';
	public const CLI        = 'cli';
	public const REST       = 'rest';
	public const POS        = 'pos';
	public const STOREFRONT = 'storefront';

	/**
	 * Memoised lane for this request.
	 *
	 * @var string|null
	 */
	private static ?string $lane = null;

	/**
	 * The lane of the current request.
	 *
	 * @return string One of the class constants.
	 */
	public static function current(): string {
		if ( null === self::$lane ) {
			/**
			 * Filters the request lane. Hosts and tests can force one; the
			 * default is {@see Request_Lane::detect()}.
			 *
			 * @param string $lane One of the Request_Lane constants.
			 */
			self::$lane = (string) apply_filters( 'woocommerce_pos_request_lane', self::detect() );
		}
		return self::$lane;
	}

	/** Whether the request is a plain shopper page: shop, product, cart, account, wc-ajax. */
	public static function is_storefront(): bool {
		return self::STOREFRONT === self::current();
	}

	/**
	 * Forget the memoised lane. Tests only: the single PHPUnit process never
	 * ends a request.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$lane = null;
	}

	/**
	 * Detect the lane from the environment WordPress has established by `init`.
	 *
	 * @return string
	 */
	private static function detect(): string {
		if ( \defined( 'WP_CLI' ) && WP_CLI ) {
			return self::CLI;
		}
		if ( wp_doing_cron() ) {
			return self::CRON;
		}
		if ( is_admin() ) {
			return self::ADMIN;
		}
		if ( \function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request() ) {
			return self::POS;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only inspected for a prefix.
		if ( '' === $uri ) {
			return self::STOREFRONT;
		}
		// The raw ?wcpos=1 marker is not in the query vars yet at init.
		if ( 1 === preg_match( '#(?:\?|&)' . preg_quote( SHORT_NAME, '#' ) . '=1(?:&|$)#', $uri ) ) {
			return self::POS;
		}
		if ( false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' ) || false !== strpos( $uri, 'rest_route=' ) ) {
			return self::REST;
		}
		$path      = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home_path = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '/' !== $home_path && 0 === strpos( trailingslashit( $path ), $home_path ) ) {
			$path = (string) substr( $path, \strlen( untrailingslashit( $home_path ) ) );
		}
		$slug = Permalink::get_slug();
		if ( 1 === preg_match( '#^/(?:index\.php/)?(' . preg_quote( $slug, '#' ) . '|wcpos-[a-z-]+)(/|$)#i', $path ) ) {
			return self::POS;
		}
		return self::STOREFRONT;
	}
}
