<?php
/**
 * WCPOS REST CORS wire-contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact contract-focused documentation.

use WCPOS\WooCommercePOS\Rest_Cors;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The wire contract published by the single owner of WCPOS CORS.
 *
 * `Access-Control-Allow-Headers` is the one header where a missing entry does
 * not degrade a feature: the browser refuses the ACTUAL request, so every
 * cross-origin till goes offline. It has been broken twice by two writers
 * drifting apart (23bcdb47, 118a091f), which is what this file ratchets.
 *
 * @covers \WCPOS\WooCommercePOS\Rest_Cors
 */
class Test_Cors_Contract extends WCPOS_REST_Unit_Test_Case {
	/**
	 * A literal current-lane route, so the lane-coverage scanner can classify
	 * these cases (a route assembled at runtime is invisible to it).
	 */
	private const CURRENT_LANE_ROUTE = '/wcpos/v2/products';

	/**
	 * A core WooCommerce route: the POS reads these collections too, and its
	 * preflight for them carries no WCPOS marker.
	 */
	private const NON_WCPOS_ROUTE = '/wc/v3/products';

	/**
	 * Every request header the POS client sends, as literal wire strings.
	 *
	 * Sources in the monorepo: engine-fetcher / recordPushAdapter /
	 * change-signal-source (the v2 sync engine), plus use-checkout-session
	 * and use-refund-mutation (the checkout/refund lane's
	 * X-WCPOS-Idempotency-Key).
	 *
	 * Deliberately NOT built from the server-side constants: this is the wire
	 * contract with the client, and a server-side rename must fail here, not
	 * silently follow. A header missing from the allow-list fails every
	 * cross-origin request that carries it at preflight.
	 *
	 * @var string[]
	 */
	private const CLIENT_SENT_HEADERS = array(
		'Authorization',
		'Content-Type',
		'X-WCPOS',
		'X-WCPOS-Store',
		'X-WCPOS-Idempotency-Key',
		'Idempotency-Key',
		'If-Match',
		'If-None-Match',
	);

	/**
	 * WP core's own defaults, which the rest_allowed_cors_headers filter extends.
	 *
	 * @var string[]
	 */
	private const CORE_ALLOW_DEFAULTS = array( 'Authorization', 'X-WP-Nonce', 'Content-Disposition', 'Content-MD5', 'Content-Type' );

	/**
	 * A marked request gets the whole contract: WCPOS's own origin and the
	 * full expose list, and none of the preflight-only fields.
	 */
	public function test_marked_request_receives_the_cors_contract(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ), $server );

		// Assert.
		$this->assertSame( '*', $server->sent_headers['Access-Control-Allow-Origin'] ?? null );
		$this->assertSame(
			implode( ', ', Rest_Cors::EXPOSE_HEADERS ),
			$server->sent_headers['Access-Control-Expose-Headers'] ?? null
		);
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Methods', $server->sent_headers );
		$this->assertArrayNotHasKey( 'Access-Control-Max-Age', $server->sent_headers );
	}

	/**
	 * The rest of the site keeps WP core's CORS answer: an unmarked request to
	 * a route that is not ours is not ours to answer.
	 */
	public function test_unmarked_non_wcpos_request_receives_no_wcpos_cors_headers(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'GET', '/wp/v2/types' ), $server );

		// Assert.
		$this->assertSame( array(), $this->cors_fields( $server ) );
	}

	/**
	 * A preflight carries no marker (Fetch spec), so it is recognised by its
	 * route or by the headers it announces — the wc/v3 case is the preflight
	 * for a marked request to a route where the WCPOS API class is never
	 * constructed.
	 */
	public function test_options_preflight_publishes_the_full_preflight_contract(): void {
		foreach ( array( self::CURRENT_LANE_ROUTE, self::NON_WCPOS_ROUTE ) as $route ) {
			// Arrange.
			$server  = $this->new_spy_server();
			$request = new WP_REST_Request( 'OPTIONS', $route );
			if ( self::NON_WCPOS_ROUTE === $route ) {
				$request->set_header( 'Access-Control-Request-Headers', 'authorization, content-type, x-wcpos' );
			}

			// Act.
			Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

			// Assert.
			$this->assertSame( '*', $server->sent_headers['Access-Control-Allow-Origin'] ?? null, "{$route} preflight must answer with the WCPOS origin." );
			$this->assertSame( 'GET, POST, PUT, PATCH, DELETE', $server->sent_headers['Access-Control-Allow-Methods'] ?? null, "{$route} preflight is missing the allowed methods." );
			// Without Max-Age the Fetch spec caches a preflight for only 5s,
			// doubling every cross-origin request. 7200 is Chromium's cap.
			$this->assertSame( '7200', $server->sent_headers['Access-Control-Max-Age'] ?? null, "{$route} preflight is missing Max-Age." );
			$this->assertSame(
				array(
					'Authorization',
					'X-WP-Nonce',
					'Content-Disposition',
					'Content-MD5',
					'Content-Type',
					'X-HTTP-Method-Override',
					'X-WCPOS',
					'Idempotency-Key',
					'If-Match',
					'If-None-Match',
					'X-WCPOS-Idempotency-Key',
					'X-WCPOS-Store',
				),
				$this->allow_list( $server ),
				"{$route} preflight published the wrong allow-list."
			);
		}
	}

	/**
	 * THE BOUNDARY: a preflight for somebody else's route is not ours to
	 * answer. Priority 20 means a later writer WINS, so claiming every OPTIONS
	 * request (as the priority-5 handler harmlessly did) would replace core's
	 * origin-specific Access-Control-Allow-Origin with `*` on unrelated
	 * plugins' routes, and `*` invalidates the `Access-Control-Allow-
	 * Credentials: true` core sends beside it — breaking credentialed
	 * cross-origin requests on sites that merely have WCPOS installed.
	 *
	 * Core's answer is left untouched, and it still carries the WCPOS
	 * allow-list, because core builds that through rest_allowed_cors_headers.
	 */
	public function test_unmarked_preflight_to_a_non_wcpos_namespace_is_left_to_core(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ), $server );

		// Assert.
		$this->assertSame( array(), $this->cors_fields( $server ) );
		$this->assertContains(
			'X-WCPOS',
			apply_filters( 'rest_allowed_cors_headers', self::CORE_ALLOW_DEFAULTS, new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) ),
			"Core's own allow-list write must still carry the WCPOS headers."
		);
	}

	/**
	 * A preflight that announces somebody else's custom header is theirs, even
	 * when the name merely contains ours.
	 */
	public function test_preflight_announcing_a_foreign_header_is_not_claimed(): void {
		// Arrange.
		$server  = $this->new_spy_server();
		$request = new WP_REST_Request( 'OPTIONS', self::NON_WCPOS_ROUTE );
		$request->set_header( 'Access-Control-Request-Headers', 'authorization,x-vendor-x-wcpos-lookalike' );

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		// Assert.
		$this->assertSame( array(), $this->cors_fields( $server ) );
	}

	/**
	 * THE RATCHET (23bcdb47, 118a091f): every header the client actually sends
	 * has to survive BOTH ways the allow-list reaches the wire — the preflight
	 * response this class writes, and WP core's own write through the
	 * rest_allowed_cors_headers filter. A header in only one of them took the
	 * whole v2 lane down for cross-origin clients, twice.
	 */
	public function test_every_client_sent_header_survives_both_allow_list_publishers(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'OPTIONS', self::CURRENT_LANE_ROUTE ), $server );
		$preflight = $this->allow_list( $server );
		$filtered  = apply_filters( 'rest_allowed_cors_headers', self::CORE_ALLOW_DEFAULTS, $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ) );

		// Assert.
		foreach ( self::CLIENT_SENT_HEADERS as $header ) {
			$this->assertContains( $header, $preflight, "Preflight allow-list is missing {$header}: every cross-origin request carrying it would fail CORS." );
			$this->assertContains( $header, $filtered, "rest_allowed_cors_headers is missing {$header}." );
		}
		$this->assertSame( count( $filtered ), count( array_unique( $filtered ) ) );
		$this->assertSame( $preflight, $filtered, 'The two publishers must produce the same allow-list.' );
	}

	/**
	 * One owner means one write per field. Run through the real filter chain,
	 * not the handler alone, so a second publisher reappearing is caught.
	 */
	public function test_each_cors_field_is_written_exactly_once(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		apply_filters( 'rest_pre_serve_request', false, new WP_REST_Response(), new WP_REST_Request( 'OPTIONS', self::CURRENT_LANE_ROUTE ), $server );

		// Assert.
		foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Expose-Headers', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Max-Age', 'Cache-Control', 'Vary' ) as $field ) {
			$this->assertSame( 1, $this->count_writes( $server, $field ), "{$field} must be written exactly once." );
		}
	}

	/**
	 * Priority 20 is load-bearing: WP core publishes an ORIGIN-SPECIFIC
	 * Access-Control-Allow-Origin at priority 10, and send_header() replaces,
	 * so only a later writer keeps the WCPOS origin on every lane.
	 */
	public function test_the_contract_is_registered_after_cores_cors_headers(): void {
		// Arrange / Act.
		$wcpos = has_filter( 'rest_pre_serve_request', array( Rest_Cors::class, 'rest_pre_serve_request' ) );
		$core  = has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		// Assert.
		$this->assertSame( 20, $wcpos );
		$this->assertIsInt( $core );
		$this->assertGreaterThan( $core, $wcpos, 'WCPOS must publish its CORS headers after core, or core overwrites the origin.' );
	}

	/**
	 * The shape neither former writer covered on its own: a marked request to
	 * a route outside the WCPOS namespaces (the POS reads wc/v3 too). It gets
	 * the CORS contract, but not the cache contract — that one is scoped to
	 * the WCPOS lanes.
	 */
	public function test_marked_request_to_a_non_wcpos_route_receives_cors_but_not_the_cache_contract(): void {
		// Arrange.
		$server = $this->new_spy_server();

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), $this->wp_rest_get_request( self::NON_WCPOS_ROUTE ), $server );

		// Assert.
		$this->assertSame( '*', $server->sent_headers['Access-Control-Allow-Origin'] ?? null );
		$this->assertSame(
			implode( ', ', Rest_Cors::EXPOSE_HEADERS ),
			$server->sent_headers['Access-Control-Expose-Headers'] ?? null
		);
		$this->assertArrayNotHasKey( 'Cache-Control', $server->sent_headers );
		$this->assertArrayNotHasKey( 'Vary', $server->sent_headers );
	}

	/**
	 * Some proxies and WAFs strip unrecognised request headers, which takes the
	 * X-WCPOS marker with them. The query-var fallback is the only thing that
	 * keeps those clients working, so it needs a test of its own — without one,
	 * the whole branch can be deleted and nothing goes red.
	 *
	 * @return void
	 */
	public function test_marker_stripped_request_is_claimed_via_the_query_var_fallback(): void {
		// Arrange.
		$server  = $this->new_spy_server();
		$request = new WP_REST_Request( 'GET', self::NON_WCPOS_ROUTE );
		$request->set_query_params( array( 'wcpos' => '1' ) );

		// Act.
		Rest_Cors::rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		// Assert.
		$this->assertSame( '*', $server->sent_headers['Access-Control-Allow-Origin'] ?? null );
	}

	/**
	 * A REST server that records sent headers instead of emitting them.
	 *
	 * `sent_headers` models header()'s replace semantics (last writer wins);
	 * `writes` keeps every call, so "written exactly once" is a real
	 * assertion rather than an artefact of overwriting the same key.
	 *
	 * @return WP_REST_Server
	 */
	private function new_spy_server(): WP_REST_Server {
		return new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public array $writes = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
				$this->writes[]             = $key;
			}
		};
	}

	/**
	 * Every Access-Control field the spy server was asked to send.
	 *
	 * @param WP_REST_Server $server Spy server.
	 *
	 * @return string[]
	 */
	private function cors_fields( WP_REST_Server $server ): array {
		return array_values(
			array_filter(
				array_keys( $server->sent_headers ),
				static function ( string $header ): bool {
					return 0 === stripos( $header, 'Access-Control-' );
				}
			)
		);
	}

	/**
	 * The allow-list as the client receives it.
	 *
	 * @param WP_REST_Server $server Spy server.
	 *
	 * @return string[]
	 */
	private function allow_list( WP_REST_Server $server ): array {
		return array_map( 'trim', explode( ',', $server->sent_headers['Access-Control-Allow-Headers'] ?? '' ) );
	}

	/**
	 * Count writes of a header field, case-insensitively.
	 *
	 * @param WP_REST_Server $server Spy server.
	 * @param string         $name   Header field name.
	 *
	 * @return int
	 */
	private function count_writes( WP_REST_Server $server, string $name ): int {
		return count(
			array_filter(
				$server->writes,
				static function ( string $header ) use ( $name ): bool {
					return 0 === strcasecmp( $name, $header );
				}
			)
		);
	}
}
