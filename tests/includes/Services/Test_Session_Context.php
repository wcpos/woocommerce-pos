<?php

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Session_Context;
use WP_UnitTestCase;

/**
 * Tests for the Session_Context value object.
 *
 * Every test supplies request state as plain arrays. No test mutates a
 * superglobal.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Session_Context extends WP_UnitTestCase {
	/**
	 * A context built with no arguments is entirely empty.
	 */
	public function test_constructor_defaults_to_empty_strings(): void {
		$context = new Session_Context();

		$this->assertEquals( '', $context->get_user_agent() );
		$this->assertEquals( '', $context->get_platform() );
		$this->assertEquals( '', $context->get_version() );
		$this->assertEquals( '', $context->get_build() );
		$this->assertEquals( '', $context->get_ip() );
	}

	/**
	 * The constructor stores each field verbatim.
	 */
	public function test_constructor_stores_all_fields(): void {
		$context = new Session_Context( 'UA string', 'ios', '1.2.3', '456', '203.0.113.7' );

		$this->assertEquals( 'UA string', $context->get_user_agent() );
		$this->assertEquals( 'ios', $context->get_platform() );
		$this->assertEquals( '1.2.3', $context->get_version() );
		$this->assertEquals( '456', $context->get_build() );
		$this->assertEquals( '203.0.113.7', $context->get_ip() );
	}

	/**
	 * from_request() maps the user agent header and the request params onto the fields.
	 */
	public function test_from_request_maps_server_and_request_state(): void {
		$context = Session_Context::from_request(
			array(
				'HTTP_USER_AGENT' => 'WCPOS-iOS/1.4.0',
				'REMOTE_ADDR'     => '198.51.100.10',
			),
			array(
				'platform' => 'ios',
				'version'  => '1.4.0',
				'build'    => '99',
			)
		);

		$this->assertEquals( 'WCPOS-iOS/1.4.0', $context->get_user_agent() );
		$this->assertEquals( 'ios', $context->get_platform() );
		$this->assertEquals( '1.4.0', $context->get_version() );
		$this->assertEquals( '99', $context->get_build() );
		$this->assertEquals( '198.51.100.10', $context->get_ip() );
	}

	/**
	 * Missing request state yields empty strings rather than notices.
	 */
	public function test_from_request_with_empty_state_yields_empty_strings(): void {
		$context = Session_Context::from_request( array(), array() );

		$this->assertEquals( '', $context->get_user_agent() );
		$this->assertEquals( '', $context->get_platform() );
		$this->assertEquals( '', $context->get_version() );
		$this->assertEquals( '', $context->get_build() );
		$this->assertEquals( '', $context->get_ip() );
	}

	/**
	 * from_request() sanitizes the user agent it captures.
	 */
	public function test_from_request_sanitizes_user_agent(): void {
		$context = Session_Context::from_request(
			array( 'HTTP_USER_AGENT' => "Mozilla/5.0 <script>alert('x')</script>" ),
			array()
		);

		$this->assertStringNotContainsString( '<script>', $context->get_user_agent() );
	}

	/**
	 * from_request() reads platform metadata from the request params, which
	 * include POST bodies — the native app auth flow posts them.
	 */
	public function test_from_request_reads_platform_from_request_params(): void {
		$context = Session_Context::from_request( array(), array( 'platform' => 'android' ) );

		$this->assertEquals( 'android', $context->get_platform() );
		$this->assertEquals( '', $context->get_version() );
		$this->assertEquals( '', $context->get_build() );
	}

	/**
	 * Calling from_request() with no arguments reads the live request without error.
	 */
	public function test_from_request_without_arguments_returns_a_context(): void {
		$context = Session_Context::from_request();

		$this->assertInstanceOf( Session_Context::class, $context );
		$this->assertIsString( $context->get_user_agent() );
		$this->assertIsString( $context->get_ip() );
	}

	/**
	 * The Cloudflare header wins over every other source.
	 */
	public function test_client_ip_prefers_cloudflare_header(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_CF_CONNECTING_IP' => '203.0.113.1',
				'HTTP_X_FORWARDED_FOR'  => '203.0.113.2',
				'HTTP_X_REAL_IP'        => '203.0.113.3',
				'REMOTE_ADDR'           => '203.0.113.4',
			)
		);

		$this->assertEquals( '203.0.113.1', $ip );
	}

	/**
	 * X-Forwarded-For wins when the Cloudflare header is absent.
	 */
	public function test_client_ip_prefers_forwarded_for_over_real_ip_and_remote_addr(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_X_FORWARDED_FOR' => '203.0.113.2',
				'HTTP_X_REAL_IP'       => '203.0.113.3',
				'REMOTE_ADDR'          => '203.0.113.4',
			)
		);

		$this->assertEquals( '203.0.113.2', $ip );
	}

	/**
	 * X-Real-IP wins over the raw remote address.
	 */
	public function test_client_ip_prefers_real_ip_over_remote_addr(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_X_REAL_IP' => '203.0.113.3',
				'REMOTE_ADDR'    => '203.0.113.4',
			)
		);

		$this->assertEquals( '203.0.113.3', $ip );
	}

	/**
	 * The raw remote address is the last resort.
	 */
	public function test_client_ip_falls_back_to_remote_addr(): void {
		$ip = Session_Context::client_ip_from_request( array( 'REMOTE_ADDR' => '203.0.113.4' ) );

		$this->assertEquals( '203.0.113.4', $ip );
	}

	/**
	 * A comma-separated forwarded-for chain is reduced to the first entry.
	 */
	public function test_client_ip_takes_first_entry_of_forwarded_for_chain(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 198.51.100.1, 192.0.2.1',
				'REMOTE_ADDR'          => '203.0.113.4',
			)
		);

		$this->assertEquals( '203.0.113.5', $ip );
	}

	/**
	 * An empty header is skipped, not treated as the winning source.
	 */
	public function test_client_ip_skips_empty_headers(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_CF_CONNECTING_IP' => '',
				'HTTP_X_FORWARDED_FOR'  => '',
				'REMOTE_ADDR'           => '203.0.113.4',
			)
		);

		$this->assertEquals( '203.0.113.4', $ip );
	}

	/**
	 * The first present header wins even when its value is not a valid IP —
	 * the result is then discarded rather than falling through.
	 */
	public function test_client_ip_returns_empty_string_when_winning_header_is_invalid(): void {
		$ip = Session_Context::client_ip_from_request(
			array(
				'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
				'REMOTE_ADDR'           => '203.0.113.4',
			)
		);

		$this->assertEquals( '', $ip );
	}

	/**
	 * IPv6 addresses validate.
	 */
	public function test_client_ip_accepts_ipv6(): void {
		$ip = Session_Context::client_ip_from_request( array( 'REMOTE_ADDR' => '2001:db8::1' ) );

		$this->assertEquals( '2001:db8::1', $ip );
	}

	/**
	 * No headers at all yields an empty string.
	 */
	public function test_client_ip_with_no_headers_returns_empty_string(): void {
		$ip = Session_Context::client_ip_from_request( array() );

		$this->assertEquals( '', $ip );
	}
}
