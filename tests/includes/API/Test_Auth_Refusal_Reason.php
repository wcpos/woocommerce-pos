<?php
/**
 * Authentication refusal diagnostics tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WC_Logger_Interface;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Auth;
use WP_REST_Response;

/**
 * Test authentication reasons at the baseline permission gate.
 */
class Test_Auth_Refusal_Reason extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Captured log rows.
	 *
	 * @var array
	 */
	private $rows = array();

	/**
	 * Original logger.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private $original_logger;

	/**
	 * Original log level.
	 *
	 * @var string|null
	 */
	private $original_log_level;

	/**
	 * Capture rows written through the production logger.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_logger    = Logger::$logger;
		$this->original_log_level = Logger::$log_level;
		Logger::reset_dedup_state();
		Logger::$logger = $this->createMock( WC_Logger_Interface::class );
		Logger::$logger->method( 'log' )->willReturnCallback(
			function ( $level, $message, $context ) {
				$this->rows[] = compact( 'level', 'message', 'context' );
			}
		);
	}

	/**
	 * Restore logger and request state.
	 */
	public function tearDown(): void {
		Logger::reset_dedup_state();
		Logger::$logger    = $this->original_logger;
		Logger::$log_level = $this->original_log_level;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tearDown();
	}

	/**
	 * Routine expiry must expose its reason without generating log traffic.
	 */
	public function test_auth_refusal_expired_token_returns_reason_without_log(): void {
		// Arrange.
		$expire = static function ( $expires_at, $issued_at ) {
			return $issued_at - HOUR_IN_SECONDS;
		};
		add_filter( 'woocommerce_pos_jwt_access_token_expire', $expire, 10, 2 );
		try {
			$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->user ) );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire, 10 );
		}
		$this->assertIsString( $token );

		// Act.
		$response = $this->dispatch_order_request( $token );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $data['code'] );
		$this->assertSame( 'Authentication required.', $data['message'] );
		$this->assertSame( 401, $data['data']['status'] );
		$this->assertSame( 'woocommerce_pos_auth_token_expired', $data['data']['reason'] );
		$this->assertSame( array(), $this->rows );
	}

	/**
	 * A revoked session must expose its reason and write exactly one warning.
	 */
	public function test_auth_refusal_revoked_session_returns_reason_and_warning(): void {
		// Arrange.
		$auth   = Auth::instance();
		$tokens = $auth->generate_token_pair( get_user_by( 'id', $this->user ) );
		$this->assertNotWPError( $tokens );
		$refresh = $auth->validate_token( $tokens['refresh_token'], 'refresh' );
		$this->assertNotWPError( $refresh );
		$this->assertTrue( $auth->revoke_session_with_blacklist( $this->user, $refresh->jti ) );

		// Act.
		$response = $this->dispatch_order_request( $tokens['access_token'] );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $data['code'] );
		$this->assertSame( 'Authentication required.', $data['message'] );
		$this->assertSame( 401, $data['data']['status'] );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $data['data']['reason'] );
		$this->assertCount( 1, $this->rows );
		$this->assertSame( 'warning', $this->rows[0]['level'] );
		$this->assertStringContainsString(
			'POS request refused: woocommerce_pos_auth_session_revoked — Session has been revoked',
			$this->rows[0]['message']
		);
		// How the Logger encodes the context is the Logger's own concern, and it is
		// not the same on both trunks: print_r here, one-line JSON on next so the
		// log reader can parse a row per line. Both write it after the same marker,
		// so assert against the part after the marker — that proves each value
		// reached the diagnostic context and not merely the message text.
		$marker = strpos( $this->rows[0]['message'], ' | Context: ' );
		$this->assertNotFalse( $marker, 'The log row carries no context.' );
		$logged_context = substr( $this->rows[0]['message'], $marker );
		$this->assertStringContainsString( '/wcpos/v2/push/orders', $logged_context );
		$this->assertStringContainsString( 'POST', $logged_context );
		$this->assertStringContainsString( 'woocommerce_pos_auth_session_revoked', $logged_context );
		$this->assertStringNotContainsString( $tokens['access_token'], $this->rows[0]['message'] );
	}

	/**
	 * Anonymous traffic must not acquire a token failure reason or a log row.
	 */
	public function test_auth_refusal_no_token_returns_no_reason_or_log(): void {
		$response = $this->dispatch_order_request();
		$data     = $response->get_data();

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $data['code'] );
		$this->assertSame( array( 'status' => 401 ), $data['data'] );
		$this->assertSame( array(), $this->rows );
	}

	/**
	 * Authenticate from the request header before dispatch, as WordPress does.
	 *
	 * @param string $token Access token, or empty for an anonymous request.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_order_request( string $token = '' ): WP_REST_Response {
		$_SERVER['HTTP_AUTHORIZATION'] = '' === $token ? '' : 'Bearer ' . $token;
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_get_current_user();

		return $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/push/orders' ) );
	}
}
