<?php
/**
 * Tests for compatibility with third-party JWT authentication plugins.
 *
 * Verifies that WCPOS authentication works correctly when another plugin
 * (e.g. jwt-authentication-for-wp-rest-api) is active and hooks into the
 * same filter chain to validate Bearer tokens with its own secret/format.
 *
 * The conflict scenario:
 * 1. A JWT plugin hooks determine_current_user at priority 10, sees the
 *    WCPOS Bearer token, fails to validate it (wrong secret/format), stores
 *    an internal WP_Error, and returns false.
 * 2. WCPOS hooks determine_current_user at priority 20, gets false,
 *    validates the Bearer token as its own JWT — succeeds, returns user_id.
 * 3. The JWT plugin hooks rest_authentication_errors (at default priority 10),
 *    sees its stored error, and returns it — blocking the request.
 * 4. WCPOS hooks rest_authentication_errors at priority 50, sees !empty($errors),
 *    and passes the JWT plugin's error through — request blocked with 403.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_Error;

/**
 * Test_JWT_Plugin_Compatibility class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_JWT_Plugin_Compatibility extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Tear down: always clean up the Authorization header.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tearDown();
	}

	/**
	 * Simulate a third-party JWT plugin that hooks into determine_current_user
	 * and rest_authentication_errors, mimicking how jwt-authentication-for-wp-rest-api
	 * behaves when it cannot validate a Bearer token it did not issue.
	 *
	 * @param WP_Error $stored_error Reference to variable that stores the plugin's error.
	 */
	private function simulate_conflicting_jwt_plugin( WP_Error &$stored_error ): void {
		// Priority 10: JWT plugin intercepts Bearer token, fails validation,
		// stores error for later, returns $user_id unchanged (false).
		add_filter(
			'determine_current_user',
			function ( $user_id ) use ( &$stored_error ) {
				$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
				if ( str_starts_with( $auth, 'Bearer ' ) ) {
					$stored_error = new WP_Error(
						'jwt_auth_bad_config',
						'[jwt_auth] The Secret Key is not configured.',
						array( 'status' => 403 )
					);
				}
				return $user_id;
			},
			10
		);

		// Default priority 10: JWT plugin returns its stored error,
		// which blocks the request before WCPOS gets a chance to clear it.
		add_filter(
			'rest_authentication_errors',
			function ( $error ) use ( &$stored_error ) {
				if ( ! empty( $error ) ) {
					return $error;
				}
				if ( ! empty( $stored_error ) ) {
					return $stored_error;
				}
				return $error;
			},
			10
		);
	}

	/**
	 * A valid WCPOS Bearer token should authenticate successfully even when
	 * a third-party JWT plugin is active and rejects the same Bearer token
	 * (because it was not issued by that plugin).
	 *
	 * Without the fix, the JWT plugin's rest_authentication_errors callback
	 * fires at priority 10, sets a WP_Error, and WCPOS passes it through at
	 * priority 50 — resulting in a 403 even though WCPOS already authenticated
	 * the user in determine_current_user.
	 */
	public function test_wcpos_bearer_token_works_when_third_party_jwt_plugin_active(): void {
		$jwt_plugin_error = new WP_Error(); // placeholder.; will be replaced.

		$this->simulate_conflicting_jwt_plugin( $jwt_plugin_error );

		// Generate a valid WCPOS JWT for the admin user.
		$user         = get_user_by( 'id', $this->user );
		$auth_service = Auth::instance();
		$access_token = $auth_service->generate_access_token( $user );

		// Place the token in $_SERVER so WCPOS's get_auth_header() finds it.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Reset current user — authentication must come from the Bearer token.
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'WCPOS Bearer token should authenticate successfully even when a third-party JWT plugin is active and rejects it'
		);
	}

	/**
	 * When the Bearer token is genuinely invalid (not issued by WCPOS either),
	 * the request should still be blocked — we must not blindly clear all errors.
	 */
	public function test_invalid_bearer_token_is_still_rejected_when_jwt_plugin_active(): void {
		$jwt_plugin_error = new WP_Error(); // placeholder.

		$this->simulate_conflicting_jwt_plugin( $jwt_plugin_error );

		// Use a token that is not valid for WCPOS (neither JWT plugin nor WCPOS can validate it).
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer this.is.not.a.valid.token'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals(
			200,
			$response->get_status(),
			'An invalid Bearer token should not grant access even when a JWT plugin is active'
		);
	}

	/**
	 * A third-party app user authenticated via the JWT plugin (using that
	 * plugin's own token) who lacks access_woocommerce_pos should be blocked
	 * from POS endpoints — the capability gate must still apply.
	 */
	public function test_jwt_plugin_authenticated_user_without_pos_cap_is_blocked(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		// Subscriber has no access_woocommerce_pos capability.

		// Simulate the JWT plugin successfully authenticating a user with its own token.
		add_filter(
			'determine_current_user',
			function () use ( $subscriber ) {
				return $subscriber; // JWT plugin validated its own token, returns user_id.
			},
			10
		);

		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'A user authenticated by a third-party JWT plugin but lacking POS capability should be blocked'
		);

		wp_delete_user( $subscriber );
	}
}
