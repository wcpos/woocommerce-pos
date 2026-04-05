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
	 * Closure registered on determine_current_user during the test, kept so
	 * tearDown can remove it explicitly.
	 *
	 * @var callable|null
	 */
	private $sim_determine_cb = null;

	/**
	 * Closure registered on rest_authentication_errors during the test, kept
	 * so tearDown can remove it explicitly.
	 *
	 * @var callable|null
	 */
	private $sim_auth_errors_cb = null;

	/**
	 * Tear down: remove any filters added by the simulation and clean up the
	 * Authorization header so state does not leak between tests.
	 */
	public function tearDown(): void {
		if ( null !== $this->sim_determine_cb ) {
			remove_filter( 'determine_current_user', $this->sim_determine_cb, 10 );
			$this->sim_determine_cb = null;
		}
		if ( null !== $this->sim_auth_errors_cb ) {
			remove_filter( 'rest_authentication_errors', $this->sim_auth_errors_cb, 10 );
			$this->sim_auth_errors_cb = null;
		}
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
		$this->sim_determine_cb = function ( $user_id ) use ( &$stored_error ) {
			$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
			if ( 0 === strpos( $auth, 'Bearer ' ) ) {
				$stored_error = new WP_Error(
					'jwt_auth_bad_config',
					'[jwt_auth] The Secret Key is not configured.',
					array( 'status' => 403 )
				);
			}
			return $user_id;
		};
		add_filter( 'determine_current_user', $this->sim_determine_cb, 10 );

		// Default priority 10: JWT plugin returns its stored error,
		// which blocks the request before WCPOS gets a chance to clear it.
		$this->sim_auth_errors_cb = function ( $error ) use ( &$stored_error ) {
			if ( ! empty( $error ) ) {
				return $error;
			}
			if ( ! empty( $stored_error ) ) {
				return $stored_error;
			}
			return $error;
		};
		add_filter( 'rest_authentication_errors', $this->sim_auth_errors_cb, 10 );
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
		$jwt_plugin_error = new WP_Error(); // placeholder; will be replaced.

		$this->simulate_conflicting_jwt_plugin( $jwt_plugin_error );

		// Generate a valid WCPOS JWT for the admin user.
		$user         = get_user_by( 'id', $this->user );
		$auth_service = Auth::instance();
		$access_token = $auth_service->generate_access_token( $user );

		// Place the token in $_SERVER so WCPOS's get_auth_header() finds it.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Simulate a fresh unauthenticated request. We must set $current_user to
		// null (not use wp_set_current_user(0)) because wp_set_current_user(0)
		// caches a WP_User(0) object, which causes _wp_get_current_user() to
		// return early and skip the determine_current_user filter entirely.
		// Setting it to null forces WordPress to re-run the filter chain when
		// current_user_can() is first called inside rest_pre_dispatch.
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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

		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'An invalid Bearer token should be rejected with 403 even when a JWT plugin is active'
		);
	}

	/**
	 * Non-JWT auth errors from other plugins must not be cleared just because a
	 * WCPOS Bearer token is valid.
	 *
	 * Note: rest_authentication_errors is only triggered from serve_request(), not
	 * dispatch(). We therefore test it directly via apply_filters() rather than
	 * through a full HTTP dispatch cycle.
	 */
	public function test_non_jwt_auth_errors_are_not_cleared_when_wcpos_auth_succeeds(): void {
		$non_jwt_error = new WP_Error(
			'maintenance_mode_lock',
			'Maintenance lock active.',
			array( 'status' => 403 )
		);

		// Plugin at priority 10 always returns the maintenance error.
		$this->sim_auth_errors_cb = function ( $error ) use ( $non_jwt_error ) {
			if ( ! empty( $error ) ) {
				return $error;
			}
			return $non_jwt_error;
		};
		add_filter( 'rest_authentication_errors', $this->sim_auth_errors_cb, 10 );

		// Generate a valid WCPOS token and place it in the server superglobal.
		$user         = get_user_by( 'id', $this->user );
		$auth_service = Auth::instance();
		$access_token = $auth_service->generate_access_token( $user );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Trigger determine_current_user so WCPOS sets $authenticated_via_wcpos = true
		// on the API instance (simulating what happens earlier in a real request).
		apply_filters( 'determine_current_user', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Now run rest_authentication_errors — the non-JWT error must survive.
		$result = apply_filters( 'rest_authentication_errors', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'Non-JWT auth errors must not be cleared by WCPOS token authentication'
		);
		$this->assertSame(
			'maintenance_mode_lock',
			$result->get_error_code(),
			'The non-JWT maintenance_mode_lock error should be passed through unchanged'
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
		$this->sim_determine_cb = function () use ( $subscriber ) {
			return $subscriber; // JWT plugin validated its own token, returns user_id.
		};
		add_filter( 'determine_current_user', $this->sim_determine_cb, 10 );

		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
