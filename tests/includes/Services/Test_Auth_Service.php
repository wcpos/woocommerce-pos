<?php

namespace WCPOS\WooCommercePOS\Tests\Services;

use ReflectionMethod;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Session_Context;
use WP_Error;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_Service extends WP_UnitTestCase {
	/**
	 * A desktop Chrome user agent, used by the session-context tests.
	 */
	private const CHROME_DESKTOP_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	private $auth_service;
	private $test_user;

	public function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		$this->auth_service = Auth::instance();

		// Create a test user
		$this->test_user = $this->factory->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		parent::tearDown();
		unset( $this->auth_service );
		
		// Clean up test user
		if ( $this->test_user ) {
			wp_delete_user( $this->test_user->ID );
		}
	}

	/**
	 * Authenticate_request returns the user ID for a valid standard Authorization header.
	 */
	public function test_authenticate_request_accepts_valid_http_authorization_header(): void {
		$token                         = $this->auth_service->generate_access_token( $this->test_user );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

		$this->assertSame( $this->test_user->ID, $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request returns a WP_Error for an invalid token.
	 */
	public function test_authenticate_request_returns_error_for_invalid_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid.token.here';

		$this->assertWPError( $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request returns false when no authorization credential exists.
	 */
	public function test_authenticate_request_returns_false_without_authorization(): void {
		$this->assertFalse( $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request reads the redirected Authorization header.
	 */
	public function test_authenticate_request_accepts_redirect_http_authorization_header(): void {
		$token                                  = $this->auth_service->generate_access_token( $this->test_user );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

		$this->assertSame( $this->test_user->ID, $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request reads the authorization query parameter.
	 */
	public function test_authenticate_request_accepts_bearer_authorization_query_parameter(): void {
		$token                 = $this->auth_service->generate_access_token( $this->test_user );
		$_GET['authorization'] = 'Bearer ' . $token;

		$this->assertEquals( $this->test_user->ID, $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request accepts a bare JWT in the authorization query parameter.
	 */
	public function test_authenticate_request_accepts_bare_jwt_authorization_query_parameter(): void {
		$token                 = $this->auth_service->generate_access_token( $this->test_user );
		$_GET['authorization'] = $token;

		$this->assertEquals( $this->test_user->ID, $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request ignores Basic credentials for other authentication plugins.
	 */
	public function test_authenticate_request_returns_false_for_basic_authorization_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

		$this->assertFalse( $this->auth_service->authenticate_request() );
	}

	/**
	 * Authenticate_request rejects a bare value that is not shaped like a JWT.
	 */
	public function test_authenticate_request_returns_false_for_bare_non_jwt_value(): void {
		$_GET['authorization'] = 'not-a-jwt';

		$this->assertFalse( $this->auth_service->authenticate_request() );
	}

	/**
	 * Authorization values and their expected extracted tokens.
	 *
	 * @return array<string, array{mixed, null|string}>
	 */
	public function extract_token_provider(): array {
		return array(
			'bare JWT'     => array( 'header.payload.signature_-123', 'header.payload.signature_-123' ),
			'Bearer JWT'   => array( 'Bearer header.payload.signature', 'header.payload.signature' ),
			'empty Bearer' => array( 'Bearer ', null ),
			'double-space Bearer' => array( 'Bearer  header.payload.signature', 'header.payload.signature' ),
			'tab Bearer' => array( "Bearer\theader.payload.signature", 'header.payload.signature' ),
			'Basic auth'   => array( 'Basic dXNlcjpwYXNz', null ),
			'empty string' => array( '', null ),
			'non-string'   => array( array( 'header.payload.signature' ), null ),
		);
	}

	/**
	 * Extract_token accepts only Bearer credentials and bare JWT-shaped values.
	 *
	 * @dataProvider extract_token_provider
	 *
	 * @param mixed       $auth_value Authorization value.
	 * @param null|string $expected   Expected token.
	 */
	public function test_extract_token_returns_expected_token( $auth_value, ?string $expected ): void {
		$this->assertEquals( $expected, $this->auth_service->extract_token( $auth_value ) );
	}

	/**
	 * Test secret key generation.
	 */
	public function test_get_secret_key(): void {
		$secret_key = $this->auth_service->get_secret_key();
		$this->assertNotEmpty( $secret_key );
		$this->assertIsString( $secret_key );
		$this->assertEquals( 64, \strlen( $secret_key ) );
	}

	/**
	 * Test refresh secret key generation.
	 */
	public function test_get_refresh_secret_key(): void {
		$secret_key = $this->auth_service->get_refresh_secret_key();
		$this->assertNotEmpty( $secret_key );
		$this->assertIsString( $secret_key );
		$this->assertEquals( 64, \strlen( $secret_key ) );
	}

	/**
	 * Test access token generation.
	 */
	public function test_generate_access_token(): void {
		$token = $this->auth_service->generate_access_token( $this->test_user );
		$this->assertNotEmpty( $token );
		$this->assertIsString( $token );
		$this->assertNotInstanceOf( WP_Error::class, $token );
	}

	/**
	 * Test refresh token generation.
	 */
	public function test_generate_refresh_token(): void {
		$token = $this->auth_service->generate_refresh_token( $this->test_user );
		$this->assertNotEmpty( $token );
		$this->assertIsString( $token );
		$this->assertNotInstanceOf( WP_Error::class, $token );
	}

	/**
	 * Test token pair generation.
	 */
	public function test_generate_token_pair(): void {
		$tokens = $this->auth_service->generate_token_pair( $this->test_user );
		$this->assertIsArray( $tokens );
		$this->assertArrayHasKey( 'access_token', $tokens );
		$this->assertArrayHasKey( 'refresh_token', $tokens );
		$this->assertArrayHasKey( 'token_type', $tokens );
		$this->assertArrayHasKey( 'expires_at', $tokens );
		$this->assertEquals( 'Bearer', $tokens['token_type'] );
	}

	/**
	 * Test token pair expiry metadata comes from the issued access token.
	 */
	public function test_generate_token_pair_expires_at_matches_access_token_exp_claim(): void {
		$filter_calls         = 0;
		$access_expiry_filter = function ( $expire, $issued_at ) use ( &$filter_calls ) {
			++$filter_calls;

			return $issued_at + ( 300 * $filter_calls );
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $access_expiry_filter, 10, 2 );

		try {
			$tokens = $this->auth_service->generate_token_pair( $this->test_user );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $access_expiry_filter, 10 );
		}

		$this->assertIsArray( $tokens );

		$access_decoded = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertEquals( (int) $access_decoded->exp, $tokens['expires_at'] );
	}

	/**
	 * Test access token validation.
	 */
	public function test_validate_access_token(): void {
		$token    = $this->auth_service->generate_access_token( $this->test_user );
		$decoded  = $this->auth_service->validate_token( $token, 'access' );
		
		$this->assertNotInstanceOf( WP_Error::class, $decoded );
		$this->assertIsObject( $decoded );
		$this->assertEquals( $this->test_user->ID, $decoded->data->user->id );
		$this->assertEquals( 'access', $decoded->type );
	}

	/**
	 * Test refresh token validation.
	 */
	public function test_validate_refresh_token(): void {
		$token    = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded  = $this->auth_service->validate_token( $token, 'refresh' );
		
		$this->assertNotInstanceOf( WP_Error::class, $decoded );
		$this->assertIsObject( $decoded );
		$this->assertEquals( $this->test_user->ID, $decoded->data->user->id );
		$this->assertEquals( 'refresh', $decoded->type );
		$this->assertObjectHasProperty( 'jti', $decoded );
	}

	/**
	 * Test session tracking.
	 */
	public function test_session_tracking(): void {
		// Generate refresh token (which creates a session)
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Get sessions for user
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		
		$this->assertIsArray( $sessions );
		$this->assertCount( 1, $sessions );
		
		$session = $sessions[0];
		$this->assertArrayHasKey( 'jti', $session );
		$this->assertArrayHasKey( 'created', $session );
		$this->assertArrayHasKey( 'last_active', $session );
		$this->assertArrayHasKey( 'expires', $session );
		$this->assertArrayHasKey( 'ip_address', $session );
		$this->assertArrayHasKey( 'user_agent', $session );
		$this->assertArrayHasKey( 'device_info', $session );
		
		// Verify JTI matches
		$this->assertEquals( $decoded->jti, $session['jti'] );
	}

	/**
	 * Test multiple sessions tracking.
	 */
	public function test_multiple_sessions(): void {
		// Generate multiple refresh tokens
		$token1 = $this->auth_service->generate_refresh_token( $this->test_user );
		$token2 = $this->auth_service->generate_refresh_token( $this->test_user );
		$token3 = $this->auth_service->generate_refresh_token( $this->test_user );

		// Get sessions for user
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		
		$this->assertIsArray( $sessions );
		$this->assertCount( 3, $sessions );
	}

	/**
	 * Test concurrent cashier sessions do not invalidate each other's tokens.
	 */
	public function test_two_cashiers_can_refresh_independent_token_pairs(): void {
		$cashier_one = $this->factory->user->create_and_get(
			array(
				'role' => 'cashier',
			)
		);
		$cashier_two = $this->factory->user->create_and_get(
			array(
				'role' => 'cashier',
			)
		);

		try {
			$this->assertTrue( user_can( $cashier_one, 'access_woocommerce_pos' ) );
			$this->assertTrue( user_can( $cashier_two, 'access_woocommerce_pos' ) );

			$cashier_one_tokens = $this->auth_service->generate_token_pair( $cashier_one );
			$cashier_two_tokens = $this->auth_service->generate_token_pair( $cashier_two );

			$this->assertIsArray( $cashier_one_tokens );
			$this->assertIsArray( $cashier_two_tokens );
			$this->assertNotEquals( $cashier_one_tokens['access_token'], $cashier_two_tokens['access_token'] );
			$this->assertNotEquals( $cashier_one_tokens['refresh_token'], $cashier_two_tokens['refresh_token'] );

			$cashier_one_access = $this->auth_service->validate_token( $cashier_one_tokens['access_token'], 'access' );
			$cashier_one_refresh = $this->auth_service->validate_token( $cashier_one_tokens['refresh_token'], 'refresh' );
			$cashier_two_access = $this->auth_service->validate_token( $cashier_two_tokens['access_token'], 'access' );
			$cashier_two_refresh = $this->auth_service->validate_token( $cashier_two_tokens['refresh_token'], 'refresh' );

			$this->assertNotInstanceOf( WP_Error::class, $cashier_one_access );
			$this->assertNotInstanceOf( WP_Error::class, $cashier_one_refresh );
			$this->assertNotInstanceOf( WP_Error::class, $cashier_two_access );
			$this->assertNotInstanceOf( WP_Error::class, $cashier_two_refresh );

			$this->assertEquals( $cashier_one->ID, $cashier_one_access->data->user->id );
			$this->assertEquals( $cashier_one->ID, $cashier_one_refresh->data->user->id );
			$this->assertEquals( $cashier_two->ID, $cashier_two_access->data->user->id );
			$this->assertEquals( $cashier_two->ID, $cashier_two_refresh->data->user->id );
			$this->assertNotEquals( $cashier_one_refresh->jti, $cashier_two_refresh->jti );
			$this->assertEquals( $cashier_one_refresh->jti, $cashier_one_access->refresh_jti );
			$this->assertEquals( $cashier_two_refresh->jti, $cashier_two_access->refresh_jti );

			$cashier_one_sessions = $this->auth_service->get_user_sessions( $cashier_one->ID );
			$cashier_two_sessions = $this->auth_service->get_user_sessions( $cashier_two->ID );

			$this->assertCount( 1, $cashier_one_sessions );
			$this->assertCount( 1, $cashier_two_sessions );
			$this->assertEquals( $cashier_one_refresh->jti, $cashier_one_sessions[0]['jti'] );
			$this->assertEquals( $cashier_two_refresh->jti, $cashier_two_sessions[0]['jti'] );

			$cashier_one_refreshed = $this->auth_service->refresh_access_token( $cashier_one_tokens['refresh_token'] );
			$this->assertIsArray( $cashier_one_refreshed );

			$cashier_two_still_valid = $this->auth_service->refresh_access_token( $cashier_two_tokens['refresh_token'] );
			$this->assertIsArray( $cashier_two_still_valid );

			$cashier_one_new_access = $this->auth_service->validate_token( $cashier_one_refreshed['access_token'], 'access' );
			$cashier_two_new_access = $this->auth_service->validate_token( $cashier_two_still_valid['access_token'], 'access' );

			$this->assertNotInstanceOf( WP_Error::class, $cashier_one_new_access );
			$this->assertNotInstanceOf( WP_Error::class, $cashier_two_new_access );
			$this->assertEquals( $cashier_one->ID, $cashier_one_new_access->data->user->id );
			$this->assertEquals( $cashier_two->ID, $cashier_two_new_access->data->user->id );
			$this->assertEquals( $cashier_one_refresh->jti, $cashier_one_new_access->refresh_jti );
			$this->assertEquals( $cashier_two_refresh->jti, $cashier_two_new_access->refresh_jti );

			$this->assertCount( 1, $this->auth_service->get_user_sessions( $cashier_one->ID ) );
			$this->assertCount( 1, $this->auth_service->get_user_sessions( $cashier_two->ID ) );

			$this->assertTrue( $this->auth_service->revoke_session( $cashier_one->ID, $cashier_one_refresh->jti ) );

			$cashier_one_revoked = $this->auth_service->refresh_access_token( $cashier_one_tokens['refresh_token'] );
			$cashier_two_after_revoke = $this->auth_service->refresh_access_token( $cashier_two_tokens['refresh_token'] );

			$this->assertInstanceOf( WP_Error::class, $cashier_one_revoked );
			$this->assertIsArray( $cashier_two_after_revoke );
		} finally {
			wp_delete_user( $cashier_one->ID );
			wp_delete_user( $cashier_two->ID );
		}
	}

	/**
	 * Test expired cashier tokens do not invalidate other cashier sessions.
	 */
	public function test_expired_cashier_tokens_do_not_invalidate_other_cashier_sessions(): void {
		$cashier_one = $this->factory->user->create_and_get(
			array(
				'role' => 'cashier',
			)
		);
		$cashier_two = $this->factory->user->create_and_get(
			array(
				'role' => 'cashier',
			)
		);
		$expire_refresh_token = null;
		$expire_access_token  = null;

		try {
			$cashier_two_tokens = $this->auth_service->generate_token_pair( $cashier_two );
			$this->assertIsArray( $cashier_two_tokens );

			$expire_refresh_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10, 2 );
			$cashier_one_expired_refresh_token = $this->auth_service->generate_refresh_token( $cashier_one );
			remove_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10 );

			$this->assertIsString( $cashier_one_expired_refresh_token );

			$cashier_one_refresh_result = $this->auth_service->refresh_access_token( $cashier_one_expired_refresh_token );
			$cashier_two_refresh_result = $this->auth_service->refresh_access_token( $cashier_two_tokens['refresh_token'] );

			$this->assertInstanceOf( WP_Error::class, $cashier_one_refresh_result );
			$this->assertIsArray( $cashier_two_refresh_result );

			$expire_access_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10, 2 );
			$cashier_one_expired_access_token = $this->auth_service->generate_access_token( $cashier_one );
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10 );

			$this->assertIsString( $cashier_one_expired_access_token );

			$cashier_one_access_result = $this->auth_service->validate_token( $cashier_one_expired_access_token, 'access' );
			$cashier_two_access_result = $this->auth_service->validate_token( $cashier_two_refresh_result['access_token'], 'access' );

			$this->assertInstanceOf( WP_Error::class, $cashier_one_access_result );
			$this->assertNotInstanceOf( WP_Error::class, $cashier_two_access_result );
			$this->assertEquals( $cashier_two->ID, $cashier_two_access_result->data->user->id );
		} finally {
			if ( null !== $expire_refresh_token ) {
				remove_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10 );
			}
			if ( null !== $expire_access_token ) {
				remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10 );
			}
			wp_delete_user( $cashier_one->ID );
			wp_delete_user( $cashier_two->ID );
		}
	}

	/**
	 * Test concurrent shop manager sessions do not invalidate each other's tokens.
	 */
	public function test_two_shop_managers_can_refresh_independent_token_pairs(): void {
		$manager_one = $this->factory->user->create_and_get(
			array(
				'role' => 'shop_manager',
			)
		);
		$manager_two = $this->factory->user->create_and_get(
			array(
				'role' => 'shop_manager',
			)
		);

		try {
			$this->assertTrue( user_can( $manager_one, 'access_woocommerce_pos' ) );
			$this->assertTrue( user_can( $manager_two, 'access_woocommerce_pos' ) );

			$manager_one_tokens = $this->auth_service->generate_token_pair( $manager_one );
			$manager_two_tokens = $this->auth_service->generate_token_pair( $manager_two );

			$this->assertIsArray( $manager_one_tokens );
			$this->assertIsArray( $manager_two_tokens );
			$this->assertNotEquals( $manager_one_tokens['access_token'], $manager_two_tokens['access_token'] );
			$this->assertNotEquals( $manager_one_tokens['refresh_token'], $manager_two_tokens['refresh_token'] );

			$manager_one_access  = $this->auth_service->validate_token( $manager_one_tokens['access_token'], 'access' );
			$manager_one_refresh = $this->auth_service->validate_token( $manager_one_tokens['refresh_token'], 'refresh' );
			$manager_two_access  = $this->auth_service->validate_token( $manager_two_tokens['access_token'], 'access' );
			$manager_two_refresh = $this->auth_service->validate_token( $manager_two_tokens['refresh_token'], 'refresh' );

			$this->assertNotInstanceOf( WP_Error::class, $manager_one_access );
			$this->assertNotInstanceOf( WP_Error::class, $manager_one_refresh );
			$this->assertNotInstanceOf( WP_Error::class, $manager_two_access );
			$this->assertNotInstanceOf( WP_Error::class, $manager_two_refresh );

			$this->assertEquals( $manager_one->ID, $manager_one_access->data->user->id );
			$this->assertEquals( $manager_one->ID, $manager_one_refresh->data->user->id );
			$this->assertEquals( $manager_two->ID, $manager_two_access->data->user->id );
			$this->assertEquals( $manager_two->ID, $manager_two_refresh->data->user->id );
			$this->assertNotEquals( $manager_one_refresh->jti, $manager_two_refresh->jti );
			$this->assertEquals( $manager_one_refresh->jti, $manager_one_access->refresh_jti );
			$this->assertEquals( $manager_two_refresh->jti, $manager_two_access->refresh_jti );

			$manager_one_sessions = $this->auth_service->get_user_sessions( $manager_one->ID );
			$manager_two_sessions = $this->auth_service->get_user_sessions( $manager_two->ID );

			$this->assertCount( 1, $manager_one_sessions );
			$this->assertCount( 1, $manager_two_sessions );
			$this->assertEquals( $manager_one_refresh->jti, $manager_one_sessions[0]['jti'] );
			$this->assertEquals( $manager_two_refresh->jti, $manager_two_sessions[0]['jti'] );

			$manager_one_refreshed = $this->auth_service->refresh_access_token( $manager_one_tokens['refresh_token'] );
			$this->assertIsArray( $manager_one_refreshed );

			$manager_two_still_valid = $this->auth_service->refresh_access_token( $manager_two_tokens['refresh_token'] );
			$this->assertIsArray( $manager_two_still_valid );

			$manager_one_new_access = $this->auth_service->validate_token( $manager_one_refreshed['access_token'], 'access' );
			$manager_two_new_access = $this->auth_service->validate_token( $manager_two_still_valid['access_token'], 'access' );

			$this->assertNotInstanceOf( WP_Error::class, $manager_one_new_access );
			$this->assertNotInstanceOf( WP_Error::class, $manager_two_new_access );
			$this->assertEquals( $manager_one->ID, $manager_one_new_access->data->user->id );
			$this->assertEquals( $manager_two->ID, $manager_two_new_access->data->user->id );
			$this->assertEquals( $manager_one_refresh->jti, $manager_one_new_access->refresh_jti );
			$this->assertEquals( $manager_two_refresh->jti, $manager_two_new_access->refresh_jti );

			$this->assertCount( 1, $this->auth_service->get_user_sessions( $manager_one->ID ) );
			$this->assertCount( 1, $this->auth_service->get_user_sessions( $manager_two->ID ) );

			$this->assertTrue( $this->auth_service->revoke_session( $manager_one->ID, $manager_one_refresh->jti ) );

			$manager_one_revoked      = $this->auth_service->refresh_access_token( $manager_one_tokens['refresh_token'] );
			$manager_two_after_revoke = $this->auth_service->refresh_access_token( $manager_two_tokens['refresh_token'] );

			$this->assertInstanceOf( WP_Error::class, $manager_one_revoked );
			$this->assertIsArray( $manager_two_after_revoke );
		} finally {
			wp_delete_user( $manager_one->ID );
			wp_delete_user( $manager_two->ID );
		}
	}

	/**
	 * Test expired shop manager tokens do not invalidate other shop manager sessions.
	 */
	public function test_expired_shop_manager_tokens_do_not_invalidate_other_shop_manager_sessions(): void {
		$manager_one = $this->factory->user->create_and_get(
			array(
				'role' => 'shop_manager',
			)
		);
		$manager_two = $this->factory->user->create_and_get(
			array(
				'role' => 'shop_manager',
			)
		);
		$expire_refresh_token = null;
		$expire_access_token  = null;

		try {
			$manager_two_tokens = $this->auth_service->generate_token_pair( $manager_two );
			$this->assertIsArray( $manager_two_tokens );

			$expire_refresh_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10, 2 );
			$manager_one_expired_refresh_token = $this->auth_service->generate_refresh_token( $manager_one );
			remove_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10 );

			$this->assertIsString( $manager_one_expired_refresh_token );

			$manager_one_refresh_result = $this->auth_service->refresh_access_token( $manager_one_expired_refresh_token );
			$manager_two_refresh_result = $this->auth_service->refresh_access_token( $manager_two_tokens['refresh_token'] );

			$this->assertInstanceOf( WP_Error::class, $manager_one_refresh_result );
			$this->assertIsArray( $manager_two_refresh_result );

			$expire_access_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10, 2 );
			$manager_one_expired_access_token = $this->auth_service->generate_access_token( $manager_one );
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10 );

			$this->assertIsString( $manager_one_expired_access_token );

			$manager_one_access_result = $this->auth_service->validate_token( $manager_one_expired_access_token, 'access' );
			$manager_two_access_result = $this->auth_service->validate_token( $manager_two_refresh_result['access_token'], 'access' );

			$this->assertInstanceOf( WP_Error::class, $manager_one_access_result );
			$this->assertNotInstanceOf( WP_Error::class, $manager_two_access_result );
			$this->assertEquals( $manager_two->ID, $manager_two_access_result->data->user->id );
		} finally {
			if ( null !== $expire_refresh_token ) {
				remove_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10 );
			}
			if ( null !== $expire_access_token ) {
				remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10 );
			}
			wp_delete_user( $manager_one->ID );
			wp_delete_user( $manager_two->ID );
		}
	}

	/**
	 * Test session revocation.
	 */
	public function test_revoke_session(): void {
		// Generate refresh token
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Verify session exists
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 1, $sessions );

		// Revoke session
		$result = $this->auth_service->revoke_session( $this->test_user->ID, $decoded->jti );
		$this->assertTrue( $result );

		// Verify session is removed
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test revoking all sessions except current.
	 */
	public function test_revoke_all_sessions_except_current(): void {
		// Generate multiple refresh tokens
		$token1   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded1 = $this->auth_service->validate_token( $token1, 'refresh' );
		
		$token2 = $this->auth_service->generate_refresh_token( $this->test_user );
		$token3 = $this->auth_service->generate_refresh_token( $this->test_user );

		// Verify all sessions exist
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 3, $sessions );

		// Revoke all except first session
		$result = $this->auth_service->revoke_all_sessions_except( $this->test_user->ID, $decoded1->jti );
		$this->assertTrue( $result );

		// Verify only first session remains
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 1, $sessions );
		$this->assertEquals( $decoded1->jti, $sessions[0]['jti'] );
	}

	/**
	 * Test revoking all sessions.
	 */
	public function test_revoke_all_sessions(): void {
		// Generate multiple refresh tokens
		$this->auth_service->generate_refresh_token( $this->test_user );
		$this->auth_service->generate_refresh_token( $this->test_user );
		$this->auth_service->generate_refresh_token( $this->test_user );

		// Verify all sessions exist
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 3, $sessions );

		// Revoke all sessions
		$result = $this->auth_service->revoke_all_refresh_tokens( $this->test_user->ID );
		$this->assertTrue( $result );

		// Verify no sessions remain
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test refresh access token.
	 */
	public function test_refresh_access_token(): void {
		// Generate refresh token
		$refresh_token = $this->auth_service->generate_refresh_token( $this->test_user );

		// Sleep briefly to ensure timestamp difference
		sleep( 1 );

		// Refresh access token
		$result = $this->auth_service->refresh_access_token( $refresh_token );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'token_type', $result );
		$this->assertArrayHasKey( 'expires_at', $result );

		// Verify last_active was updated
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 1, $sessions );
		$this->assertGreaterThan( $sessions[0]['created'], $sessions[0]['last_active'] );
	}

	/**
	 * Test refresh expiry metadata comes from the issued access token.
	 */
	public function test_refresh_access_token_expires_at_matches_access_token_exp_claim(): void {
		$refresh_token = $this->auth_service->generate_refresh_token( $this->test_user );

		$filter_calls         = 0;
		$access_expiry_filter = function ( $expire, $issued_at ) use ( &$filter_calls ) {
			++$filter_calls;

			return $issued_at + ( 300 * $filter_calls );
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $access_expiry_filter, 10, 2 );

		try {
			$result = $this->auth_service->refresh_access_token( $refresh_token );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $access_expiry_filter, 10 );
		}

		$this->assertIsArray( $result );

		$access_decoded = $this->auth_service->validate_token( $result['access_token'], 'access' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertEquals( (int) $access_decoded->exp, $result['expires_at'] );
	}

	/**
	 * Test permission checking for self.
	 */
	public function test_can_manage_own_sessions(): void {
		// Set current user
		wp_set_current_user( $this->test_user->ID );

		// User should be able to manage their own sessions
		$can_manage = $this->auth_service->can_manage_user_sessions( $this->test_user->ID );
		$this->assertTrue( $can_manage );
	}

	/**
	 * Test permission checking for admin.
	 */
	public function test_admin_can_manage_all_sessions(): void {
		// Create another user
		$other_user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		// Set admin as current user
		wp_set_current_user( $this->test_user->ID );

		// Admin should be able to manage other user's sessions
		$can_manage = $this->auth_service->can_manage_user_sessions( $other_user->ID );
		$this->assertTrue( $can_manage );

		// Cleanup
		wp_delete_user( $other_user->ID );
	}

	/**
	 * Test permission checking for non-admin.
	 */
	public function test_non_admin_cannot_manage_others_sessions(): void {
		// Create two regular users
		$user1 = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user2 = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		// Set user1 as current user
		wp_set_current_user( $user1->ID );

		// User1 should NOT be able to manage user2's sessions
		$can_manage = $this->auth_service->can_manage_user_sessions( $user2->ID );
		$this->assertFalse( $can_manage );

		// Cleanup
		wp_delete_user( $user1->ID );
		wp_delete_user( $user2->ID );
	}

	/**
	 * Test device info parsing.
	 */
	public function test_device_info_parsing(): void {
		// Save original user agent
		$original_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

		// Set a known user agent
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		// Generate token which should trigger device parsing
		$this->auth_service->generate_refresh_token( $this->test_user );
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );

		$this->assertCount( 1, $sessions );
		$device_info = $sessions[0]['device_info'];

		$this->assertArrayHasKey( 'device_type', $device_info );
		$this->assertArrayHasKey( 'browser', $device_info );
		$this->assertArrayHasKey( 'browser_version', $device_info );
		$this->assertArrayHasKey( 'os', $device_info );

		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( 'Chrome', $device_info['browser'] );
		$this->assertEquals( 'Windows', $device_info['os'] );

		// Restore original user agent
		if ( null === $original_user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $original_user_agent;
		}
	}

	/**
	 * Test access token contains JTI.
	 */
	public function test_access_token_contains_jti(): void {
		$token   = $this->auth_service->generate_access_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'access' );

		$this->assertNotInstanceOf( WP_Error::class, $decoded );
		$this->assertObjectHasProperty( 'jti', $decoded );
		$this->assertNotEmpty( $decoded->jti );
	}

	/**
	 * Test access token linked to refresh token.
	 */
	public function test_access_token_linked_to_refresh_token(): void {
		$tokens = $this->auth_service->generate_token_pair( $this->test_user );

		$this->assertIsArray( $tokens );
		$this->assertArrayHasKey( 'access_token', $tokens );
		$this->assertArrayHasKey( 'refresh_token', $tokens );

		// Decode both tokens
		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertNotInstanceOf( WP_Error::class, $refresh_decoded );

		// Access token should have refresh_jti linking to refresh token
		$this->assertObjectHasProperty( 'refresh_jti', $access_decoded );
		$this->assertEquals( $refresh_decoded->jti, $access_decoded->refresh_jti );
	}

	/**
	 * Test token blacklist (can blacklist any JTI - access token or session).
	 */
	public function test_token_blacklist(): void {
		// Generate access token
		$token   = $this->auth_service->generate_access_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'access' );

		$this->assertNotInstanceOf( WP_Error::class, $decoded );

		// Blacklist the token by its JTI
		$result = $this->auth_service->blacklist_token( $decoded->jti, 3600 );
		$this->assertTrue( $result );

		// Try to validate blacklisted token
		$validated = $this->auth_service->validate_token( $token, 'access' );
		$this->assertInstanceOf( WP_Error::class, $validated );
		$this->assertEquals( 'woocommerce_pos_auth_token_revoked', $validated->get_error_code() );
	}

	/**
	 * Test session revocation with blacklist.
	 *
	 * When a session is revoked, the refresh_jti is blacklisted, which
	 * invalidates ALL access tokens linked to that session.
	 */
	public function test_revoke_session_with_blacklist(): void {
		// Generate token pair
		$tokens = $this->auth_service->generate_token_pair( $this->test_user );

		// Decode to get JTIs
		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		// Access token should be valid and linked to refresh token
		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertEquals( $refresh_decoded->jti, $access_decoded->refresh_jti );

		// Revoke session with blacklist (blacklists the refresh_jti)
		$result = $this->auth_service->revoke_session_with_blacklist(
			$this->test_user->ID,
			$refresh_decoded->jti
		);
		$this->assertTrue( $result );

		// Refresh token should be revoked from user meta
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );

		// Access token should be invalid because its refresh_jti is blacklisted
		$validated = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$this->assertInstanceOf( WP_Error::class, $validated );
		$this->assertEquals( 'woocommerce_pos_auth_session_revoked', $validated->get_error_code() );
	}

	/**
	 * Test session blacklist outlives previously issued access tokens.
	 */
	public function test_revoke_session_blacklist_covers_previously_issued_access_token_expiry(): void {
		$long_access_expiry = function ( $expire, $issued_at ) {
			return $issued_at + 10;
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $long_access_expiry, 10, 2 );

		try {
			$tokens = $this->auth_service->generate_token_pair( $this->test_user );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $long_access_expiry, 10 );
		}

		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertNotInstanceOf( WP_Error::class, $refresh_decoded );

		$short_blacklist_expiry = function ( $expire, $issued_at ) {
			return $issued_at + 1;
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $short_blacklist_expiry, 10, 2 );

		try {
			$result = $this->auth_service->revoke_session_with_blacklist(
				$this->test_user->ID,
				$refresh_decoded->jti
			);

			sleep( 2 );

			$validated = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $short_blacklist_expiry, 10 );
		}

		$this->assertTrue( $result );
		$this->assertInstanceOf( WP_Error::class, $validated );
		$this->assertEquals( 'woocommerce_pos_auth_session_revoked', $validated->get_error_code() );
	}

	/**
	 * Test legacy sessions without recorded access expiry still get conservative blacklists.
	 */
	public function test_revoke_session_blacklist_covers_legacy_session_without_recorded_access_expiry(): void {
		$long_access_expiry = function ( $expire, $issued_at ) {
			return $issued_at + 10;
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $long_access_expiry, 10, 2 );

		try {
			$tokens = $this->auth_service->generate_token_pair( $this->test_user );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $long_access_expiry, 10 );
		}

		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertNotInstanceOf( WP_Error::class, $refresh_decoded );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );
		$this->assertIsArray( $refresh_tokens );
		$this->assertArrayHasKey( $refresh_decoded->jti, $refresh_tokens );

		unset( $refresh_tokens[ $refresh_decoded->jti ]['access_expires'] );
		update_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', $refresh_tokens );

		$short_blacklist_expiry = function ( $expire, $issued_at ) {
			return $issued_at + 1;
		};

		add_filter( 'woocommerce_pos_jwt_access_token_expire', $short_blacklist_expiry, 10, 2 );

		try {
			$result = $this->auth_service->revoke_session_with_blacklist(
				$this->test_user->ID,
				$refresh_decoded->jti
			);

			sleep( 2 );

			$validated = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		} finally {
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $short_blacklist_expiry, 10 );
		}

		$this->assertTrue( $result );
		$this->assertInstanceOf( WP_Error::class, $validated );
		$this->assertEquals( 'woocommerce_pos_auth_session_revoked', $validated->get_error_code() );
	}

	/**
	 * Test public update_session_activity method.
	 */
	public function test_public_update_session_activity(): void {
		// Generate refresh token
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Get initial session
		$sessions            = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$initial_last_active = $sessions[0]['last_active'];

		// Sleep briefly
		sleep( 1 );

		// Update activity using public method
		$result = $this->auth_service->update_session_activity( $this->test_user->ID, $decoded->jti );
		$this->assertTrue( $result );

		// Verify last_active was updated
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertGreaterThan( $initial_last_active, $sessions[0]['last_active'] );
	}

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: get_user_data returns expected structure.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::get_user_data
	 */
	public function test_direct_get_user_data(): void {
		$result = $this->auth_service->get_user_data( $this->test_user );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'username', $result );
		$this->assertArrayHasKey( 'email', $result );
		$this->assertArrayHasKey( 'display_name', $result );
		$this->assertArrayHasKey( 'first_name', $result );
		$this->assertArrayHasKey( 'last_name', $result );
		$this->assertArrayHasKey( 'roles', $result );
		$this->assertArrayHasKey( 'avatar_url', $result );

		$this->assertEquals( $this->test_user->ID, $result['id'] );
		$this->assertEquals( $this->test_user->user_login, $result['username'] );
		$this->assertSame( $this->test_user->roles, $result['roles'] );
	}

	/**
	 * Direct test: get_user_data with web frontend flag.
	 *
	 * NOTE: This test is skipped because the web frontend flag triggers cookie operations
	 * which fail in PHPUnit (headers already sent).
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::get_user_data
	 */
	public function test_direct_get_user_data_web_frontend(): void {
		$this->markTestSkipped( 'Web frontend flag triggers cookie operations that fail in PHPUnit' );
	}

	/**
	 * Direct test: get_redirect_data returns expected structure.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::get_redirect_data
	 */
	public function test_direct_get_redirect_data(): void {
		$result = $this->auth_service->get_redirect_data( $this->test_user );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'refresh_token', $result );
		$this->assertArrayHasKey( 'token_type', $result );
		$this->assertArrayHasKey( 'expires_at', $result );
		$this->assertArrayHasKey( 'uuid', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'display_name', $result );
	}

	/**
	 * Direct test: validate_token with invalid token.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::validate_token
	 */
	public function test_direct_validate_token_invalid(): void {
		$result = $this->auth_service->validate_token( 'invalid.token.here', 'access' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'woocommmerce_pos_auth_invalid_token', $result->get_error_code() );
	}

	/**
	 * Direct test: validate_token with empty token.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::validate_token
	 */
	public function test_direct_validate_token_empty(): void {
		$result = $this->auth_service->validate_token( '', 'access' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: validate_token type mismatch.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::validate_token
	 */
	public function test_direct_validate_token_type_mismatch(): void {
		// Generate access token but try to validate as refresh
		$token  = $this->auth_service->generate_access_token( $this->test_user );
		$result = $this->auth_service->validate_token( $token, 'refresh' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: generate_token (legacy method).
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::generate_token
	 */
	public function test_direct_generate_token_legacy(): void {
		$result = $this->auth_service->generate_token( $this->test_user );

		// Legacy generate_token now just returns access token string
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		// Verify it's a valid access token
		$decoded = $this->auth_service->validate_token( $result, 'access' );
		$this->assertNotInstanceOf( WP_Error::class, $decoded );
	}

	/**
	 * Direct test: refresh_access_token with invalid token.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::refresh_access_token
	 */
	public function test_direct_refresh_access_token_invalid(): void {
		$result = $this->auth_service->refresh_access_token( 'invalid.refresh.token' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: revoke_refresh_token.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::revoke_refresh_token
	 */
	public function test_direct_revoke_refresh_token(): void {
		// Generate refresh token
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Revoke it
		$result = $this->auth_service->revoke_refresh_token( $this->test_user->ID, $decoded->jti );

		$this->assertTrue( $result );

		// Verify it's gone
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Direct test: revoke_refresh_token with nonexistent JTI.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::revoke_refresh_token
	 */
	public function test_direct_revoke_refresh_token_nonexistent(): void {
		$result = $this->auth_service->revoke_refresh_token( $this->test_user->ID, 'nonexistent-jti' );

		// Should return false if no matching token found
		$this->assertFalse( $result );
	}

	/**
	 * Direct test: generate_access_token with refresh JTI.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::generate_access_token
	 */
	public function test_direct_generate_access_token_with_refresh_jti(): void {
		$refresh_jti = 'test-refresh-jti-' . wp_generate_uuid4();
		$token       = $this->auth_service->generate_access_token( $this->test_user, $refresh_jti );

		$this->assertIsString( $token );

		$decoded = $this->auth_service->validate_token( $token, 'access' );
		$this->assertNotInstanceOf( WP_Error::class, $decoded );
		$this->assertEquals( $refresh_jti, $decoded->refresh_jti );
	}

	/**
	 * Direct test: get_user_sessions for user with no sessions.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::get_user_sessions
	 */
	public function test_direct_get_user_sessions_empty(): void {
		// Create a new user with no sessions
		$new_user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$sessions = $this->auth_service->get_user_sessions( $new_user->ID );

		$this->assertIsArray( $sessions );
		$this->assertEmpty( $sessions );

		wp_delete_user( $new_user->ID );
	}

	/**
	 * Direct test: update_session_activity for nonexistent session.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Auth::update_session_activity
	 */
	public function test_direct_update_session_activity_nonexistent(): void {
		$result = $this->auth_service->update_session_activity( $this->test_user->ID, 'nonexistent-jti' );

		$this->assertFalse( $result );
	}

	/**
	 * An explicit ios platform sets the app type, forces tablet, and takes the
	 * declared version and build.
	 */
	public function test_store_session_with_ios_platform_overrides_device_info(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'ios', '1.4.0', '412' )
		);

		$this->assertEquals( 'ios_app', $device_info['app_type'] );
		$this->assertEquals( 'tablet', $device_info['device_type'] );
		$this->assertEquals( 'WooCommerce POS', $device_info['browser'] );
		$this->assertEquals( '1.4.0', $device_info['browser_version'] );
		$this->assertEquals( '412', $device_info['build'] );
	}

	/**
	 * An explicit android platform behaves like ios.
	 */
	public function test_store_session_with_android_platform_overrides_device_info(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'android', '2.0.1', '77' )
		);

		$this->assertEquals( 'android_app', $device_info['app_type'] );
		$this->assertEquals( 'tablet', $device_info['device_type'] );
		$this->assertEquals( 'WooCommerce POS', $device_info['browser'] );
		$this->assertEquals( '2.0.1', $device_info['browser_version'] );
		$this->assertEquals( '77', $device_info['build'] );
	}

	/**
	 * An explicit electron platform forces desktop.
	 */
	public function test_store_session_with_electron_platform_overrides_device_info(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'electron', '3.1.0', '5' )
		);

		$this->assertEquals( 'electron_app', $device_info['app_type'] );
		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( 'WooCommerce POS', $device_info['browser'] );
		$this->assertEquals( '3.1.0', $device_info['browser_version'] );
		$this->assertEquals( '5', $device_info['build'] );
	}

	/**
	 * An explicit web platform keeps the browser identity parsed from the user
	 * agent and leaves the device type alone.
	 */
	public function test_store_session_with_web_platform_keeps_parsed_browser(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'web', '', '' )
		);

		$this->assertEquals( 'web', $device_info['app_type'] );
		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( 'Chrome', $device_info['browser'] );
		$this->assertEquals( '120.0.0.0', $device_info['browser_version'] );
		$this->assertArrayNotHasKey( 'build', $device_info );
	}

	/**
	 * A platform declared without a version or build keeps the version parsed
	 * from the user agent and records no build.
	 */
	public function test_store_session_with_platform_only_keeps_parsed_version_and_no_build(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'ios' )
		);

		$this->assertEquals( 'ios_app', $device_info['app_type'] );
		$this->assertEquals( 'tablet', $device_info['device_type'] );
		$this->assertEquals( 'WooCommerce POS', $device_info['browser'] );
		$this->assertEquals( '120.0.0.0', $device_info['browser_version'] );
		$this->assertArrayNotHasKey( 'build', $device_info );
	}

	/**
	 * With no platform declared the device info comes purely from the user agent.
	 */
	public function test_store_session_without_platform_falls_back_to_user_agent(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT )
		);

		$this->assertEquals( 'web', $device_info['app_type'] );
		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( 'Chrome', $device_info['browser'] );
		$this->assertEquals( '120.0.0.0', $device_info['browser_version'] );
		$this->assertEquals( 'Windows', $device_info['os'] );
		$this->assertArrayNotHasKey( 'build', $device_info );
	}

	/**
	 * A platform value outside the allow list is ignored entirely.
	 */
	public function test_store_session_with_unknown_platform_is_ignored(): void {
		$device_info = $this->store_session_and_get_device_info(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, 'blackberry', '9.9.9', '1' )
		);

		$this->assertEquals( 'web', $device_info['app_type'] );
		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( 'Chrome', $device_info['browser'] );
		$this->assertEquals( '120.0.0.0', $device_info['browser_version'] );
		$this->assertArrayNotHasKey( 'build', $device_info );
	}

	/**
	 * The session records the user agent and IP carried by the context.
	 */
	public function test_store_session_records_context_user_agent_and_ip(): void {
		$session = $this->store_session_with_context(
			new Session_Context( self::CHROME_DESKTOP_USER_AGENT, '', '', '', '203.0.113.9' )
		);

		$this->assertEquals( self::CHROME_DESKTOP_USER_AGENT, $session['user_agent'] );
		$this->assertEquals( '203.0.113.9', $session['ip_address'] );
	}

	/**
	 * The Cloudflare header wins over X-Forwarded-For and the remote address all
	 * the way through to the stored session.
	 */
	public function test_store_session_ip_precedence_prefers_cloudflare_header(): void {
		$session = $this->store_session_with_context(
			Session_Context::from_request(
				array(
					'HTTP_USER_AGENT'       => self::CHROME_DESKTOP_USER_AGENT,
					'HTTP_CF_CONNECTING_IP' => '203.0.113.1',
					'HTTP_X_FORWARDED_FOR'  => '203.0.113.2',
					'REMOTE_ADDR'           => '203.0.113.4',
				),
				array()
			)
		);

		$this->assertEquals( '203.0.113.1', $session['ip_address'] );
	}

	/**
	 * Without the Cloudflare header, X-Forwarded-For wins over the remote address.
	 */
	public function test_store_session_ip_precedence_prefers_forwarded_for_over_remote_addr(): void {
		$session = $this->store_session_with_context(
			Session_Context::from_request(
				array(
					'HTTP_USER_AGENT'      => self::CHROME_DESKTOP_USER_AGENT,
					'HTTP_X_FORWARDED_FOR' => '203.0.113.2, 198.51.100.1',
					'REMOTE_ADDR'          => '203.0.113.4',
				),
				array()
			)
		);

		$this->assertEquals( '203.0.113.2', $session['ip_address'] );
	}

	/**
	 * With no proxy headers the remote address is used.
	 */
	public function test_store_session_ip_precedence_falls_back_to_remote_addr(): void {
		$session = $this->store_session_with_context(
			Session_Context::from_request(
				array(
					'HTTP_USER_AGENT' => self::CHROME_DESKTOP_USER_AGENT,
					'REMOTE_ADDR'     => '203.0.113.4',
				),
				array()
			)
		);

		$this->assertEquals( '203.0.113.4', $session['ip_address'] );
	}

	/**
	 * Platform metadata supplied as request params reaches the stored session.
	 */
	public function test_store_session_reads_platform_metadata_from_request_params(): void {
		$device_info = $this->store_session_and_get_device_info(
			Session_Context::from_request(
				array( 'HTTP_USER_AGENT' => self::CHROME_DESKTOP_USER_AGENT ),
				array(
					'platform' => 'electron',
					'version'  => '4.2.0',
					'build'    => '18',
				)
			)
		);

		$this->assertEquals( 'electron_app', $device_info['app_type'] );
		$this->assertEquals( 'desktop', $device_info['device_type'] );
		$this->assertEquals( '4.2.0', $device_info['browser_version'] );
		$this->assertEquals( '18', $device_info['build'] );
	}

	/**
	 * With no context supplied the session is recorded against the live request.
	 *
	 * This pins the default path: if store_refresh_token_jti() stopped calling
	 * Session_Context::from_request() the recorded IP would be empty. The
	 * user-agent half of the same wiring is covered by test_device_info_parsing.
	 */
	public function test_generate_refresh_token_without_context_records_live_request_ip(): void {
		$expected_ip = Session_Context::client_ip_from_request();
		$this->assertNotEmpty( $expected_ip, 'The test request is expected to carry a client IP.' );

		$this->auth_service->generate_refresh_token( $this->test_user );
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );

		$this->assertCount( 1, $sessions );
		$this->assertEquals( $expected_ip, $sessions[0]['ip_address'] );
	}

	/**
	 * Eviction trims IDLE sessions down to the cap.
	 */
	public function test_store_refresh_token_beyond_cap_keeps_only_the_capped_number_of_sessions(): void {
		$this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertCount( Auth::MAX_SESSIONS_PER_USER, $refresh_tokens );
	}

	/**
	 * The session created by the most recent login survives the eviction.
	 */
	public function test_store_refresh_token_beyond_cap_retains_the_newest_session(): void {
		$this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );

		$newest         = $this->auth_service->generate_refresh_token( $this->test_user );
		$newest_decoded = $this->auth_service->validate_token( $newest, 'refresh' );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayHasKey( $newest_decoded->jti, $refresh_tokens );
	}

	/**
	 * The least recently seen session is the one evicted.
	 */
	public function test_store_refresh_token_beyond_cap_evicts_the_oldest_session(): void {
		$planted = $this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );
		$oldest  = array_key_last( $planted );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayNotHasKey( $oldest, $refresh_tokens );
	}

	/**
	 * An idle session is chosen over a busy one even when the busy one is older.
	 *
	 * This is the rule the #1798 cap lacked. "Oldest of N" is not a proxy for unused when
	 * a programmatic client mints N sessions in an hour.
	 */
	public function test_store_refresh_token_beyond_cap_evicts_the_idle_session_not_the_oldest_busy_one(): void {
		// The oldest session by CREATION, but seen moments ago — a device working right now.
		$busy = $this->plant_sessions( 1, time(), array( 'created' => time() - 30 * DAY_IN_SECONDS ) );
		$this->plant_sessions( Auth::MAX_SESSIONS_PER_USER - 2, time(), array(), true );
		$idle = $this->plant_sessions( 1, $this->idle_timestamp(), array(), true );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayNotHasKey( array_key_first( $idle ), $refresh_tokens );
		$this->assertArrayHasKey( array_key_first( $busy ), $refresh_tokens );
	}

	/**
	 * Three hundred logins in an hour must not sign an in-use device out.
	 *
	 * The shared E2E cashier authenticates hundreds of times a day across parallel shards.
	 * Under the #1798 cap every login past the cap evicted and blacklisted a session that
	 * was minutes old and still serving requests, which is what produced the 401 storm.
	 * The cap now yields rather than log a live session out.
	 */
	public function test_three_hundred_logins_in_an_hour_keep_an_active_session_valid(): void {
		$first          = $this->auth_service->generate_token_pair( $this->test_user );
		$first_refresh  = $this->auth_service->validate_token( $first['refresh_token'], 'refresh' );

		for ( $i = 0; $i < 300; $i++ ) {
			$this->auth_service->generate_refresh_token( $this->test_user );
		}

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertGreaterThan( Auth::MAX_SESSIONS_PER_USER, \count( $refresh_tokens ) );
		$this->assertArrayHasKey( $first_refresh->jti, $refresh_tokens );
		$this->assertFalse( get_transient( 'wcpos_blacklist_' . $first_refresh->jti ) );
		$this->assertNotInstanceOf(
			WP_Error::class,
			$this->auth_service->validate_token( $first['access_token'], 'access' )
		);
	}

	/**
	 * An authenticated request must not touch the session row.
	 *
	 * Recording activity in the row meant every request did a read-modify-write of the
	 * whole array. Overlapping a login, logout or revoke for the same user — four parallel
	 * E2E shards on one cashier — that writes back a stale copy and erases the concurrent
	 * change, losing a session that had just been issued.
	 */
	public function test_validating_an_access_token_does_not_write_the_session_row(): void {
		$tokens  = $this->auth_service->generate_token_pair( $this->test_user );
		$decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		$stale = time() - 30 * DAY_IN_SECONDS;
		$this->set_session_field( $decoded->jti, 'last_active', $stale );
		delete_transient( 'wcpos_session_seen_' . $decoded->jti );

		$writes = $this->count_session_row_writes(
			function () use ( $tokens ) {
				$this->auth_service->validate_token( $tokens['access_token'], 'access' );
			}
		);

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertSame( 0, $writes );
		$this->assertSame( $stale, (int) $refresh_tokens[ $decoded->jti ]['last_active'] );
	}

	/**
	 * Activity is recorded per session, in a transient, so two sessions cannot collide.
	 */
	public function test_validating_an_access_token_records_activity_in_a_transient(): void {
		$tokens  = $this->auth_service->generate_token_pair( $this->test_user );
		$decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );
		delete_transient( 'wcpos_session_seen_' . $decoded->jti );

		$this->auth_service->validate_token( $tokens['access_token'], 'access' );

		$this->assertEqualsWithDelta( time(), (int) get_transient( 'wcpos_session_seen_' . $decoded->jti ), 5 );
	}

	/**
	 * The activity transient is rewritten at most once every few minutes.
	 */
	public function test_validating_an_access_token_throttles_the_activity_transient(): void {
		$tokens  = $this->auth_service->generate_token_pair( $this->test_user );
		$decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );
		$key     = 'wcpos_session_seen_' . $decoded->jti;

		$recent = time() - MINUTE_IN_SECONDS;
		set_transient( $key, $recent, Auth::SESSION_EVICTION_IDLE_SECONDS );
		$this->auth_service->validate_token( $tokens['access_token'], 'access' );

		$this->assertSame( $recent, (int) get_transient( $key ), 'a minute-old record is left alone' );

		$stale = time() - 10 * MINUTE_IN_SECONDS;
		set_transient( $key, $stale, Auth::SESSION_EVICTION_IDLE_SECONDS );
		$this->auth_service->validate_token( $tokens['access_token'], 'access' );

		$this->assertGreaterThan( $stale, (int) get_transient( $key ), 'a ten-minute-old record is refreshed' );
	}

	/**
	 * A session whose activity record has expired is idle, whatever the row says.
	 */
	public function test_store_refresh_token_beyond_cap_evicts_a_session_whose_activity_transient_expired(): void {
		$planted = $this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );
		$oldest  = array_key_last( $planted );
		delete_transient( 'wcpos_session_seen_' . $oldest );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayNotHasKey( $oldest, $refresh_tokens );
	}

	/**
	 * A fresh activity record saves a session whose row timestamp is ancient.
	 *
	 * This is the case the row alone cannot see: a device working through a long-lived
	 * access token has not rewritten `last_active` since its last refresh.
	 */
	public function test_store_refresh_token_beyond_cap_keeps_a_session_with_a_fresh_activity_transient(): void {
		$planted = $this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );
		$oldest  = array_key_last( $planted );
		set_transient( 'wcpos_session_seen_' . $oldest, time(), Auth::SESSION_EVICTION_IDLE_SECONDS );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayHasKey( $oldest, $refresh_tokens );
		// The next-oldest went instead: the cap is still enforced.
		$this->assertCount( Auth::MAX_SESSIONS_PER_USER, $refresh_tokens );
	}

	/**
	 * An evicted session that could still hold a live access token is still revoked.
	 *
	 * Deliberately artificial — an idle session's 30-minute access token is long dead in
	 * practice — but it pins that eviction has not quietly stopped revoking.
	 */
	public function test_store_refresh_token_beyond_cap_rejects_the_evicted_access_token(): void {
		$evicted         = $this->auth_service->generate_token_pair( $this->test_user );
		$evicted_refresh = $this->auth_service->validate_token( $evicted['refresh_token'], 'refresh' );
		// Validate FIRST: a successful access-token validation now marks the session live,
		// which would take it straight back out of the eviction pool.
		$this->assertNotInstanceOf( WP_Error::class, $this->auth_service->validate_token( $evicted['access_token'], 'access' ) );

		// Idle for longer than anything planted below, so it is the eviction candidate. The
		// validation above recorded activity, so that record has to age out too.
		$this->set_session_field( $evicted_refresh->jti, 'last_active', $this->idle_timestamp() - DAY_IN_SECONDS );
		$this->set_session_field( $evicted_refresh->jti, 'created', $this->idle_timestamp() - DAY_IN_SECONDS );
		delete_transient( 'wcpos_session_seen_' . $evicted_refresh->jti );

		$this->plant_sessions( Auth::MAX_SESSIONS_PER_USER - 1, $this->idle_timestamp(), array(), true );
		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );
		$this->assertArrayNotHasKey( $evicted_refresh->jti, $refresh_tokens );

		$validated = $this->auth_service->validate_token( $evicted['access_token'], 'access' );

		$this->assertInstanceOf( WP_Error::class, $validated );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $validated->get_error_code() );
	}

	/**
	 * Sessions kept under the cap are untouched by eviction.
	 */
	public function test_store_refresh_token_under_cap_keeps_every_session(): void {
		$this->plant_sessions( Auth::MAX_SESSIONS_PER_USER - 1, $this->idle_timestamp() );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertCount( Auth::MAX_SESSIONS_PER_USER, $refresh_tokens );
	}

	/**
	 * Expired sessions are still pruned on write, independently of the cap.
	 */
	public function test_store_refresh_token_prunes_expired_sessions(): void {
		$expired_jti = wp_generate_uuid4();
		update_user_meta(
			$this->test_user->ID,
			'_woocommerce_pos_refresh_tokens',
			array(
				$expired_jti => array(
					'expires'     => time() - HOUR_IN_SECONDS,
					'created'     => time() - DAY_IN_SECONDS,
					'last_active' => time() - DAY_IN_SECONDS,
					'ip_address'  => '127.0.0.1',
					'user_agent'  => self::CHROME_DESKTOP_USER_AGENT,
					'device_info' => array(),
				),
			)
		);

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayNotHasKey( $expired_jti, $refresh_tokens );
		$this->assertCount( 1, $refresh_tokens );
	}

	/**
	 * A session whose access token has already expired is evicted WITHOUT a blacklist
	 * transient — clearing a bloated row must not write thousands of records that guard
	 * nothing.
	 */
	public function test_store_refresh_token_beyond_cap_does_not_blacklist_an_evicted_dead_session(): void {
		$planted = $this->plant_sessions( Auth::MAX_SESSIONS_PER_USER, $this->idle_timestamp() );
		$oldest  = array_key_last( $planted );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertArrayNotHasKey( $oldest, $refresh_tokens );
		$this->assertFalse( get_transient( 'wcpos_blacklist_' . $oldest ) );
	}

	/**
	 * A blacklist written by eviction lasts no longer than the access token it guards.
	 */
	public function test_store_refresh_token_beyond_cap_bounds_the_blacklist_to_the_access_token_lifetime(): void {
		$planted = $this->plant_sessions(
			Auth::MAX_SESSIONS_PER_USER,
			$this->idle_timestamp(),
			array( 'access_expires' => time() + ( HOUR_IN_SECONDS / 2 ) )
		);
		$oldest = array_key_last( $planted );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$this->assertTrue( get_transient( 'wcpos_blacklist_' . $oldest ) );

		$timeout = (int) get_option( '_transient_timeout_wcpos_blacklist_' . $oldest );

		$this->assertGreaterThan( time(), $timeout );
		$this->assertLessThanOrEqual( $planted[ $oldest ]['access_expires'], $timeout );
	}

	/**
	 * A row too large to load is discarded unread, and the login that found it succeeds.
	 */
	public function test_login_discards_an_oversized_session_row_and_starts_a_fresh_one(): void {
		global $wpdb;

		$planted = $this->plant_oversized_session_row();

		$token = $this->auth_service->generate_refresh_token( $this->test_user );
		$this->assertNotInstanceOf( WP_Error::class, $token );

		$decoded        = $this->auth_service->validate_token( $token, 'refresh' );
		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertCount( 1, $refresh_tokens );
		$this->assertArrayHasKey( $decoded->jti, $refresh_tokens );
		foreach ( array_keys( $planted ) as $planted_jti ) {
			$this->assertArrayNotHasKey( $planted_jti, $refresh_tokens );
		}
		$this->assertLessThanOrEqual(
			Auth::MAX_SESSIONS_ROW_BYTES,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT LENGTH(meta_value) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
					$this->test_user->ID,
					'_woocommerce_pos_refresh_tokens'
				)
			)
		);
	}

	/**
	 * A refresh loads the whole row, so it needs the same unreadable-row guard as a login.
	 *
	 * Without it the recovery path only ran on login, and a client that still held a valid
	 * refresh token would keep loading the row it could not afford to load.
	 */
	public function test_refreshing_an_access_token_discards_an_unreadable_session_row(): void {
		$tokens = $this->auth_service->generate_token_pair( $this->test_user );
		$this->plant_oversized_session_row();
		$this->assertGreaterThan( Auth::MAX_SESSIONS_ROW_BYTES, $this->session_row_bytes() );

		$refreshed = $this->auth_service->refresh_access_token( $tokens['refresh_token'] );

		// The session it named went with the row, so the refresh is refused — correctly, and
		// the same way a login recovers: the client signs in again.
		$this->assertInstanceOf( WP_Error::class, $refreshed );
		$this->assertSame( 0, $this->session_row_bytes() );
	}

	/**
	 * A big-but-readable row is TRIMMED, not thrown away.
	 *
	 * The first release of this guard deleted anything past a megabyte, which signed every
	 * device out over a row eviction could simply have cut down.
	 */
	public function test_login_trims_a_large_but_readable_row_and_keeps_the_active_session(): void {
		$active = $this->plant_sessions( 1, time() );
		$this->plant_bulky_idle_sessions( 251 );

		$before = $this->session_row_bytes();
		$this->assertGreaterThan( 2 * MB_IN_BYTES, $before );
		$this->assertLessThan( Auth::MAX_SESSIONS_ROW_BYTES, $before );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertCount( Auth::MAX_SESSIONS_PER_USER, $refresh_tokens );
		$this->assertArrayHasKey( array_key_first( $active ), $refresh_tokens );
		$this->assertLessThan( $before, $this->session_row_bytes() );
	}

	/**
	 * A row under the byte ceiling is left alone.
	 */
	public function test_login_keeps_a_session_row_under_the_byte_ceiling(): void {
		$planted = $this->plant_sessions( 3, time() );

		$this->auth_service->generate_refresh_token( $this->test_user );

		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );

		$this->assertCount( 4, $refresh_tokens );
		foreach ( array_keys( $planted ) as $planted_jti ) {
			$this->assertArrayHasKey( $planted_jti, $refresh_tokens );
		}
	}

	/**
	 * How many times `$run` writes the session row.
	 *
	 * Counts through the `update_user_metadata` short-circuit filter, returning `$check`
	 * untouched so the write still happens — the count is the assertion, not a stub.
	 *
	 * @param callable $run The code under test.
	 *
	 * @return int Number of writes to `_woocommerce_pos_refresh_tokens`.
	 */
	private function count_session_row_writes( callable $run ): int {
		$writes = 0;
		$spy    = function ( $check, $object_id, $meta_key ) use ( &$writes ) {
			if ( '_woocommerce_pos_refresh_tokens' === $meta_key ) {
				++$writes;
			}

			return $check;
		};

		add_filter( 'update_user_metadata', $spy, 10, 3 );
		try {
			$run();
		} finally {
			remove_filter( 'update_user_metadata', $spy, 10 );
		}

		return $writes;
	}

	/**
	 * A timestamp comfortably past the eviction idle window.
	 */
	private function idle_timestamp(): int {
		return time() - Auth::SESSION_EVICTION_IDLE_SECONDS - DAY_IN_SECONDS;
	}

	/**
	 * The stored length of the session row, in bytes.
	 */
	private function session_row_bytes(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(meta_value) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$this->test_user->ID,
				'_woocommerce_pos_refresh_tokens'
			)
		);
	}

	/**
	 * Overwrite one field of one stored session.
	 *
	 * @param string $jti   Session JTI.
	 * @param string $field Field name.
	 * @param mixed  $value New value.
	 */
	private function set_session_field( string $jti, string $field, $value ): void {
		$refresh_tokens = get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true );
		$refresh_tokens = \is_array( $refresh_tokens ) ? $refresh_tokens : array();
		$refresh_tokens[ $jti ][ $field ] = $value;
		update_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', $refresh_tokens );
	}

	/**
	 * Write session records straight to user meta, oldest last.
	 *
	 * @param int   $count       How many sessions to plant.
	 * @param int   $newest_seen Timestamp of the most recently active planted session.
	 * @param array $overrides   Fields to force on every planted session.
	 * @param bool  $append      Keep the sessions already stored.
	 *
	 * @return array The planted sessions, keyed by JTI, in planting order.
	 */
	private function plant_sessions( int $count, int $newest_seen, array $overrides = array(), bool $append = false ): array {
		$sessions = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$seen                            = $newest_seen - $i;
			$sessions[ wp_generate_uuid4() ] = array_merge(
				array(
					'expires'        => time() + 30 * DAY_IN_SECONDS,
					'created'        => $seen,
					'last_active'    => $seen,
					'access_expires' => $seen + ( HOUR_IN_SECONDS / 2 ),
					'ip_address'     => '127.0.0.1',
					'user_agent'     => self::CHROME_DESKTOP_USER_AGENT,
					'device_info'    => array(),
				),
				$overrides
			);
		}

		$stored = $append ? get_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', true ) : array();
		$stored = \is_array( $stored ) ? $stored : array();

		update_user_meta( $this->test_user->ID, '_woocommerce_pos_refresh_tokens', array_merge( $stored, $sessions ) );

		return $sessions;
	}

	/**
	 * Append idle sessions fat enough to push the row past two megabytes.
	 *
	 * @param int $count How many to plant.
	 */
	private function plant_bulky_idle_sessions( int $count ): void {
		$this->plant_sessions(
			$count,
			$this->idle_timestamp(),
			array( 'user_agent' => str_repeat( 'U', 8192 ) ),
			true
		);
	}

	/**
	 * Plant a genuinely serialized session row past the byte ceiling, straight through
	 * $wpdb so nothing in the write path can cap it first.
	 *
	 * @return array The planted sessions, keyed by JTI.
	 */
	private function plant_oversized_session_row(): array {
		global $wpdb;

		$sessions = array();
		for ( $i = 0; $i < 110; $i++ ) {
			$sessions[ wp_generate_uuid4() ] = array(
				'expires'     => time() + 30 * DAY_IN_SECONDS,
				'created'     => time() - $i,
				'last_active' => time() - $i,
				'ip_address'  => '127.0.0.1',
				// A real row grows through thousands of small entries; a few fat ones cross
				// the same ceiling without making the fixture slow to build.
				'user_agent'  => str_repeat( 'U', 65536 ),
				'device_info' => array(),
			);
		}

		$blob = serialize( $sessions );
		$this->assertGreaterThan( Auth::MAX_SESSIONS_ROW_BYTES, \strlen( $blob ) );

		$wpdb->delete(
			$wpdb->usermeta,
			array(
				'user_id'  => $this->test_user->ID,
				'meta_key' => '_woocommerce_pos_refresh_tokens',
			),
			array( '%d', '%s' )
		);
		$wpdb->insert(
			$wpdb->usermeta,
			array(
				'user_id'    => $this->test_user->ID,
				'meta_key'   => '_woocommerce_pos_refresh_tokens',
				'meta_value' => $blob,
			),
			array( '%d', '%s', '%s' )
		);
		wp_cache_delete( $this->test_user->ID, 'user_meta' );

		return $sessions;
	}

	/**
	 * Store a session against an injected context and return the stored session.
	 *
	 * store_refresh_token_jti() is private; the optional Session_Context
	 * parameter is the seam this exercises, so it is invoked by reflection
	 * rather than by mutating superglobals.
	 *
	 * @param Session_Context $context The context to record the session against.
	 *
	 * @return array The stored session.
	 */
	private function store_session_with_context( Session_Context $context ): array {
		$jti    = wp_generate_uuid4();
		$method = new ReflectionMethod( Auth::class, 'store_refresh_token_jti' );
		$method->setAccessible( true );
		$method->invoke( $this->auth_service, $this->test_user->ID, $jti, time() + DAY_IN_SECONDS, $context );

		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );

		foreach ( $sessions as $session ) {
			if ( $session['jti'] === $jti ) {
				return $session;
			}
		}

		$this->fail( 'The stored session was not found.' );
	}

	/**
	 * Store a session against an injected context and return its device info.
	 *
	 * @param Session_Context $context The context to record the session against.
	 *
	 * @return array The stored device info.
	 */
	private function store_session_and_get_device_info( Session_Context $context ): array {
		$session = $this->store_session_with_context( $context );

		return $session['device_info'];
	}
}
