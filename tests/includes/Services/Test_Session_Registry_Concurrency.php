<?php
/**
 * Session registry writes that overlap.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Session_Registry;
use WP_UnitTestCase;

/**
 * Two requests that write one user's session row at the same time must not lose each other's change.
 *
 * Each test makes the overlap deterministic: the outer request has read the row and is about to
 * save it when the inner request runs to completion. Two PHP workers produce that order when two
 * devices sign in to the same account within a few milliseconds of each other.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Session_Registry_Concurrency extends WP_UnitTestCase {
	/**
	 * Auth service under test.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * The shared till account.
	 *
	 * @var \WP_User
	 */
	private $user;

	/**
	 * Set up the shared account.
	 */
	public function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		$this->auth = Auth::instance();
		$this->user = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
	}

	/**
	 * The overlap hook on `query`, while one is armed.
	 *
	 * @var callable|null
	 */
	private $overlap_hook;

	/**
	 * Remove the overlap hook if a test failed before it fired.
	 *
	 * Only this closure: the test case keeps its own `query` filters for temporary tables.
	 */
	public function tearDown(): void {
		if ( $this->overlap_hook ) {
			remove_filter( 'query', $this->overlap_hook );
			$this->overlap_hook = null;
		}
		parent::tearDown();
	}

	/**
	 * Two devices sign in to one account at once: both stay signed in.
	 */
	public function test_session_row_two_overlapping_logins_keeps_both_sessions(): void {
		// Arrange.
		$existing = $this->login();
		$inner    = null;
		$this->run_inside_next_row_write(
			function () use ( &$inner ) {
				$inner = $this->login();
			}
		);

		// Act.
		$outer = $this->login();

		// Assert.
		$this->assertNotWPError( $this->auth->validate_token( $existing['access_token'], 'access' ) );
		$this->assertNotWPError( $this->auth->validate_token( $outer['access_token'], 'access' ) );
		$this->assertNotWPError( $this->auth->validate_token( $inner['access_token'], 'access' ) );
	}

	/**
	 * One device refreshes its access token while another signs in: the new sign-in survives.
	 */
	public function test_session_row_refresh_overlapping_a_login_keeps_the_new_session(): void {
		// Arrange.
		$existing = $this->login();
		$this->age_session( $existing['refresh_token'] );
		$inner = null;
		$this->run_inside_next_row_write(
			function () use ( &$inner ) {
				$inner = $this->login();
			}
		);

		// Act.
		$refreshed = $this->auth->refresh_access_token( $existing['refresh_token'] );

		// Assert.
		$this->assertNotWPError( $refreshed );
		$this->assertNotWPError( $this->auth->validate_token( $inner['access_token'], 'access' ) );
	}

	/**
	 * A device signs out while another signs in: the signed-out session stays revoked.
	 */
	public function test_session_row_login_overlapping_a_sign_out_keeps_the_session_revoked(): void {
		// Arrange.
		$signing_out = $this->login();
		$jti         = $this->refresh_jti( $signing_out['refresh_token'] );
		$this->run_inside_next_row_write(
			function () use ( $jti ) {
				$this->assertTrue( $this->auth->revoke_refresh_token( $this->user->ID, $jti ) );
			}
		);

		// Act.
		$this->login();

		// Assert.
		$this->assertWPError( $this->auth->refresh_access_token( $signing_out['refresh_token'] ) );
	}

	/**
	 * Sign the shared account in, as a POS device does.
	 *
	 * @return array The token pair.
	 */
	private function login(): array {
		$pair = $this->auth->generate_token_pair( $this->user );
		$this->assertIsArray( $pair );

		return $pair;
	}

	/**
	 * The session id inside a refresh token.
	 *
	 * @param string $refresh_token Refresh token.
	 */
	private function refresh_jti( string $refresh_token ): string {
		$decoded = $this->auth->validate_token( $refresh_token, 'refresh' );
		$this->assertNotWPError( $decoded );

		return (string) $decoded->jti;
	}

	/**
	 * Move a session's activity and access expiry an hour back, as for a device that signed in earlier.
	 *
	 * A refresh in the same second as the sign-in would write an identical row, which WordPress skips.
	 *
	 * @param string $refresh_token The session's refresh token.
	 */
	private function age_session( string $refresh_token ): void {
		$jti  = $this->refresh_jti( $refresh_token );
		$rows = get_user_meta( $this->user->ID, Session_Registry::META_KEY, true );
		$this->assertArrayHasKey( $jti, $rows );
		$rows[ $jti ]['last_active']    = time() - HOUR_IN_SECONDS;
		$rows[ $jti ]['access_expires'] = time() - MINUTE_IN_SECONDS;
		$this->assertTrue( update_user_meta( $this->user->ID, Session_Registry::META_KEY, $rows ) );
	}

	/**
	 * Run another request to completion between the next session-row read and its save.
	 *
	 * The hook fires once, just before the database receives the next UPDATE or INSERT of the
	 * session row, after the saving request has built the value it is about to write from its own
	 * earlier read. It sits on `query` rather than a meta filter so it sees the write whichever
	 * API the registry uses to make it; the overlapping request's own writes pass through.
	 *
	 * @param callable $other_request The overlapping request.
	 */
	private function run_inside_next_row_write( callable $other_request ): void {
		global $wpdb;

		$fired   = false;
		$pattern = '/^\s*(UPDATE|INSERT\s+INTO)\s+`?' . preg_quote( $wpdb->usermeta, '/' ) . '`?\s/i';

		$this->overlap_hook = static function ( $query ) use ( &$fired, $other_request, $pattern ) {
			if ( ! $fired && preg_match( $pattern, $query ) && false !== strpos( $query, Session_Registry::META_KEY ) ) {
				$fired = true;
				$other_request();
			}

			return $query;
		};
		add_filter( 'query', $this->overlap_hook );
	}
}
