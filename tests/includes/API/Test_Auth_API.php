<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Auth;
use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_API extends WP_UnitTestCase {
	/**
	 * @var Auth
	 */
	private $api;

	/**
	 * @var AuthService
	 */
	private $auth_service;

	/**
	 * @var \WP_User
	 */
	private $admin_user;

	/**
	 * @var \WP_User
	 */
	private $shop_manager_user;

	/**
	 * @var \WP_User
	 */
	private $regular_user;

	public function setUp(): void {
		parent::setUp();
		$this->api          = new Auth();
		$this->auth_service = AuthService::instance();

		// Create test users
		$this->admin_user = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
		$this->shop_manager_user = $this->factory->user->create_and_get( array( 'role' => 'shop_manager' ) );
		$this->regular_user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
	}

	public function tearDown(): void {
		parent::tearDown();
		
		// Clean up test users
		wp_delete_user( $this->admin_user->ID );
		wp_delete_user( $this->shop_manager_user->ID );
		wp_delete_user( $this->regular_user->ID );
	}

	/**
	 * Create a mock REST request.
	 *
	 * @param array $params
	 * @param array $headers
	 * @return WP_REST_Request
	 */
	private function create_request( array $params = array(), array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request();
		
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		
		return $request;
	}

	/**
	 * Test authorization test endpoint with header auth.
	 */
	public function test_authorization_endpoint_with_header(): void {
		$request = $this->create_request( array(), array( 'authorization' => 'Bearer test_token' ) );
		$response = $this->api->test_authorization( $request );
		$data = $response->get_data();

		$this->assertEquals( 'success', $data['status'] );
		$this->assertTrue( $data['received_header_auth'] );
		$this->assertEquals( 'Bearer test_token', $data['header_value'] );
		$this->assertEquals( 'header', $data['auth_method'] );
	}

	/**
	 * Test authorization test endpoint with param auth.
	 */
	public function test_authorization_endpoint_with_param(): void {
		$request = $this->create_request( array( 'authorization' => 'Bearer test_token' ) );
		$response = $this->api->test_authorization( $request );
		$data = $response->get_data();

		$this->assertEquals( 'success', $data['status'] );
		$this->assertTrue( $data['received_param_auth'] );
		$this->assertEquals( 'Bearer test_token', $data['param_value'] );
		$this->assertEquals( 'param', $data['auth_method'] );
	}

	/**
	 * Test authorization test endpoint with no auth.
	 */
	public function test_authorization_endpoint_with_no_auth(): void {
		$request = $this->create_request();
		$response = $this->api->test_authorization( $request );
		$data = $response->get_data();

		$this->assertEquals( 'error', $data['status'] );
		$this->assertEquals( 'No authorization token detected', $data['message'] );
	}

	/**
	 * Test refresh token endpoint with valid token.
	 */
	public function test_refresh_token_with_valid_token(): void {
		// Generate a valid refresh token
		$refresh_token = $this->auth_service->generate_refresh_token( $this->regular_user );

		$request = $this->create_request( array( 'refresh_token' => $refresh_token ) );
		$response = $this->api->refresh_token( $request );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertArrayHasKey( 'token_type', $data );
		$this->assertArrayHasKey( 'expires_in', $data );
		$this->assertArrayHasKey( 'expires_at', $data );
		$this->assertEquals( 'Bearer', $data['token_type'] );
		$this->assertIsString( $data['access_token'] );
		$this->assertIsInt( $data['expires_in'] );
		$this->assertIsInt( $data['expires_at'] );
	}

	/**
	 * Test refresh token endpoint with invalid token.
	 */
	public function test_refresh_token_with_invalid_token(): void {
		$request = $this->create_request( array( 'refresh_token' => 'invalid_token' ) );
		$response = $this->api->refresh_token( $request );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayHasKey( 'error_description', $data );
		$this->assertEquals( 'invalid_grant', $data['error'] );
	}

	/**
	 * Test refresh token endpoint with missing token.
	 */
	public function test_refresh_token_with_missing_token(): void {
		$request = $this->create_request();
		$response = $this->api->refresh_token( $request );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 'invalid_request', $data['error'] );
	}

	/**
	 * Test get sessions endpoint for current user.
	 */
	public function test_get_sessions_for_current_user(): void {
		wp_set_current_user( $this->regular_user->ID );

		// Create a session
		$this->auth_service->generate_refresh_token( $this->regular_user );

		$request = $this->create_request();
		$response = $this->api->get_sessions( $request );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertArrayHasKey( 'sessions', $data );
		$this->assertEquals( $this->regular_user->ID, $data['user_id'] );
		$this->assertIsArray( $data['sessions'] );
		$this->assertCount( 1, $data['sessions'] );
		
		$session = $data['sessions'][0];
		$this->assertArrayHasKey( 'jti', $session );
		$this->assertArrayHasKey( 'created', $session );
		$this->assertArrayHasKey( 'last_active', $session );
		$this->assertArrayHasKey( 'expires', $session );
		$this->assertArrayHasKey( 'is_current', $session );
	}

	/**
	 * Test get sessions endpoint for specific user.
	 */
	public function test_get_sessions_for_specific_user(): void {
		wp_set_current_user( $this->admin_user->ID );

		// Create a session for regular user
		$this->auth_service->generate_refresh_token( $this->regular_user );

		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$response = $this->api->get_sessions( $request );
		$data = $response->get_data();

		$this->assertEquals( $this->regular_user->ID, $data['user_id'] );
		$this->assertCount( 1, $data['sessions'] );
	}

	/**
	 * Test delete session endpoint.
	 */
	public function test_delete_session(): void {
		wp_set_current_user( $this->regular_user->ID );

		// Create a session
		$refresh_token = $this->auth_service->generate_refresh_token( $this->regular_user );
		$decoded = $this->auth_service->validate_token( $refresh_token, 'refresh' );

		// Verify session exists
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 1, $sessions );

		// Delete the session
		$request = $this->create_request(
			array(
				'jti' => $decoded->jti,
				'user_id' => $this->regular_user->ID,
			)
		);
		$response = $this->api->delete_session( $request );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Session revoked successfully.', $data['message'] );

		// Verify session is gone
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test delete session with missing parameters.
	 */
	public function test_delete_session_with_missing_params(): void {
		wp_set_current_user( $this->regular_user->ID );

		$request = $this->create_request();
		$response = $this->api->delete_session( $request );
		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'Missing required parameters.', $data['message'] );
	}

	/**
	 * Test delete all sessions.
	 */
	public function test_delete_all_sessions(): void {
		wp_set_current_user( $this->regular_user->ID );

		// Create multiple sessions
		$this->auth_service->generate_refresh_token( $this->regular_user );
		$this->auth_service->generate_refresh_token( $this->regular_user );
		$this->auth_service->generate_refresh_token( $this->regular_user );

		// Verify sessions exist
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 3, $sessions );

		// Delete all sessions
		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$response = $this->api->delete_all_sessions( $request );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Sessions revoked successfully.', $data['message'] );

		// Verify all sessions are gone
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test delete all sessions except current.
	 */
	public function test_delete_all_sessions_except_current(): void {
		wp_set_current_user( $this->regular_user->ID );

		// Create multiple sessions
		$token1 = $this->auth_service->generate_refresh_token( $this->regular_user );
		$this->auth_service->generate_refresh_token( $this->regular_user );
		$this->auth_service->generate_refresh_token( $this->regular_user );

		// Verify sessions exist
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 3, $sessions );

		// Delete all sessions except current (simulated with Bearer header)
		$request = $this->create_request(
			array(
				'user_id' => $this->regular_user->ID,
				'except_current' => true,
			),
			array( 'authorization' => 'Bearer ' . $token1 )
		);
		$response = $this->api->delete_all_sessions( $request );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );

		// Verify only one session remains
		$sessions = $this->auth_service->get_user_sessions( $this->regular_user->ID );
		$this->assertCount( 1, $sessions );
	}

	/**
	 * Test get all users sessions (admin only).
	 */
	public function test_get_all_users_sessions(): void {
		wp_set_current_user( $this->admin_user->ID );

		// Create sessions for multiple users
		$this->auth_service->generate_refresh_token( $this->admin_user );
		$this->auth_service->generate_refresh_token( $this->regular_user );
		$this->auth_service->generate_refresh_token( $this->shop_manager_user );

		$request = $this->create_request();
		$response = $this->api->get_all_users_sessions( $request );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'users', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertIsArray( $data['users'] );
		$this->assertGreaterThanOrEqual( 3, $data['total'] );

		// Check user data structure
		if ( ! empty( $data['users'] ) ) {
			$user_data = $data['users'][0];
			$this->assertArrayHasKey( 'user_id', $user_data );
			$this->assertArrayHasKey( 'username', $user_data );
			$this->assertArrayHasKey( 'display_name', $user_data );
			$this->assertArrayHasKey( 'session_count', $user_data );
			$this->assertArrayHasKey( 'last_active', $user_data );
			$this->assertArrayHasKey( 'sessions', $user_data );
		}
	}

	/**
	 * Test permission check for own sessions.
	 */
	public function test_check_session_permissions_for_own_sessions(): void {
		wp_set_current_user( $this->regular_user->ID );

		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$result = $this->api->check_session_permissions( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test permission check for admin accessing other user's sessions.
	 */
	public function test_check_session_permissions_for_admin(): void {
		wp_set_current_user( $this->admin_user->ID );

		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$result = $this->api->check_session_permissions( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test permission check for shop manager accessing other user's sessions.
	 */
	public function test_check_session_permissions_for_shop_manager(): void {
		wp_set_current_user( $this->shop_manager_user->ID );

		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$result = $this->api->check_session_permissions( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test permission check for regular user accessing other user's sessions.
	 */
	public function test_check_session_permissions_for_regular_user(): void {
		wp_set_current_user( $this->regular_user->ID );

		$request = $this->create_request( array( 'user_id' => $this->admin_user->ID ) );
		$result = $this->api->check_session_permissions( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test permission check when not logged in.
	 */
	public function test_check_session_permissions_when_not_logged_in(): void {
		wp_set_current_user( 0 );

		$request = $this->create_request( array( 'user_id' => $this->regular_user->ID ) );
		$result = $this->api->check_session_permissions( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test admin permission check for admin user.
	 */
	public function test_check_admin_permissions_for_admin(): void {
		wp_set_current_user( $this->admin_user->ID );

		$request = $this->create_request();
		$result = $this->api->check_admin_permissions( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test admin permission check for shop manager.
	 */
	public function test_check_admin_permissions_for_shop_manager(): void {
		wp_set_current_user( $this->shop_manager_user->ID );

		$request = $this->create_request();
		$result = $this->api->check_admin_permissions( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test admin permission check for regular user.
	 */
	public function test_check_admin_permissions_for_regular_user(): void {
		wp_set_current_user( $this->regular_user->ID );

		$request = $this->create_request();
		$result = $this->api->check_admin_permissions( $request );

		$this->assertFalse( $result );
	}
}

