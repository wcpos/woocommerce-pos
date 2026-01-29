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
		$this->assertArrayHasKey( 'avatar_url', $result );

		$this->assertEquals( $this->test_user->ID, $result['id'] );
		$this->assertEquals( $this->test_user->user_login, $result['username'] );
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
}
