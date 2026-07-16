<?php
/**
 * Sync write header mirror tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab tests retain compact contract-focused documentation.

use WCPOS\WooCommercePOS\API as WCPOS_API;
use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Header_Mirror;
use WCPOS\WooCommercePOS\Sync\Response_Telemetry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Header_Mirror
 */
class Test_Header_Mirror extends WP_UnitTestCase {
	private function request( array $headers ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	public function test_allow_cors_headers_appends_the_mirror_headers_without_duplicates(): void {
		$this->assertSame(
			array( 'Authorization', 'Idempotency-Key', 'If-Match' ),
			Header_Mirror::allow_cors_headers( array( 'Authorization' ) )
		);
		$this->assertSame(
			array( 'Idempotency-Key', 'If-Match' ),
			Header_Mirror::allow_cors_headers( array( 'Idempotency-Key', 'If-Match' ) )
		);
	}

	public function test_production_api_cors_allow_list_includes_mirror_headers(): void {
		$reflection = new \ReflectionClass( WCPOS_API::class );
		$api        = $reflection->newInstanceWithoutConstructor();
		$headers    = $api->rest_allowed_cors_headers( array( 'Authorization' ) );

		$this->assertContains( 'Idempotency-Key', $headers );
		$this->assertContains( 'If-Match', $headers );
		$this->assertSame( count( $headers ), count( array_unique( $headers ) ) );
	}

	public function test_production_api_exposes_response_telemetry_headers(): void {
		$reflection = new \ReflectionClass( WCPOS_API::class );
		$api        = $reflection->newInstanceWithoutConstructor();
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};
		$request = new WP_REST_Request( 'GET', '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'status' );

		$api->rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		$this->assertArrayHasKey( 'Access-Control-Expose-Headers', $server->sent_headers );
		$this->assertStringContainsString( 'Link', $server->sent_headers['Access-Control-Expose-Headers'] );
		$this->assertStringContainsString( 'X-Server-Load', $server->sent_headers['Access-Control-Expose-Headers'] );
		$this->assertStringContainsString( 'Server-Timing', $server->sent_headers['Access-Control-Expose-Headers'] );
	}

	public function test_options_preflight_allow_list_includes_mirror_headers_without_wcpos_header(): void {
		$reflection = new \ReflectionClass( Init::class );
		$init       = $reflection->newInstanceWithoutConstructor();
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};
		$request = new WP_REST_Request( 'OPTIONS', '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/products' );

		$init->rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		$this->assertStringContainsString( 'Idempotency-Key', $server->sent_headers['Access-Control-Allow-Headers'] );
		$this->assertStringContainsString( 'If-Match', $server->sent_headers['Access-Control-Allow-Headers'] );
		$this->assertArrayHasKey( 'Access-Control-Expose-Headers', $server->sent_headers );
		$this->assertStringContainsString( 'Link', $server->sent_headers['Access-Control-Expose-Headers'] );
		$this->assertStringContainsString( 'X-Server-Load', $server->sent_headers['Access-Control-Expose-Headers'] );
		$this->assertStringContainsString( 'Server-Timing', $server->sent_headers['Access-Control-Expose-Headers'] );
	}

	public function test_init_does_not_expose_telemetry_headers_on_non_preflight_requests(): void {
		$reflection = new \ReflectionClass( Init::class );
		$init       = $reflection->newInstanceWithoutConstructor();
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};
		$request = new WP_REST_Request( 'GET', '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'status' );

		$init->rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		$this->assertArrayNotHasKey( 'Access-Control-Expose-Headers', $server->sent_headers );
	}

	public function test_non_string_base_revision_is_treated_as_empty_not_cast(): void {
		$result = Header_Mirror::assert( $this->request( array( 'If-Match' => '"rev-1"' ) ), 'mid', array( 'not', 'a', 'string' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_header_body_mismatch', $result->get_error_code() );
		$this->assertNull( Header_Mirror::assert( $this->request( array() ), 'mid', array( 'x' ) ) );
	}

	public function test_idempotency_key_match_and_divergence(): void {
		$this->assertNull( Header_Mirror::assert( $this->request( array( 'Idempotency-Key' => 'mid' ) ), 'mid', null ) );
		$bad = Header_Mirror::assert( $this->request( array( 'Idempotency-Key' => 'other' ) ), 'mid', null );

		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 422, $bad->get_error_data()['status'] );
	}

	public function test_if_match_weak_tag_is_unquoted_before_compare(): void {
		$this->assertNull( Header_Mirror::assert( $this->request( array( 'If-Match' => 'W/"rev-1"' ) ), 'mid', 'rev-1' ) );
	}
}
