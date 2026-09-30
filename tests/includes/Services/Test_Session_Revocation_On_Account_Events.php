<?php
/**
 * Account events end POS sessions on the server.
 *
 * Logging out of WordPress ends the web POS session of that browser only; a password
 * reset or a password change ends every POS session of the user. A profile update that
 * leaves the password alone ends nothing.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_Error;
use WP_UnitTestCase;

/**
 * Session revocation on logout, password reset and password change.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Session_Revocation_On_Account_Events extends WP_UnitTestCase {
	/**
	 * Auth service under test.
	 *
	 * @var Auth
	 */
	private $auth_service;

	/**
	 * Test user. Never made the current user: a password change for the current
	 * user makes core re-send auth cookies, which fails under PHPUnit.
	 *
	 * @var \WP_User
	 */
	private $user;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		unset( $_COOKIE['wcpos_web_session_jti'] );
		$this->auth_service = Auth::instance();
		$this->user         = $this->factory->user->create_and_get( array( 'role' => 'shop_manager' ) );
	}

	/**
	 * Tear down: never leak the web session cookie into later tests.
	 */
	public function tearDown(): void {
		unset( $_COOKIE['wcpos_web_session_jti'] );
		parent::tearDown();
	}

	/**
	 * Mint a linked token pair and return it with the session JTI.
	 *
	 * @return array{0: array, 1: string}
	 */
	private function login(): array {
		$tokens  = $this->auth_service->generate_token_pair( $this->user );
		$refresh = $this->auth_service->validate_token( $tokens['refresh_token'], 'refresh' );

		return array( $tokens, (string) $refresh->jti );
	}

	/**
	 * Assert the session behind a token pair is revoked for both token types.
	 *
	 * @param array $tokens Token pair from generate_token_pair().
	 */
	private function assert_session_revoked( array $tokens ): void {
		$access = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$this->assertInstanceOf( WP_Error::class, $access );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $access->get_error_code() );

		$refresh = $this->auth_service->refresh_access_token( $tokens['refresh_token'] );
		$this->assertInstanceOf( WP_Error::class, $refresh );
		$this->assertSame( 'woocommerce_pos_auth_refresh_token_revoked', $refresh->get_error_code() );
	}

	/**
	 * Assert the session behind a token pair still accepts its access token.
	 *
	 * @param array $tokens Token pair from generate_token_pair().
	 */
	private function assert_session_live( array $tokens ): void {
		$access = $this->auth_service->validate_token( $tokens['access_token'], 'access' );
		$this->assertNotInstanceOf( WP_Error::class, $access );
	}

	/**
	 * Logging out ends the session named by this browser's cookie and no other.
	 */
	public function test_logout_revokes_the_browsers_web_session_only(): void {
		// Arrange.
		list( $web_tokens, $web_jti ) = $this->login();
		list( $app_tokens )           = $this->login();
		$_COOKIE['wcpos_web_session_jti'] = $web_jti;

		// Act.
		do_action( 'wp_logout', $this->user->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook under test.

		// Assert.
		$this->assert_session_revoked( $web_tokens );
		$this->assert_session_live( $app_tokens );
	}

	/**
	 * A password reset ends every session of the user.
	 */
	public function test_password_reset_revokes_every_session(): void {
		// Arrange.
		list( $web_tokens ) = $this->login();
		list( $app_tokens ) = $this->login();

		// Act.
		reset_password( $this->user, 'new-' . wp_generate_password( 12, false ) );

		// Assert.
		$this->assert_session_revoked( $web_tokens );
		$this->assert_session_revoked( $app_tokens );
	}

	/**
	 * A password change ends every session of the user.
	 */
	public function test_password_change_revokes_every_session(): void {
		// Arrange.
		list( $web_tokens ) = $this->login();
		list( $app_tokens ) = $this->login();

		// Act.
		wp_update_user(
			array(
				'ID'        => $this->user->ID,
				'user_pass' => 'new-' . wp_generate_password( 12, false ),
			)
		);

		// Assert.
		$this->assert_session_revoked( $web_tokens );
		$this->assert_session_revoked( $app_tokens );
	}

	/**
	 * A profile update that leaves the password alone keeps the sessions.
	 */
	public function test_profile_update_without_password_change_keeps_sessions(): void {
		// Arrange.
		list( $tokens ) = $this->login();

		// Act.
		wp_update_user(
			array(
				'ID'           => $this->user->ID,
				'display_name' => 'Renamed',
			)
		);

		// Assert.
		$this->assert_session_live( $tokens );
	}

	/**
	 * Logging out without a web session cookie ends nothing.
	 */
	public function test_logout_without_web_session_cookie_keeps_sessions(): void {
		// Arrange.
		list( $tokens ) = $this->login();

		// Act.
		do_action( 'wp_logout', $this->user->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook under test.

		// Assert.
		$this->assert_session_live( $tokens );
	}
}
