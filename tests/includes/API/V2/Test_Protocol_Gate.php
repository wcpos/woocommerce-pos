<?php
/**
 * Protocol gate integration tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests the wcpos/v2 minimum client protocol gate (free#1868, batch free#1752).
 *
 * The shared request helpers model a CURRENT client (marker + protocol 2), so
 * every "no signal" case below strips the protocol header explicitly.
 */
class Test_Protocol_Gate extends WCPOS_REST_Unit_Test_Case {
	/** Missing protocol signals receive the update-required envelope. */
	public function test_gate_refuses_sync_request_without_protocol_signal_with_426_envelope(): void {
		// Arrange / Act.
		$response = $this->dispatch_product_request();
		$data     = $response->get_data();
		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'wcpos_update_required', $data['code'] );
		$this->assertSame( 426, $data['data']['status'] );
		$this->assertSame( 2, $data['data']['min_protocol'] );
		$this->assertSame( 2, $data['data']['server_protocol'] );
		$this->assertSame( \WCPOS\WooCommercePOS\VERSION, $data['data']['plugin_version'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/** The refusal is served bare even when the client asked for the response envelope: the client keys on the top-level code. */
	public function test_gate_refusal_is_not_wrapped_in_the_response_envelope(): void {
		// Arrange.
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->remove_header( 'X-WCPOS-Protocol' );
		$request->set_query_params( array( '_wcpos_envelope' => '1' ) );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		$response = rest_ensure_response( apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ) );
		$data     = $response->get_data();
		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'wcpos_update_required', $data['code'] );
		$this->assertArrayNotHasKey( '_wcpos', $data );
	}

	/** Protocol 2 supplied by the canonical header passes the gate. */
	public function test_gate_passes_protocol_2_via_header(): void {
		$response = $this->dispatch_product_request( '2' );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Protocol 2 supplied by the query twin passes the gate (header-stripping hosts). */
	public function test_gate_passes_protocol_2_via_query_twin(): void {
		$response = $this->dispatch_product_request( '2', true );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Protocols newer than the server minimum pass the gate. */
	public function test_gate_passes_protocol_greater_than_2(): void {
		$response = $this->dispatch_product_request( '3' );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Protocol 1 is below the server minimum. */
	public function test_gate_refuses_protocol_1(): void {
		$response = $this->dispatch_product_request( '1' );
		$this->assertSame( 426, $response->get_status() );
	}

	/** The header protocol wins when a newer query-twin protocol is also present. */
	public function test_gate_prefers_stale_header_over_current_query_twin(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_header( 'X-WCPOS-Protocol', '1' );
		$request->set_query_params( array( 'wcpos_protocol' => '2' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 426, $response->get_status() );
	}

	/** The header protocol wins when an older query-twin protocol is also present. */
	public function test_gate_prefers_current_header_over_stale_query_twin(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_header( 'X-WCPOS-Protocol', '2' );
		$request->set_query_params( array( 'wcpos_protocol' => '1' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Carve-outs are served without a signal.
	 *
	 * @dataProvider carve_out_routes
	 *
	 * @param string $method Request method.
	 * @param string $route  Route.
	 */
	public function test_gate_carve_out_without_signal_is_not_refused( string $method, string $route ): void {
		// Arrange.
		$request = 'POST' === $method ? $this->wp_rest_post_request( $route ) : $this->wp_rest_get_request( $route );
		$request->remove_header( 'X-WCPOS-Protocol' );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		// Assert — the route must exist (an unregistered route would pass a "not refused"
		// check vacuously; some carve-outs legitimately answer 404 from their own callback,
		// so key on the REST server's code, not the status) and must not be refused.
		$code = \is_array( $data ) ? ( $data['code'] ?? null ) : null;
		$this->assertNotSame( 'rest_no_route', $code, $route . ' is not a registered route' );
		$this->assertNotSame( 426, $response->get_status(), $route );
		$this->assertNotSame( 'wcpos_update_required', $code, $route );
	}

	/**
	 * Every route the gate must never touch: the pre-login connect probes, the
	 * whole auth prefix, the health probe, the cloud-print relay callback
	 * (public) and the printer polls (printer token) — none of these callers
	 * carries a client protocol signal by construction.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function carve_out_routes(): array {
		return array(
			'echo'               => array( 'GET', '/wcpos/v2/echo' ),
			'ping'               => array( 'GET', '/wcpos/v2/ping' ),
			'site'               => array( 'GET', '/wcpos/v2/site' ),
			'status'             => array( 'GET', '/wcpos/v2/status' ),
			'auth refresh'       => array( 'POST', '/wcpos/v2/auth/refresh' ),
			'relay verification' => array( 'GET', '/wcpos/v2/print-jobs/relay-verification' ),
			'cloudprnt poll'     => array( 'POST', '/wcpos/v2/print-jobs/cloudprnt' ),
			'epson sdp poll'     => array( 'POST', '/wcpos/v2/print-jobs/epson-sdp' ),
		);
	}

	/** A request without the WCPOS marker is not a POS client and is never told to update. */
	public function test_gate_leaves_unmarked_requests_alone(): void {
		// Arrange.
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		// Assert.
		$this->assertNotSame( 426, $response->get_status() );
	}

	/**
	 * A stale client is told to update whatever its auth state — the gate runs
	 * before the permission gate, so an expired or missing token does not turn
	 * the boundary refusal into a 401 that sends the client into token recovery.
	 */
	public function test_gate_refuses_unauthenticated_marked_request_without_signal(): void {
		// Arrange.
		wp_set_current_user( 0 );
		// Act.
		$response = $this->dispatch_product_request();
		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'wcpos_update_required', $response->get_data()['code'] );
	}

	/** The query-twin marker on the request itself counts (header-stripping hosts). */
	public function test_gate_refuses_query_marked_request_without_signal(): void {
		// Arrange.
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_query_params( array( 'wcpos' => '1' ) );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		// Assert.
		$this->assertSame( 426, $response->get_status() );
	}

	/** Only the documented literal wcpos=1 marks a query-twin request. */
	public function test_gate_leaves_non_literal_query_marker_alone(): void {
		// Arrange.
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_query_params( array( 'wcpos' => 'false' ) );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		// Assert.
		$code = \is_array( $data ) ? ( $data['code'] ?? null ) : null;
		$this->assertNotSame( 'rest_no_route', $code );
		$this->assertNotSame( 426, $response->get_status() );
	}

	/**
	 * The ambient query var belongs to the served request, never to a nested dispatch.
	 *
	 * A query-twin client's outer request sets `$wp->query_vars['wcpos']`; a
	 * request dispatched while serving it (`rest_do_request`, `_embed`, batch)
	 * carries neither that marker nor the outer protocol signal on its own
	 * params. Reading the ambient marker would refuse such a dispatch on behalf
	 * of a perfectly current client.
	 */
	public function test_gate_ignores_ambient_query_marker_on_nested_dispatch(): void {
		// Arrange.
		$GLOBALS['wp']->query_vars['wcpos'] = 1;
		$request                            = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		// Act.
		try {
			$response = rest_get_server()->dispatch( $request );
		} finally {
			unset( $GLOBALS['wp']->query_vars['wcpos'] );
		}
		// Assert.
		$this->assertNotSame( 426, $response->get_status() );
	}

	/** The gate does not change the frozen wcpos/v1 surface. */
	public function test_gate_leaves_wcpos_v1_routes_alone(): void {
		// Arrange.
		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->remove_header( 'X-WCPOS-Protocol' );
		$v2_request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$v2_request->remove_header( 'X-WCPOS-Protocol' );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		$v2_response = rest_get_server()->dispatch( $v2_request );
		$data        = $response->get_data();
		// Assert.
		$code = \is_array( $data ) ? ( $data['code'] ?? null ) : null;
		$this->assertNotSame( 'rest_no_route', $code );
		$this->assertNotSame( 426, $response->get_status() );
		$this->assertSame( 426, $v2_response->get_status() );
	}

	/** OPTIONS preflights return before the protocol gate. */
	public function test_gate_leaves_options_preflight_alone(): void {
		// Arrange.
		$request = new WP_REST_Request( 'OPTIONS', '/wcpos/v2/products' );
		$request->set_header( 'X-WCPOS', '1' );
		// Act.
		$response = rest_get_server()->dispatch( $request );
		// Assert.
		$this->assertNotSame( 426, $response->get_status() );
	}

	/** Refused requests are recorded before the gate returns its response. */
	public function test_gate_refusal_still_records_client_signal_none(): void {
		// Arrange.
		$captures       = array();
		$intercept_http = static function ( $preempt, $parsed_args ) use ( &$captures ) {
			$captures[] = json_decode( $parsed_args['body'], true );
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
			);
		};
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );
		Analytics::reset_instance();
		update_user_meta( $this->user, '_woocommerce_pos_uuid', wp_generate_uuid4() );
		add_filter( 'pre_http_request', $intercept_http, 10, 2 );
		// Act — the marker rides the request object only (no SAPI header):
		// telemetry and gate must agree on the same predicate.
		try {
			$response = $this->dispatch_product_request();
		} finally {
			remove_filter( 'pre_http_request', $intercept_http, 10 );
			Analytics::reset_instance();
		}
		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertCount( 1, $captures );
		$this->assertSame( 'pos_client_signal', $captures[0]['event'] );
		$this->assertSame( 'none', $captures[0]['properties']['channel'] );
	}

	/**
	 * Dispatch a marked product request with an optional header or query protocol.
	 *
	 * @param string|null $protocol Protocol value, or null for a pre-signal client.
	 * @param bool        $query    Whether to send the value on the query twin instead of the header.
	 */
	private function dispatch_product_request( ?string $protocol = null, bool $query = false ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->remove_header( 'X-WCPOS-Protocol' );
		if ( null !== $protocol ) {
			if ( $query ) {
				$request->set_query_params( array( 'wcpos_protocol' => $protocol ) );
			} else {
				$request->set_header( 'X-WCPOS-Protocol', $protocol );
			}
		}

		return rest_get_server()->dispatch( $request );
	}
}
