<?php
/**
 * Sync CORS allow-list tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact contract-focused documentation.

use WCPOS\WooCommercePOS\API as WCPOS_API;
use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Cors;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Cors
 */
class Test_Cors extends WP_UnitTestCase {
	/**
	 * Every request header the v2 sync engine sends, as literal wire strings.
	 *
	 * Deliberately NOT built from the server-side constants: this is the wire
	 * contract with the client (engine-fetcher / recordPushAdapter /
	 * change-signal-source in the monorepo), and a server-side rename must
	 * fail here, not silently follow. A header missing from either CORS
	 * allow-list writer fails every cross-origin engine request at preflight.
	 *
	 * @var string[]
	 */
	private const ENGINE_SENT_HEADERS = array(
		'Authorization',
		'Content-Type',
		'X-WCPOS',
		'X-WCPOS-Store',
		'Idempotency-Key',
		'If-Match',
		'If-None-Match',
	);

	public function test_allow_headers_appends_the_sync_lane_headers_without_duplicates(): void {
		$this->assertSame(
			array( 'Authorization', 'Idempotency-Key', 'If-Match', 'If-None-Match', 'X-WCPOS-Store' ),
			Cors::allow_headers( array( 'Authorization' ) )
		);
		$this->assertSame(
			array( 'X-WCPOS-Store', 'Idempotency-Key', 'If-Match', 'If-None-Match' ),
			Cors::allow_headers( array( 'X-WCPOS-Store' ) )
		);
	}

	public function test_options_preflight_allow_list_carries_every_engine_sent_header(): void {
		$reflection = new \ReflectionClass( Init::class );
		$init       = $reflection->newInstanceWithoutConstructor();
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};
		$request = new WP_REST_Request( 'OPTIONS', '/' . Api::ROUTE_NAMESPACE . '/taxes' );

		$init->rest_pre_serve_request( false, new WP_REST_Response(), $request, $server );

		$allowed = array_map( 'trim', explode( ',', $server->sent_headers['Access-Control-Allow-Headers'] ) );
		foreach ( self::ENGINE_SENT_HEADERS as $header ) {
			$this->assertContains( $header, $allowed, "Preflight allow-list is missing {$header}: every cross-origin engine request would fail CORS." );
		}
	}

	public function test_core_filter_allow_list_carries_every_engine_sent_header(): void {
		$reflection = new \ReflectionClass( WCPOS_API::class );
		$api        = $reflection->newInstanceWithoutConstructor();

		// WP core's defaults, which the rest_allowed_cors_headers filter extends.
		$core_defaults = array( 'Authorization', 'X-WP-Nonce', 'Content-Disposition', 'Content-MD5', 'Content-Type' );
		$allowed       = $api->rest_allowed_cors_headers( $core_defaults );

		foreach ( self::ENGINE_SENT_HEADERS as $header ) {
			$this->assertContains( $header, $allowed, "rest_allowed_cors_headers is missing {$header}." );
		}
		$this->assertSame( count( $allowed ), count( array_unique( $allowed ) ) );
	}
}
