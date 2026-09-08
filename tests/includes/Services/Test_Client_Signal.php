<?php
/**
 * Client protocol signal tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Client_Signal;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests client protocol signal parsing.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Client_Signal
 */
class Test_Client_Signal extends WP_UnitTestCase {
	/** Headers take precedence over fallback query parameters. */
	public function test_read_prefers_headers_over_query_parameters(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_query_params(
			array(
				'wcpos_protocol' => '1',
				'wcpos_client'   => 'web/1.0.0',
			)
		);
		$request->set_header( 'X-WCPOS-Protocol', '2' );
		$request->set_header( 'X-WCPOS-Client', 'electron/1.11.0' );

		$this->assertSame(
			array(
				'protocol'    => '2',
				'platform'    => 'electron',
				'app_version' => '1.11.0',
				'channel'     => 'header',
			),
			Client_Signal::read( $request )
		);
	}

	/** Query parameters provide the signal when headers are absent. */
	public function test_read_uses_query_parameters_when_headers_are_absent(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_query_params(
			array(
				'wcpos_protocol' => '2',
				'wcpos_client'   => 'web/1.11.0',
			)
		);

		$this->assertSame(
			array(
				'protocol'    => '2',
				'platform'    => 'web',
				'app_version' => '1.11.0',
				'channel'     => 'query',
			),
			Client_Signal::read( $request )
		);
	}

	/** Missing signal fields retain the telemetry row's none channel. */
	public function test_read_reports_none_when_signal_is_absent(): void {
		$this->assertSame(
			array(
				'protocol'    => null,
				'platform'    => null,
				'app_version' => null,
				'channel'     => 'none',
			),
			Client_Signal::read( new WP_REST_Request( 'GET', '/wcpos/v2/products' ) )
		);
	}

	/** A client value without a slash is retained as a platform-only signal. */
	public function test_read_treats_malformed_client_as_platform_only(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_query_params( array( 'wcpos_client' => 'Not A Client!' ) );

		$this->assertSame(
			array(
				'protocol'    => null,
				'platform'    => 'notaclient',
				'app_version' => null,
				'channel'     => 'query',
			),
			Client_Signal::read( $request )
		);
	}

	/** Values are strictly sanitized and capped before capture. */
	public function test_read_sanitizes_and_caps_signal_values(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_header( 'X-WCPOS-Protocol', 'v12.34-567890' );
		$request->set_header( 'X-WCPOS-Client', 'PLATFORM!@#_123456789012345678901234567890/V!1.2.3-BETA_12345678901234567890' );

		$this->assertSame(
			array(
				'protocol'    => '12345678',
				'platform'    => 'platform_12345678901234567890123',
				'app_version' => 'v1.2.3-beta_12345678901234567890',
				'channel'     => 'header',
			),
			Client_Signal::read( $request )
		);
	}
}
