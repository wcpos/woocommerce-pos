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
	 * The request's user agent before the test, restored in tearDown.
	 *
	 * @var string|null
	 */
	private $user_agent_before;

	/**
	 * Set up the shared account.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->user_agent_before = $_SERVER['HTTP_USER_AGENT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the exact global value for restoration.
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
		if ( null === $this->user_agent_before ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->user_agent_before;
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
	 * Two devices sign in to an account that has no session row yet: both stay signed in, in one row.
	 */
	public function test_session_row_two_overlapping_first_logins_keep_both_sessions_in_one_row(): void {
		global $wpdb;

		// Arrange.
		$inner = null;
		$this->run_inside_next_row_write(
			function () use ( &$inner ) {
				$inner = $this->login();
			}
		);

		// Act.
		$outer = $this->login();

		// Assert.
		$this->assertNotWPError( $this->auth->validate_token( $inner['access_token'], 'access' ) );
		$this->assertNotWPError( $this->auth->validate_token( $outer['access_token'], 'access' ) );
		$this->assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
					$this->user->ID,
					Session_Registry::META_KEY
				)
			)
		);
	}

	/**
	 * A concurrent change that differs from the saving request's copy only in letter case is not overwritten.
	 */
	public function test_session_row_concurrent_change_differing_only_in_case_survives(): void {
		global $wpdb;

		// Arrange.
		$_SERVER['HTTP_USER_AGENT'] = 'lowercase-agent';
		$first                      = $this->login();
		$first_jti                  = $this->refresh_jti( $first['refresh_token'] );
		$this->run_inside_next_row_write(
			function () use ( $wpdb ) {
				$row = $this->stored_row();
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d",
						str_replace( 'lowercase-agent', 'LOWERCASE-AGENT', $row->meta_value ),
						$row->umeta_id
					)
				);
			}
		);

		// Act.
		$second = $this->login();

		// Assert.
		$entries = $this->auth->sessions()->entries( $this->user->ID );
		$this->assertSame( 'LOWERCASE-AGENT', $entries[ $first_jti ]['user_agent'] );
		$this->assertArrayHasKey( $this->refresh_jti( $second['refresh_token'] ), $entries );
	}

	/**
	 * A write builds on the row in the database, not on a stale copy in the object cache.
	 */
	public function test_session_row_write_reads_the_database_not_a_stale_cache(): void {
		// Arrange.
		$first      = $this->login();
		$second     = $this->login();
		$first_jti  = $this->refresh_jti( $first['refresh_token'] );
		$second_jti = $this->refresh_jti( $second['refresh_token'] );
		$entries    = $this->auth->sessions()->entries( $this->user->ID );
		$this->assertArrayHasKey( $second_jti, $entries );
		update_meta_cache( 'user', array( $this->user->ID ) );
		$cache                               = wp_cache_get( $this->user->ID, 'user_meta' );
		$cache[ Session_Registry::META_KEY ] = array( maybe_serialize( array( $first_jti => $entries[ $first_jti ] ) ) );
		wp_cache_set( $this->user->ID, $cache, 'user_meta' );

		// Act.
		$third = $this->login();

		// Assert.
		wp_cache_delete( $this->user->ID, 'user_meta' );
		$entries = $this->auth->sessions()->entries( $this->user->ID );
		$this->assertArrayHasKey( $first_jti, $entries );
		$this->assertArrayHasKey( $second_jti, $entries );
		$this->assertArrayHasKey( $this->refresh_jti( $third['refresh_token'] ), $entries );
	}

	/**
	 * A row that changes before every save makes the write give up after a bounded number of attempts.
	 */
	public function test_session_row_write_gives_up_after_bounded_attempts_and_returns_false(): void {
		global $wpdb;

		// Arrange.
		$session  = $this->login();
		$jti      = $this->refresh_jti( $session['refresh_token'] );
		$attempts = ( new \ReflectionClass( Session_Registry::class ) )->getConstant( 'MAX_ROW_WRITE_ATTEMPTS' );
		$updates  = $this->count_row_updates(
			function ( int $seen ) use ( $wpdb, $jti ) {
				$row                         = $this->stored_row();
				$value                       = maybe_unserialize( $row->meta_value );
				$value[ $jti ]['last_active'] = 1000 + $seen;
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d",
						maybe_serialize( $value ),
						$row->umeta_id
					)
				);
			}
		);

		// Act.
		$revoked = $this->auth->revoke_refresh_token( $this->user->ID, $jti );

		// Assert.
		$this->assertFalse( $revoked );
		$this->assertSame( $attempts, $updates->count );
		wp_cache_delete( $this->user->ID, 'user_meta' );
		$this->assertArrayHasKey( $jti, $this->auth->sessions()->entries( $this->user->ID ) );
	}

	/**
	 * Keeping only the current session when it is already the only one succeeds without writing.
	 */
	public function test_session_row_unchanged_write_returns_true_without_an_update(): void {
		// Arrange.
		$session = $this->login();
		$jti     = $this->refresh_jti( $session['refresh_token'] );
		$updates = $this->count_row_updates( null );

		// Act.
		$kept = $this->auth->revoke_all_sessions_except( $this->user->ID, $jti );

		// Assert.
		$this->assertTrue( $kept );
		$this->assertSame( 0, $updates->count );
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

	/**
	 * Count the UPDATEs of the session row that reach the database, running a callback just before each.
	 *
	 * Queries the callback itself makes are neither counted nor intercepted.
	 *
	 * @param callable|null $before_update Receives the number of UPDATEs seen so far, including this one.
	 *
	 * @return \stdClass Its `count` property holds the number of UPDATEs seen.
	 */
	private function count_row_updates( ?callable $before_update ): \stdClass {
		global $wpdb;

		$updates        = new \stdClass();
		$updates->count = 0;
		$busy           = false;
		$pattern        = '/^\s*UPDATE\s+`?' . preg_quote( $wpdb->usermeta, '/' ) . '`?\s/i';

		$this->overlap_hook = static function ( $query ) use ( $updates, &$busy, $before_update, $pattern ) {
			if ( ! $busy && preg_match( $pattern, $query ) && false !== strpos( $query, Session_Registry::META_KEY ) ) {
				++$updates->count;
				if ( $before_update ) {
					$busy = true;
					$before_update( $updates->count );
					$busy = false;
				}
			}

			return $query;
		};
		add_filter( 'query', $this->overlap_hook );

		return $updates;
	}

	/**
	 * The user's session row as the database holds it.
	 *
	 * @return \stdClass The row's `umeta_id` and `meta_value`.
	 */
	private function stored_row(): \stdClass {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1",
				$this->user->ID,
				Session_Registry::META_KEY
			)
		);
		$this->assertIsObject( $row );

		return $row;
	}
}
