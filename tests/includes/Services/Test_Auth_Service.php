<?php
/**
 * Auth Service tests.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Session_Registry;
use WP_Error;
use WP_UnitTestCase;

/**
 * Auth Service coverage.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_Service extends WP_UnitTestCase {
	/**
	 * Auth service under test.
	 *
	 * @var Auth
	 */
	private $auth_service;
	/**
	 * Test user.
	 *
	 * @var \WP_User
	 */
	private $test_user;

	/**
	 * Set up the test user.
	 */
	public function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		$this->auth_service = Auth::instance();

		// Create a test user.
		$this->test_user = $this->factory->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Clean up the test user.
	 */
	public function tearDown(): void {
		remove_filter( 'user_has_cap', array( $this, 'apply_role_denies' ), 10 );
		foreach ( $this->denied_caps as $cap ) {
			get_role( 'customer' )->remove_cap( $cap );
		}
		$this->denied_caps = array();

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		parent::tearDown();
		unset( $this->auth_service );

		// Clean up test user.
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
		// Generate refresh token.
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Verify session exists.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 1, $sessions );

		// Revoke session.
		$result = $this->auth_service->revoke_session( $this->test_user->ID, $decoded->jti );
		$this->assertTrue( $result );

		// Verify session is removed.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test revoking all sessions except current.
	 */
	public function test_revoke_all_sessions_except_current(): void {
		// Generate multiple refresh tokens.
		$token1   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded1 = $this->auth_service->validate_token( $token1, 'refresh' );

		$token2 = $this->auth_service->generate_refresh_token( $this->test_user );
		$token3 = $this->auth_service->generate_refresh_token( $this->test_user );

		// Verify all sessions exist.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 3, $sessions );

		// Revoke all except first session.
		$result = $this->auth_service->revoke_all_sessions_except( $this->test_user->ID, $decoded1->jti );
		$this->assertTrue( $result );

		// Verify only first session remains.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 1, $sessions );
		$this->assertEquals( $decoded1->jti, $sessions[0]['jti'] );
	}

	/**
	 * Test revoking all sessions.
	 */
	public function test_revoke_all_sessions(): void {
		// Generate multiple refresh tokens.
		$this->auth_service->generate_refresh_token( $this->test_user );
		$this->auth_service->generate_refresh_token( $this->test_user );
		$this->auth_service->generate_refresh_token( $this->test_user );

		// Verify all sessions exist.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 3, $sessions );

		// Revoke all sessions.
		$result = $this->auth_service->revoke_all_refresh_tokens( $this->test_user->ID );
		$this->assertTrue( $result );

		// Verify no sessions remain.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test refresh access token.
	 */
	public function test_refresh_access_token(): void {
		// Generate refresh token.
		$refresh_token = $this->auth_service->generate_refresh_token( $this->test_user );

		// Sleep briefly to ensure timestamp difference.
		sleep( 1 );

		// Refresh access token.
		$result = $this->auth_service->refresh_access_token( $refresh_token );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'token_type', $result );
		$this->assertArrayHasKey( 'expires_at', $result );

		// Verify last_active was updated.
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
		// Set current user.
		wp_set_current_user( $this->test_user->ID );

		// User should be able to manage their own sessions.
		$can_manage = $this->auth_service->can_manage_user_sessions( $this->test_user->ID );
		$this->assertTrue( $can_manage );
	}

	/**
	 * Test permission checking for admin.
	 */
	public function test_admin_can_manage_all_sessions(): void {
		// Create another user.
		$other_user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		// Set admin as current user.
		wp_set_current_user( $this->test_user->ID );

		// Admin should be able to manage other user's sessions.
		$can_manage = $this->auth_service->can_manage_user_sessions( $other_user->ID );
		$this->assertTrue( $can_manage );

		// Cleanup.
		wp_delete_user( $other_user->ID );
	}

	/**
	 * Test permission checking for non-admin.
	 */
	public function test_non_admin_cannot_manage_others_sessions(): void {
		// Create two regular users.
		$user1 = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user2 = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		// Set user1 as current user.
		wp_set_current_user( $user1->ID );

		// User1 should NOT be able to manage user2's sessions.
		$can_manage = $this->auth_service->can_manage_user_sessions( $user2->ID );
		$this->assertFalse( $can_manage );

		// Cleanup.
		wp_delete_user( $user1->ID );
		wp_delete_user( $user2->ID );
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

		// Decode both tokens.
		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertNotInstanceOf( WP_Error::class, $refresh_decoded );

		// Access token should have refresh_jti linking to refresh token.
		$this->assertObjectHasProperty( 'refresh_jti', $access_decoded );
		$this->assertEquals( $refresh_decoded->jti, $access_decoded->refresh_jti );
	}

	/**
	 * Test token blacklist (can blacklist any JTI - access token or session).
	 */
	public function test_token_blacklist(): void {
		// Generate access token.
		$token   = $this->auth_service->generate_access_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'access' );

		$this->assertNotInstanceOf( WP_Error::class, $decoded );

		// Blacklist the token by its JTI.
		$result = $this->auth_service->blacklist_token( $decoded->jti, 3600 );
		$this->assertTrue( $result );

		// Try to validate blacklisted token.
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
		// Generate token pair.
		$tokens = $this->auth_service->generate_token_pair( $this->test_user );

		// Decode to get JTIs.
		$access_decoded  = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$refresh_decoded = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		// Access token should be valid and linked to refresh token.
		$this->assertNotInstanceOf( WP_Error::class, $access_decoded );
		$this->assertEquals( $refresh_decoded->jti, $access_decoded->refresh_jti );

		// Revoke session with blacklist (blacklists the refresh_jti).
		$result = $this->auth_service->revoke_session_with_blacklist(
			$this->test_user->ID,
			$refresh_decoded->jti
		);
		$this->assertTrue( $result );

		// Refresh token should be revoked from user meta.
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
		$this->assertCount( 0, $sessions );

		// Access token should be invalid because its refresh_jti is blacklisted.
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

		$refresh_tokens = $this->auth_service->sessions()->entries( $this->test_user->ID );
		$this->assertIsArray( $refresh_tokens );
		$this->assertArrayHasKey( $refresh_decoded->jti, $refresh_tokens );

		unset( $refresh_tokens[ $refresh_decoded->jti ]['access_expires'] );
		update_user_meta( $this->test_user->ID, Session_Registry::META_KEY, $refresh_tokens );

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

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage).
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
		// Generate access token but try to validate as refresh.
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

		// Legacy generate_token now just returns access token string.
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		// Verify it's a valid access token.
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
		// Generate refresh token.
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Revoke it.
		$result = $this->auth_service->revoke_refresh_token( $this->test_user->ID, $decoded->jti );

		$this->assertTrue( $result );

		// Verify it's gone.
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

		// Should return false if no matching token found.
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
	 * Capabilities denied on the customer role by the current test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $denied_caps = array();

	/**
	 * Create a customer-first administrator with Members-style role denies.
	 *
	 * @param string[] $denied Capabilities denied on the customer role.
	 * @return \WP_User
	 */
	private function create_denied_user( array $denied ): \WP_User {
		$this->denied_caps = $denied;
		foreach ( $denied as $cap ) {
			get_role( 'customer' )->add_cap( $cap, false );
		}
		$user_id = wp_insert_user(
			array(
				'user_login' => 'members-denied-user',
				'user_pass'  => 'test-password',
				'role'       => 'customer',
			)
		);
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'administrator' );
		add_filter( 'user_has_cap', array( $this, 'apply_role_denies' ), 10, 4 );

		return $user;
	}

	/**
	 * Emulate Members: a deny on any role overrides grants for multi-role users.
	 *
	 * @param array    $allcaps Merged grants.
	 * @param string[] $caps    Required capabilities.
	 * @param array    $args    Capability check arguments.
	 * @param \WP_User $user    User being checked.
	 * @return array
	 */
	public function apply_role_denies( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( count( $user->roles ) >= 2 ) {
			foreach ( $user->roles as $role ) {
				foreach ( get_role( $role )->capabilities as $cap => $grant ) {
					if ( false === $grant ) {
						$allcaps[ $cap ] = false;
					}
				}
			}
		}

		return $allcaps;
	}

	/**
	 * Payload excludes a role-editor deny even when raw administrator grants win.
	 */
	public function test_get_user_data_capabilities_excludes_capability_denied_by_user_has_cap_filter(): void {
		$user = $this->create_denied_user( array( 'read_private_products' ) );
		$this->assertTrue( $user->allcaps['read_private_products'] );

		$data = $this->auth_service->get_user_data( $user );

		$this->assertNotContains( 'read_private_products', $data['capabilities'] );
		$this->assertContains( 'access_woocommerce_pos', $data['capabilities'] );
		$this->assertContains( 'edit_product', $data['capabilities'] );
		$this->assertFalse( user_can( $user, 'read_private_products' ) );
	}

	/**
	 * Singular meta grants also respect the role-editor deny filter.
	 */
	public function test_get_user_data_capabilities_excludes_meta_cap_denied_by_user_has_cap_filter(): void {
		$user = $this->create_denied_user( array( 'edit_product' ) );
		$this->assertTrue( $user->allcaps['edit_product'] );

		$data = $this->auth_service->get_user_data( $user );

		$this->assertNotContains( 'edit_product', $data['capabilities'] );
	}
}
