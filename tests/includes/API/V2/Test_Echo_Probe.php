<?php
/**
 * Echo probe endpoint tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Cors;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests the public request-header echo probe.
 */
class Test_Echo_Probe extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Every documented header reports presence and byte length without leaking values.
	 */
	public function test_all_headers_report_presence_and_length_without_values(): void {
		$values = array(
			'authorization'           => 'Bearer secret-token-substring',
			'content-type'            => 'application/json',
			'x-wcpos'                 => '1',
			'idempotency-key'         => 'idem-123',
			'if-match'                => '"revision-7"',
			'if-none-match'           => '"revision-6"',
			'x-wcpos-idempotency-key' => 'wcpos-idem-456',
			'x-wcpos-store'           => 'store-9',
		);
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/echo' );

		foreach ( $values as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( 1, $data['v'] );
		foreach ( $values as $name => $value ) {
			$this->assertEquals(
				array(
					'received' => true,
					'length'   => strlen( $value ),
				),
				$data['headers'][ $name ]
			);
		}
		$this->assertStringNotContainsString( 'secret-token-substring', wp_json_encode( $data ) );
	}

	/**
	 * Missing headers report false and zero bytes.
	 */
	public function test_missing_headers_report_absent_and_zero_length(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/echo' ) );

		foreach ( $response->get_data()['headers'] as $header ) {
			$this->assertEquals(
				array(
					'received' => false,
					'length'   => 0,
				),
				$header
			);
		}
	}

	/**
	 * Apache CGI's REDIRECT_HTTP_AUTHORIZATION counts as header arrival.
	 */
	public function test_redirect_http_authorization_reports_received(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirected-token';

		try {
			$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/echo' ) )->get_data();
		} finally {
			unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		$this->assertEquals(
			array(
				'received' => true,
				'length'   => strlen( 'Bearer redirected-token' ),
			),
			$data['headers']['authorization']
		);
		$this->assertStringNotContainsString( 'redirected-token', wp_json_encode( $data ) );
	}

	/**
	 * Non-empty fallback query parameters report their presence.
	 */
	public function test_non_empty_params_report_present(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/echo' );
		$request->set_query_params(
			array(
				'authorization' => 'Bearer query-token',
				'wcpos'         => '1',
				'store_id'      => '9',
			)
		);

		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEquals(
			array(
				'authorization' => true,
				'wcpos'         => true,
				'store_id'      => true,
			),
			$data['params']
		);
	}

	/**
	 * Missing fallback query parameters report false.
	 */
	public function test_missing_params_report_absent(): void {
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/echo' ) )->get_data();

		$this->assertEquals(
			array(
				'authorization' => false,
				'wcpos'         => false,
				'store_id'      => false,
			),
			$data['params']
		);
	}

	/**
	 * Anonymous requests can reach the probe through the central WCPOS permission gate.
	 */
	public function test_route_is_public(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/echo' ) );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * The response header set stays aligned with the CORS allow-list.
	 */
	public function test_header_keys_match_cors_headers_and_baselines(): void {
		$expected = array_map(
			'strtolower',
			array_merge( array( 'Authorization', 'Content-Type', 'X-WCPOS' ), Cors::headers() )
		);
		$data     = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/echo' ) )->get_data();

		$this->assertEqualsCanonicalizing( $expected, array_keys( $data['headers'] ) );
	}
}
