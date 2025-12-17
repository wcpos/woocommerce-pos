<?php

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_Error;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_Service extends WP_UnitTestCase {
	private $auth_service;
	private $test_user;

	public function setUp(): void {
		parent::setUp();
		$this->auth_service = Auth::instance();

		// Create a test user
		$this->test_user = $this->factory->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
	}

	public function tearDown(): void {
		parent::tearDown();
		unset( $this->auth_service );
		
		// Clean up test user
		if ( $this->test_user ) {
			wp_delete_user( $this->test_user->ID );
		}
	}

	/**
	 * Test secret key generation.
	 */
	public function test_get_secret_key(): void {
		$secret_key = $this->auth_service->get_secret_key();
		$this->assertNotEmpty( $secret_key );
		$this->assertIsString( $secret_key );
		$this->assertEquals( 64, strlen( $secret_key ) );
	}

	/**
	 * Test refresh secret key generation.
	 */
	public function test_get_refresh_secret_key(): void {
		$secret_key = $this->auth_service->get_refresh_secret_key();
		$this->assertNotEmpty( $secret_key );
		$this->assertIsString( $secret_key );
		$this->assertEquals( 64, strlen( $secret_key ) );
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
		// Set a known user agent
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		// Generate token which should trigger device parsing
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
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
	 * Test public update_session_activity method.
	 */
	public function test_public_update_session_activity(): void {
		// Generate refresh token
		$token   = $this->auth_service->generate_refresh_token( $this->test_user );
		$decoded = $this->auth_service->validate_token( $token, 'refresh' );

		// Get initial session
		$sessions = $this->auth_service->get_user_sessions( $this->test_user->ID );
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
}

