<?php
/**
 * Refresh endpoint status codes, dispatched on the wcpos/v2 lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Auth;

/**
 * The refresh route the 1.10+ clients call answers with the status that matches the failure.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_Refresh_Status extends WCPOS_REST_Unit_Test_Case {
	/**
	 * A refresh without a refresh_token answers 400.
	 *
	 * The route declares refresh_token as required, so core rejects the missing parameter
	 * before the handler runs.
	 */
	public function test_refresh_missing_parameter_returns_400(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
	}

	/**
	 * A refresh with an empty refresh_token reaches the handler and answers 400.
	 */
	public function test_refresh_empty_parameter_returns_400(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => '' ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
	}

	/**
	 * A refresh with an invalid token answers 403.
	 */
	public function test_refresh_invalid_token_returns_403(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => 'invalid_token' ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	/**
	 * A refresh for a revoked session answers 403.
	 */
	public function test_refresh_revoked_session_returns_403(): void {
		// Arrange.
		$user    = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$auth    = Auth::instance();
		$tokens  = $auth->generate_token_pair( $user );
		$decoded = $auth->validate_token( $tokens['refresh_token'], 'refresh' );
		$this->assertTrue( $auth->revoke_session_with_blacklist( $user->ID, $decoded->jti ) );
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => $tokens['refresh_token'] ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	/**
	 * A refresh with a valid token answers 200 with an access token.
	 */
	public function test_refresh_valid_token_returns_200(): void {
		// Arrange.
		$user   = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$tokens = Auth::instance()->generate_token_pair( $user );
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => $tokens['refresh_token'] ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'access_token', $response->get_data() );
	}

	/**
	 * A refresh for a deleted user answers 403.
	 *
	 * The handler maps a missing user to 404, but deleting the user deletes the session row
	 * with its meta, so the revoked-session check answers first.
	 */
	public function test_refresh_deleted_user_returns_403(): void {
		// Arrange.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$tokens  = Auth::instance()->generate_token_pair( get_userdata( $user_id ) );
		wp_delete_user( $user_id );
		wp_set_current_user( 0 );
		$request = $this->wp_rest_post_request( '/wcpos/v2/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => $tokens['refresh_token'] ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}
}
