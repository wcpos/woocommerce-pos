<?php
/**
 * Opt-in WCPOS REST response envelope tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Sync\Response_Envelope;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Response envelope contract tests.
 *
 * @group sync
 */
class Test_Response_Envelope extends WCPOS_REST_Unit_Test_Case {
	/** Literal route required by the REST lane coverage gate. */
	private const CURRENT_LANE_ROUTE = '/wcpos/v2/products';

	/**
	 * Every registered WCPOS route follows the opt-in contract, except push.
	 */
	public function test_all_registered_wcpos_routes_follow_the_opt_in_contract(): void {
		// Arrange.
		$visited = array();

		foreach ( API::ROUTE_NAMESPACES as $namespace ) {
			$routes = $this->server->get_routes( $namespace );
			$this->assertNotEmpty( $routes, "No routes registered for {$namespace}." );

			foreach ( $routes as $route => $handlers ) {
				foreach ( $this->allowed_methods( $handlers ) as $method ) {
					$body     = array(
						'route' => $route,
						'method' => $method,
					);
					$headers  = array(
						'X-WP-Total'       => '18',
						'X-WP-TotalPages'  => '2',
						'X-WCPOS-Pressure' => 'low',
						'X-Server-Load'    => '[0.1,0.2,0.3]',
						'X-WCPOS-Memory-Peak' => '16777216',
					);
					$request  = $this->envelope_request( $method, $route );
					$response = new WP_REST_Response( $body, 200, $headers );

					// Act.
					Response_Envelope::filter_response( $response, null, $request );
					$data = $response->get_data();

					// Assert.
					if ( 0 === stripos( $route, '/wcpos/v2/push/' ) || 0 === strcasecmp( $route, '/wcpos/v2/ping' ) ) {
						// push/*: golden write-contract bodies. ping: the live
						// fast path (Ping::maybe_serve) exits before REST
						// filters exist, so the REST fallback stays identical
						// to it — ping body-mirrors its own metadata.
						$this->assertEquals( $body, $data, "{$method} {$route} must retain its unwrapped body." );
					} else {
						$this->assertEquals( array( 'data', '_wcpos' ), array_keys( $data ), "{$method} {$route} did not use the uniform envelope." );
						$this->assertEquals( $body, $data['data'], "{$method} {$route} changed the original body." );
						$this->assertEquals( 1, $data['_wcpos']['v'], "{$method} {$route} used the wrong envelope version." );
						$this->assertEquals( 18, $data['_wcpos']['total'], "{$method} {$route} did not mirror X-WP-Total." );
						$this->assertEquals( 2, $data['_wcpos']['total_pages'], "{$method} {$route} did not mirror X-WP-TotalPages." );
						$this->assertEquals( 1, $data['_wcpos']['page'], "{$method} {$route} did not identify the default page." );
						$this->assertEquals( 'low', $data['_wcpos']['pressure'], "{$method} {$route} did not mirror X-WCPOS-Pressure." );
						$this->assertEquals( array( 0.1, 0.2, 0.3 ), $data['_wcpos']['server_load'], "{$method} {$route} did not mirror X-Server-Load." );
						$this->assertEquals( 16777216, $data['_wcpos']['memory_peak_bytes'], "{$method} {$route} did not mirror X-WCPOS-Memory-Peak." );
					}

					$plain_response = new WP_REST_Response( $body, 200, $headers );
					$plain_request  = $this->wp_rest_get_request( $route );

					// Act: the legacy path does not request an envelope.
					Response_Envelope::filter_response( $plain_response, null, $plain_request );

					// Assert: the body remains byte-identical.
					$this->assertEquals( wp_json_encode( $body ), wp_json_encode( $plain_response->get_data() ), "{$method} {$route} changed without opt-in." );
					$visited[ $route ] = true;
				}
			}
		}

		$this->assertArrayHasKey( self::CURRENT_LANE_ROUTE, $visited, 'The route-table loop must cover the current-lane products route.' );
	}

	/**
	 * A real collection response mirrors controller pagination and telemetry headers.
	 */
	public function test_current_lane_dispatch_mirrors_response_headers(): void {
		// Arrange.
		$request = $this->envelope_request( 'GET', self::CURRENT_LANE_ROUTE );
		$request->set_param( 'page', 1 );

		// Act.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		// Assert.
		$this->assertEquals( (int) $headers['X-WP-Total'], $data['_wcpos']['total'] );
		$this->assertEquals( (int) $headers['X-WP-TotalPages'], $data['_wcpos']['total_pages'] );
		$this->assertEquals( json_decode( $headers['X-Server-Load'], true ), $data['_wcpos']['server_load'] );
		$this->assertEquals( $headers['X-WCPOS-Pressure'], $data['_wcpos']['pressure'] );
	}

	/**
	 * Push and error bodies remain byte-identical even when opted in.
	 */
	public function test_push_and_error_bodies_are_never_wrapped(): void {
		// Arrange.
		$push_body    = array(
			'ok' => true,
			'id' => 'remote-id',
		);
		$push_request = $this->envelope_request( 'POST', '/wcpos/v2/push/products' );
		$push         = new WP_REST_Response( $push_body, 200 );
		$error_body   = array(
			'code' => 'rest_forbidden',
			'message' => 'Forbidden',
		);
		$error        = new WP_REST_Response( $error_body, 403 );

		// Act.
		Response_Envelope::filter_response( $push, null, $push_request );
		Response_Envelope::filter_response( $error, null, $this->envelope_request( 'GET', self::CURRENT_LANE_ROUTE ) );

		// Assert.
		$this->assertEquals( $push_body, $push->get_data() );
		$this->assertEquals( $error_body, $error->get_data() );
	}

	/**
	 * Strong-validator bodies contain deterministic metadata only.
	 */
	public function test_etag_response_omits_volatile_fields_and_is_byte_deterministic(): void {
		// Arrange.
		$body    = array(
			'checkpoint' => array( 'head' => 9 ),
			'changes' => array(),
		);
		$headers = array(
			'ETag'                  => '"9:validator"',
			'X-WCPOS-Pressure'      => 'high',
			'X-Server-Load'         => '[0.1,0.2,0.3]',
			'X-WCPOS-Memory-Peak'  => '16777216',
		);
		$first   = new WP_REST_Response( $body, 200, $headers );
		$second  = new WP_REST_Response( $body, 200, $headers );

		// Act.
		Response_Envelope::filter_response( $first, null, $this->envelope_request( 'GET', '/wcpos/v2/changes/tick' ) );
		Response_Envelope::filter_response( $second, null, $this->envelope_request( 'GET', '/wcpos/v2/changes/tick' ) );
		$meta = $first->get_data()['_wcpos'];

		// Assert.
		$this->assertEquals( '"9:validator"', $meta['validator'] );
		$this->assertArrayNotHasKey( 'pressure', $meta );
		$this->assertArrayNotHasKey( 'server_load', $meta );
		$this->assertArrayNotHasKey( 'memory_peak_bytes', $meta );
		$this->assertEquals( wp_json_encode( $first->get_data() ), wp_json_encode( $second->get_data() ) );
	}

	/**
	 * A marked WCPOS request can opt a core WooCommerce route into the envelope.
	 */
	public function test_marked_non_wcpos_route_is_wrapped_but_unmarked_route_is_not(): void {
		// Arrange.
		$body           = array( array( 'id' => 1 ) );
		$marked_request = $this->envelope_request( 'GET', '/wc/v3/products' );
		$unmarked       = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$unmarked->set_param( '_wcpos_envelope', 1 );
		$marked_response   = new WP_REST_Response( $body );
		$unmarked_response = new WP_REST_Response( $body );

		// Act.
		Response_Envelope::filter_response( $marked_response, null, $marked_request );
		Response_Envelope::filter_response( $unmarked_response, null, $unmarked );

		// Assert.
		$this->assertEquals( $body, $marked_response->get_data()['data'] );
		$this->assertEquals( 1, $marked_response->get_data()['_wcpos']['v'] );
		$this->assertArrayNotHasKey( 'pressure', $marked_response->get_data()['_wcpos'] );
		$this->assertArrayNotHasKey( 'server_load', $marked_response->get_data()['_wcpos'] );
		$this->assertArrayNotHasKey( 'memory_peak_bytes', $marked_response->get_data()['_wcpos'] );
		$this->assertEquals( $body, $unmarked_response->get_data() );
	}

	/**
	 * Raw byte responses (receipt PDFs, printer payloads) are never wrapped:
	 * Raw_Response serves get_raw_body() through its own callback, so a
	 * wrapped JSON body would be a promise the client never receives.
	 */
	public function test_raw_byte_responses_are_never_wrapped(): void {
		// Arrange.
		$raw     = \WCPOS\WooCommercePOS\API\V1\Raw_Response::serve( '%PDF-1.7 bytes', 'application/pdf' );
		$before  = $raw->get_data();
		$request = $this->envelope_request( 'GET', '/wcpos/v1/receipts/42/pdf' );

		// Act.
		Response_Envelope::filter_response( $raw, null, $request );

		// Assert.
		$this->assertEquals( $before, $raw->get_data() );
		$this->assertEquals( '%PDF-1.7 bytes', $raw->get_raw_body() );
	}

	/**
	 * Response-level links serialize INSIDE data (byte-faithful to the
	 * unenveloped body) and are not re-appended as a third top-level key.
	 */
	public function test_response_links_serialize_inside_the_wrapped_data(): void {
		// Arrange.
		$response = new WP_REST_Response( array( 'id' => 42 ), 200 );
		$response->add_link( 'self', 'https://example.test/wp-json/wcpos/v2/products/42' );
		$request = $this->envelope_request( 'GET', self::CURRENT_LANE_ROUTE . '/42' );

		// Act.
		Response_Envelope::filter_response( $response, null, $request );
		$data   = $response->get_data();
		$served = rest_get_server()->response_to_data( $response, false );

		// Assert.
		$this->assertEquals( array( 'data', '_wcpos' ), array_keys( $served ), 'WP re-appended _links outside the envelope.' );
		$this->assertArrayHasKey( '_links', $data['data'], 'The original response links must ride inside data.' );
		$this->assertEquals( 42, $data['data']['id'] );
	}

	/**
	 * A header-stripping proxy (wcpos-infra#72 Tier 3) deletes X-WCPOS in
	 * transit, so the client publishes the marker as the `wcpos` query var too.
	 * The envelope must honour that carrier: the same proxy strips X-WP-Total
	 * from the response, so a non-namespace route that ignored the query var
	 * would lose the header AND its body fallback together, and no total could
	 * reach the client at all.
	 */
	public function test_query_var_marker_opts_a_core_route_in_when_the_header_is_stripped(): void {
		// Arrange: the marker survives ONLY as the query var, as it does behind a
		// header-stripping proxy.
		global $wp;
		$previous            = $wp->query_vars;
		$wp->query_vars['wcpos'] = '1';
		$body                = array( array( 'id' => 1 ) );
		$request             = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$request->set_param( '_wcpos_envelope', 1 );
		$response = new WP_REST_Response( $body );
		$response->header( 'X-WP-Total', '17' );

		// Act.
		try {
			Response_Envelope::filter_response( $response, null, $request );
		} finally {
			$wp->query_vars = $previous;
		}

		// Assert: wrapped, and the total the stripped header would have carried is
		// mirrored into the body.
		$this->assertEquals( $body, $response->get_data()['data'] );
		$this->assertEquals( 17, $response->get_data()['_wcpos']['total'] );
	}

	/**
	 * The envelope stays double opt-in: the marker alone never wraps a core
	 * route, `_wcpos_envelope=1` is still required.
	 */
	public function test_query_var_marker_without_the_envelope_param_is_not_wrapped(): void {
		// Arrange.
		global $wp;
		$previous                = $wp->query_vars;
		$wp->query_vars['wcpos'] = '1';
		$body                    = array( array( 'id' => 1 ) );
		$request                 = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$response                = new WP_REST_Response( $body );

		// Act.
		try {
			Response_Envelope::filter_response( $response, null, $request );
		} finally {
			$wp->query_vars = $previous;
		}

		// Assert.
		$this->assertEquals( $body, $response->get_data() );
	}

	/**
	 * Build an opted-in, WCPOS-marked request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 */
	private function envelope_request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_param( '_wcpos_envelope', 1 );

		return $request;
	}

	/**
	 * Collect the HTTP verbs a registered route responds to.
	 *
	 * @param array $handlers Route handlers as stored by the REST server.
	 *
	 * @return string[]
	 */
	private function allowed_methods( array $handlers ): array {
		$allowed = array();

		foreach ( $handlers as $handler ) {
			foreach ( array_keys( $handler['methods'] ) as $method ) {
				$allowed[ $method ] = true;
			}
		}

		return array_keys( $allowed );
	}
}
