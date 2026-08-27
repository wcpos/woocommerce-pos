<?php
/**
 * WCPOS REST CORS and cache wire contract.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The single owner of the WCPOS REST wire contract.
 *
 * ## Why one owner
 *
 * `Access-Control-Allow-Headers` is the one response header where a missing
 * entry does not degrade a feature: the browser refuses the ACTUAL request,
 * so a cross-origin till goes offline outright with no client-side recovery.
 * The same set used to be published by two hand-maintained writers — Init's
 * preflight handler and API's `rest_allowed_cors_headers` callback — and they
 * drifted apart twice in one week (`23bcdb47`, `118a091f`), each time taking
 * every cross-origin till down. `6b10fdcd` then moved the cache contract to
 * Init because the relay's consent route is served WITHOUT constructing API.
 * All of that lives here now, in one class, published by one handler.
 *
 * ## Why priority 20
 *
 * WP core hooks `rest_send_cors_headers()` onto `rest_pre_serve_request` at
 * priority 10, and it publishes an ORIGIN-SPECIFIC `Access-Control-Allow-
 * Origin`. `WP_REST_Server::send_header()` calls PHP's `header()` with
 * `$replace = true`, so the last writer wins: running at 20 is what makes the
 * WCPOS `*` origin survive on the lanes that are ours, including a preflight
 * to a non-WCPOS namespace (`/wc/v3/...`) that announces a WCPOS header,
 * where API is never constructed. Everything WCPOS writes must therefore run
 * after 10 — and, because a later writer WINS rather than merely adds, what
 * we claim has to be exactly ours ({@see self::owns_request()}).
 * Raw byte responses echo their body from their own callback and are pushed
 * to priority 30 for the same reason ({@see API\V1\Raw_Response::serve()}):
 * headers cannot be sent after the body.
 *
 * ## Why registered unconditionally
 *
 * The Cloud Print relay proves site consent by fetching the verification
 * route WITHOUT the `X-WCPOS` marker, and it is served without constructing
 * API ({@see Init::register_public_relay_routes()}), so a hook registered
 * behind the marker gate would miss it — a shared cache could then replay a
 * single-use verification token as stale proof. CORS preflights carry no
 * request headers at all (Fetch spec), so they cannot be gated either.
 * Registration is unconditional; {@see self::owns_request()} decides per
 * request what is published.
 */
final class Rest_Cors {
	/**
	 * Response headers a cross-origin client is allowed to READ.
	 *
	 * `X-WCPOS-Pressure` is also emitted by the bootstrap ping fast path,
	 * which short-circuits before WP REST loads ({@see API\V2\Ping}).
	 *
	 * @var string[]
	 */
	public const EXPOSE_HEADERS = array(
		'X-WP-Total',            // Total number of records in a collection.
		'X-WP-TotalPages',       // Total number of pages in a collection.
		'Link',                  // Pagination and API discovery.
		'X-Server-Load',         // Response telemetry.
		'Server-Timing',         // Response telemetry.
		'X-WCPOS-Memory-Peak',   // Response telemetry.
		'X-WCPOS-Pressure',      // Host pressure bucket, also sent by the ping fast path.
		'ETag',                  // Conditional sequence-log polling (304s).
		'Date',                  // Server clock, used for client drift correction.
	);

	/**
	 * Request headers allowed through preflight, before the sync-lane set.
	 *
	 * The first five are WP core's own defaults, repeated so this list stands
	 * on its own: the handler publishes it directly and core's copy is
	 * overwritten. The sync-lane additions are merged in from Sync\Cors.
	 *
	 * Known duplication, recorded rather than fixed here: the first six entries
	 * restate WordPress core's own defaults from WP_REST_Server::serve_request(),
	 * and the contract test restates them a third time. If core ever adds a
	 * seventh, both copies drift and no test fails — the same class of bug this
	 * module exists to remove, one level up. The real fix is to stop republishing
	 * the allow-list here at all and hook `rest_allowed_cors_headers` instead,
	 * since core already writes it unconditionally before any
	 * `rest_pre_serve_request` filter runs; that shrinks this module to what
	 * genuinely needs last-writer-wins (Allow-Origin, Max-Age, cache contract).
	 * That is a behaviour-shaped change and does not belong in a release-week
	 * refactor of a High-tier path.
	 *
	 * Access-Control-Allow-Methods matches core's list exactly, OPTIONS included.
	 * The handler this replaces omitted OPTIONS, but it wrote at priority 5 and
	 * core overwrote it at 10 whenever an Origin was present — so the value that
	 * actually reached the wire was core's. Winning at 20 without OPTIONS would
	 * have changed the wire for the first time in that header's life.
	 *
	 * @var string[]
	 */
	public const ALLOW_HEADERS_BASE = array(
		'Authorization',            // For user-agent authentication with a server.
		'X-WP-Nonce',               // WordPress-specific header, used for CSRF protection.
		'Content-Disposition',      // Informs how to process the response data.
		'Content-MD5',              // For verifying data integrity.
		'Content-Type',             // Specifies the media type of the resource.
		'X-HTTP-Method-Override',   // Used to override the HTTP method.
		'X-WCPOS',                  // Used to identify WCPOS requests.
	);

	/**
	 * Request headers a shared cache must key WCPOS responses on.
	 *
	 * Authorization keys per bearer token and X-WCPOS-Store per store scope —
	 * the same token requesting different scopes receives store-specific
	 * pricing and taxes, so a shared entry must not span stores either.
	 *
	 * @var string[]
	 */
	public const VARY_TOKENS = array( 'Origin', 'Authorization', Sync\Store_Scope::HEADER );

	/**
	 * Preflight cache lifetime, in seconds.
	 *
	 * Without it the Fetch spec caches a preflight for only FIVE seconds, so
	 * every cross-origin POS request is two requests. 7200 is Chromium's cap
	 * (Firefox allows 86400).
	 */
	public const MAX_AGE = '7200';

	/**
	 * WCPOS REST routes, matched case-insensitively because WP dispatches
	 * REST routes with a case-insensitive regex: `/WCPOS/V1/...` reaches the
	 * controllers, so it must reach this contract too.
	 */
	private const ROUTE_PATTERN = '#^/wcpos/v\d+(?:/|$)#i';

	/**
	 * The lanes the cache contract covers: the two shipped namespaces.
	 *
	 * Deliberately narrower than ROUTE_PATTERN — a namespace added through
	 * `woocommerce_pos_rest_namespaces` publishes its own response semantics,
	 * and this guard has never covered it. Preflights are the exception:
	 * every preflight this class ANSWERS gets the cache contract regardless
	 * of lane ({@see self::rest_pre_serve_request()}), because the answer
	 * depends on the announced headers; the narrowing here binds real
	 * responses only.
	 */
	private const CACHE_ROUTE_PATTERN = '#^/wcpos/v[12](?:/|$)#i';

	/**
	 * The prefix every WCPOS-specific request header shares.
	 *
	 * A preflight carries no headers of its own, but it ANNOUNCES the ones the
	 * real request will send in `Access-Control-Request-Headers` — the marker
	 * `X-WCPOS` plus the scope and idempotency headers ({@see Sync\Cors}) all
	 * start with this, so a preflight destined for us is identifiable without
	 * having to guess from the route alone.
	 */
	private const MARKER_HEADER_PREFIX = 'x-wcpos';

	/** Makes CR/LF/NUL and malformed names unreachable in the response header. */
	private const HEADER_NAME_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/';

	/**
	 * Reflection budget, names axis ({@see self::preflight_allow_headers()}).
	 *
	 * Bounds a hostile many-short-names announcement, which the byte budget
	 * alone would not. A legitimate client announces well under ten names
	 * beyond the floor.
	 */
	private const REFLECT_MAX_NAMES = 16;

	/**
	 * Reflection budget, bytes axis ({@see self::preflight_allow_headers()}).
	 *
	 * Bounds a hostile few-long-names announcement. With the ~250-byte floor
	 * the emitted header stays well under the smallest real proxy
	 * response-header ceilings (nginx buffers 4 KB per header line).
	 */
	private const REFLECT_MAX_BYTES = 256;

	/**
	 * Register the wire contract. Unconditional — see the class docblock.
	 */
	public static function register_hooks(): void {
		add_filter( 'rest_allowed_cors_headers', array( self::class, 'allowed_cors_headers' ), 10, 1 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'rest_pre_serve_request' ), 20, 4 );
	}

	/**
	 * WP core's `rest_allowed_cors_headers` filter: the request headers a POS
	 * client may send.
	 *
	 * Both publishers of the allow-list — core's own write in
	 * `WP_REST_Server::serve_request()` and this class's preflight handler —
	 * pass through here, so there is exactly one list.
	 *
	 * @param string[] $allow_headers The allow-list under construction.
	 *
	 * @return string[] $allow_headers
	 */
	public static function allowed_cors_headers( array $allow_headers ): array {
		return Sync\Cors::allow_headers( array_merge( $allow_headers, self::ALLOW_HEADERS_BASE ) );
	}

	/**
	 * Publish the WCPOS CORS and cache contract on a REST response.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 *                                  Default false.
	 * @param WP_HTTP_Response $result  Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 *
	 * @return bool $served
	 */
	public static function rest_pre_serve_request( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
		$owns_request = self::owns_request( $request );
		if ( $owns_request && 'OPTIONS' === $request->get_method() ) {
			self::send_cache_defeating_headers( $result, $server, array( 'Access-Control-Request-Headers' ) );
		} elseif ( preg_match( self::CACHE_ROUTE_PATTERN, (string) $request->get_route() ) ) {
			self::send_cache_defeating_headers( $result, $server );
		}

		if ( ! $owns_request ) {
			return $served;
		}

		// Core's own filter, re-applied here because this write replaces the
		// one core made in WP_REST_Server::serve_request().
		$expose_headers = apply_filters( 'rest_exposed_cors_headers', self::EXPOSE_HEADERS, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.

		$server->send_header( 'Access-Control-Allow-Origin', '*' );
		$server->send_header( 'Access-Control-Expose-Headers', implode( ', ', array_unique( $expose_headers ) ) );

		if ( 'OPTIONS' === $request->get_method() ) {
			$server->send_header( 'Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE' );
			$server->send_header( 'Access-Control-Allow-Headers', implode( ', ', array_unique( self::preflight_allow_headers( $request ) ) ) );
			$server->send_header( 'Access-Control-Max-Age', self::MAX_AGE );
		}

		return $served;
	}

	/**
	 * The preflight allow-list: the frozen floor plus reflected announcements.
	 *
	 * The floor (ALLOW_HEADERS_BASE ∪ Sync\Cors::headers(), through core's
	 * filter) is frozen — {@see Sync\Cors::headers()}. Any `x-wcpos-*` name
	 * the browser announces in `Access-Control-Request-Headers` is reflected
	 * after it, so a header a future client invents is pre-authorized the
	 * moment it ships instead of waiting out the plugin-update lag that took
	 * tills offline in 23bcdb47, 118a091f, and forced #1760's query twins.
	 * Reflection grants nothing: it tells the browser it MAY send the name;
	 * every route keeps its permission callback, and the server ignores
	 * names it does not read. {@see API\V2\Echo_Probe} advertises this
	 * capability to clients (`cors.reflects_request_headers`) — narrowing
	 * reflection later must update that field.
	 *
	 * A non-browser can put arbitrary bytes in the announcement, so
	 * reflected names are token-checked (HEADER_NAME_PATTERN) and budgeted
	 * on two independent axes (REFLECT_MAX_NAMES, REFLECT_MAX_BYTES). An
	 * oversized name is SKIPPED, never truncated — and never aborts the
	 * names after it, or one hostile entry could starve a legitimate header
	 * and take the till offline: the exact outage class reflection removes.
	 *
	 * Degradation contract: an absent or proxy-stripped announcement yields
	 * the floor, byte-identical to the pre-reflection wire.
	 *
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return string[] Floor names in canonical casing, reflected extras in lowercase.
	 */
	private static function preflight_allow_headers( WP_REST_Request $request ): array {
		// Same list core built in serve_request(), through the same
		// filter, so a third party that hooks it reaches both writes.
		$allow_headers   = apply_filters( 'rest_allowed_cors_headers', self::ALLOW_HEADERS_BASE, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$allowed_names   = array_fill_keys( array_map( 'strtolower', $allow_headers ), true );
		$reflected       = 0;
		$reflected_bytes = 0;

		foreach ( self::announced_header_names( $request ) as $header ) {
			// The bare marker (`x-wcpos`) is already in the floor; reflected
			// extras must carry the hyphenated namespace, so `x-wcposter`
			// stays a stranger's header.
			if ( ! preg_match( self::HEADER_NAME_PATTERN, $header ) || 0 !== strpos( $header, self::MARKER_HEADER_PREFIX . '-' ) || isset( $allowed_names[ $header ] ) ) {
				continue;
			}
			if ( self::REFLECT_MAX_NAMES <= $reflected ) {
				break;
			}
			if ( self::REFLECT_MAX_BYTES < $reflected_bytes + strlen( $header ) ) {
				continue;
			}

			$allow_headers[]          = $header;
			$allowed_names[ $header ] = true;
			++$reflected;
			$reflected_bytes += strlen( $header );
		}

		return $allow_headers;
	}

	/**
	 * Whether this request is destined for WCPOS.
	 *
	 * Ours is: a WCPOS-namespace route (marked or not — the relay consent
	 * route is deliberately unmarked), a marked request whatever the route
	 * (the POS reads `wc/v3` collections too), or a preflight that ANNOUNCES
	 * one of our headers.
	 *
	 * Note what is NOT here: a bare OPTIONS. The old handler claimed every
	 * preflight on the site, which was harmless only because it ran at
	 * priority 5 and core overwrote it at 10. At priority 20 we win, and
	 * stamping `Access-Control-Allow-Origin: *` on another plugin's route
	 * would break credentialed cross-origin requests that have nothing to do
	 * with WCPOS (core pairs its origin-specific answer with
	 * `Access-Control-Allow-Credentials: true`, which `*` invalidates).
	 * Preflights we do not claim keep core's answer, and they still carry the
	 * full WCPOS allow-list: core builds that one through
	 * `rest_allowed_cors_headers`, which {@see self::allowed_cors_headers()}
	 * filters unconditionally.
	 *
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return bool
	 */
	private static function owns_request( WP_REST_Request $request ): bool {
		if ( preg_match( self::ROUTE_PATTERN, (string) $request->get_route() ) ) {
			return true;
		}

		if ( ! empty( $request->get_header( 'X-' . SHORT_NAME ) ) ) {
			return true;
		}

		// The query-var marker, for clients behind a proxy that strips custom
		// request headers ({@see wcpos_request()}).
		$query_params = $request->get_query_params();
		if ( ! empty( $query_params[ SHORT_NAME ] ) ) {
			return true;
		}

		return 'OPTIONS' === $request->get_method() && self::preflight_announces_wcpos( $request );
	}

	/**
	 * Whether a preflight says the request it precedes will be a WCPOS one.
	 *
	 * The browser builds `Access-Control-Request-Headers` from the headers the
	 * real request carries, so a marked request to a route outside our
	 * namespaces — the shape that took the till offline in 23bcdb47 and
	 * 118a091f — announces `x-wcpos` here and is answered as ours.
	 *
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return bool
	 */
	private static function preflight_announces_wcpos( WP_REST_Request $request ): bool {
		foreach ( self::announced_header_names( $request ) as $header ) {
			if ( 0 === strpos( $header, self::MARKER_HEADER_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse the header names announced by a CORS preflight.
	 *
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return string[] Trimmed, lowercase, non-empty header names.
	 */
	private static function announced_header_names( WP_REST_Request $request ): array {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', strtolower( (string) $request->get_header( 'Access-Control-Request-Headers' ) ) ) ),
				static function ( string $header ): bool {
					return '' !== $header;
				}
			)
		);
	}

	/**
	 * Defeat shared caching of WCPOS REST responses.
	 *
	 * Hosting layers cache authenticated REST GETs and replay them across
	 * users (LiteSpeed caches REST by default for 7 days with no
	 * Authorization bypass; WP Engine's edge cache excludes /wp-json/wc but
	 * not /wp-json/wcpos; Sucuri's default levels ignore Cache-Control).
	 *
	 * Vary is defense-in-depth for intermediaries that ignore no-store but
	 * honor Vary ({@see self::VARY_TOKENS}). Existing Vary tokens are
	 * preserved (deduped case-insensitively); a wildcard Vary stays alone,
	 * since '*' is grammatically an alternative to a field list. Preflights
	 * also vary on their announced headers because the answer depends on them
	 * (RFC 9111 section 4.1).
	 *
	 * @param WP_HTTP_Response $result            Result to send to the client.
	 * @param WP_REST_Server   $server            Server instance.
	 * @param string[]         $extra_vary_tokens Additional Vary tokens.
	 */
	private static function send_cache_defeating_headers( WP_HTTP_Response $result, WP_REST_Server $server, array $extra_vary_tokens = array() ): void {
		$response_headers = array_change_key_case( $result->get_headers(), CASE_LOWER );
		$existing_vary    = isset( $response_headers['vary'] )
			? array_values(
				array_filter(
					array_map( 'trim', explode( ',', (string) $response_headers['vary'] ) ),
					static function ( string $token ): bool {
						return '' !== $token;
					}
				)
			)
			: array();

		if ( in_array( '*', $existing_vary, true ) ) {
			$vary = '*';
		} else {
			$vary_tokens = array_merge( $existing_vary, self::VARY_TOKENS, $extra_vary_tokens );
			$vary_tokens = array_change_key_case( array_combine( $vary_tokens, $vary_tokens ), CASE_LOWER );
			$vary        = implode( ', ', $vary_tokens );
		}

		$server->send_header( 'Cache-Control', 'private, no-store' );
		$server->send_header( 'Vary', $vary );
		do_action( 'litespeed_control_set_nocache', 'wcpos rest response' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook
	}
}
