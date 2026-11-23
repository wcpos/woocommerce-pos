<?php
/**
 * Manager-approval credential lockout tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Templates\Auth;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;
use WP_Error;

/** The approval routes share the POS login page's account-and-peer lockout. */
class Test_Approval_Credential_Lockout extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as fixture_tear_down;
	}

	/** The connecting peer every attempt in a test comes from. */
	private const PEER = '203.0.113.10';

	/** Original request globals.
	 *
	 * @var array
	 */
	private $request;

	/** Closure manager whose password the cashier types.
	 *
	 * @var \WP_User
	 */
	private $manager;

	/** Cashier without closure management.
	 *
	 * @var \WP_User
	 */
	private $cashier;

	/** Create a manager and a cashier, and pin the connecting peer. */
	public function setUp(): void {
		parent::setUp();
		$this->request          = array( $_REQUEST, $_SERVER, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test-owned request fixture.
		$_SERVER['REMOTE_ADDR'] = self::PEER;
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
		$this->manager = self::factory()->user->create_and_get(
			array(
				'role'      => 'subscriber',
				'user_pass' => 'manager-pass',
			)
		);
		$this->manager->add_cap( 'manage_woocommerce_pos_closures' );
		$this->cashier = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$this->cashier->add_cap( 'access_woocommerce_pos' );
		$this->cashier->add_cap( 'manage_woocommerce_pos_cash' );
	}

	/** Remove committed lockout transients and restore request globals. */
	public function tearDown(): void {
		$this->fixture_tear_down();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%wcpos\_auth\_%'" );
		$wpdb->query( 'COMMIT' );
		list( $_REQUEST, $_SERVER, $_POST ) = $this->request;
	}

	/** Dispatch a v2 write as the given user.
	 *
	 * @param string $route Resource path.
	 * @param array  $body Request body.
	 */
	private function post( string $route, array $body ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/** A counting session the cashier is closing.
	 *
	 * @return string Session id.
	 */
	private function counting_session(): string {
		return $this->closure_session()['id'];
	}

	/** A closure the cashier can recount with approval.
	 *
	 * @return string Closure id.
	 */
	private function closure(): string {
		$fields = $this->closure_fields( $this->closure_session() );
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			$fields[ $key ] = null === $fields[ $key . '_gmt' ] ? null : str_replace( ' ', 'T', $fields[ $key . '_gmt' ] ) . 'Z';
			unset( $fields[ $key . '_gmt' ] );
		}
		$row = $this->post( 'closures', $fields )->get_data();
		$this->assertArrayHasKey( 'id', $row );
		return $row['id'];
	}

	/** Ask for the close-time variance approval.
	 *
	 * @param string $session_id Session id.
	 * @param string $username Typed approver.
	 * @param string $password Typed password.
	 */
	private function approve( string $session_id, string $username, string $password ) {
		wp_set_current_user( $this->cashier->ID );
		return $this->post(
			'sessions/' . $session_id . '/approve',
			array(
				'username' => $username,
				'password' => $password,
				'counted'  => array( 'cash' => '100' ),
			)
		);
	}

	/** Ask for a closure recount approval.
	 *
	 * @param string $closure_id Closure id.
	 * @param string $username Typed approver.
	 * @param string $password Typed password.
	 */
	private function recount( string $closure_id, string $username, string $password ) {
		wp_set_current_user( $this->cashier->ID );
		return $this->post(
			'closures/' . $closure_id . '/recount',
			array(
				'id'       => wp_generate_uuid4(),
				'counted'  => array( 'cash' => '98' ),
				'reason'   => 'Recount',
				'approval' => array(
					'username' => $username,
					'password' => $password,
				),
			)
		);
	}

	/** Submit a POS login page attempt through the real template.
	 *
	 * @param string $username Typed login.
	 * @param string $password Typed password.
	 */
	private function login( string $username, string $password ): Auth {
		$state                     = wp_generate_uuid4();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST                  = array(
			'redirect_uri' => 'wcpos://',
			'state'        => $state,
		);
		$_POST                     = array(
			'_wpnonce'      => wp_create_nonce( 'wcpos_auth' ),
			'wcpos-log'     => $username,
			'wcpos-pwd'     => $password,
			'wcpos_website' => '',
		);
		return new Auth();
	}

	/** Assert the lockout refusal.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private function assert_locked( $response ): void {
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wcpos_approval_locked', $response->get_data()['code'] );
	}

	/** Five wrong close approvals lock the account on both routes, before the password is checked. */
	public function test_session_approval_five_failures_lock_the_account_on_both_routes(): void {
		$session = $this->counting_session();
		$closure = $this->closure();
		for ( $i = 0; $i < 5; $i++ ) {
			$refused = $this->approve( $session, $this->manager->user_login, 'wrong' );
			$this->assertSame( 403, $refused->get_status() );
			$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		}
		$checked = false;
		add_filter(
			'authenticate',
			static function ( $user ) use ( &$checked ) {
				$checked = true;
				return $user;
			},
			99
		);

		$this->assert_locked( $this->approve( $session, $this->manager->user_login, 'manager-pass' ) );
		$this->assert_locked( $this->recount( $closure, $this->manager->user_login, 'manager-pass' ) );

		$this->assertFalse( $checked );
		$this->assertNull( ( new Register_Session_Store() )->get( $session )['approved_by'] );
	}

	/** Five wrong recount approvals lock the account on both routes. */
	public function test_recount_approval_five_failures_lock_the_account_on_both_routes(): void {
		$session = $this->counting_session();
		$closure = $this->closure();
		for ( $i = 0; $i < 5; $i++ ) {
			$refused = $this->recount( $closure, $this->manager->user_email, 'wrong' );
			$this->assertSame( 403, $refused->get_status() );
			$this->assertSame( 'wcpos_recount_approval_invalid', $refused->get_data()['code'] );
		}

		$this->assert_locked( $this->recount( $closure, $this->manager->user_login, 'manager-pass' ) );
		$this->assert_locked( $this->approve( $session, $this->manager->user_login, 'manager-pass' ) );
	}

	/** Spellings wp_authenticate() resolves to the same account share its bucket. */
	public function test_approval_lockout_buckets_the_identifier_as_wp_authenticate_resolves_it(): void {
		$session = $this->counting_session();
		$closure = $this->closure();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 403, $this->approve( $session, $this->manager->user_email . str_repeat( '<b></b>', $i ), 'wrong' )->get_status() );
		}
		// One sanitize_user() pass leaves `%41`, which the login lookup's own pass then strips.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertSame( 403, $this->recount( $closure, str_repeat( '%%4141', $i + 1 ) . $this->manager->user_login, 'wrong' )->get_status() );
		}

		$this->assert_locked( $this->approve( $session, $this->manager->user_email . '<i></i>', 'manager-pass' ) );
		$this->assert_locked( $this->recount( $closure, '%%4141' . $this->manager->user_login, 'manager-pass' ) );
		$this->assert_locked( $this->approve( $session, $this->manager->user_login, 'manager-pass' ) );
	}

	/** An unknown name locks exactly as a known one, so the refusal names no account. */
	public function test_approval_lockout_does_not_reveal_whether_the_username_exists(): void {
		$session = $this->counting_session();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->approve( $session, $this->manager->user_login, 'wrong' );
			$this->assertSame( 403, $this->approve( $session, 'no-such-manager', 'wrong' )->get_status() );
		}

		$known   = $this->approve( $session, $this->manager->user_login, 'wrong' );
		$unknown = $this->approve( $session, 'no-such-manager', 'wrong' );

		$this->assert_locked( $known );
		$this->assertSame( $known->get_data(), $unknown->get_data() );
	}

	/** An approval before the limit clears the failures, as a login does. */
	public function test_approval_success_before_the_limit_clears_the_counter(): void {
		$closure = $this->closure();
		for ( $round = 0; $round < 2; $round++ ) {
			for ( $i = 0; $i < 4; $i++ ) {
				$this->assertSame( 403, $this->recount( $closure, $this->manager->user_login, 'wrong' )->get_status() );
			}

			$response = $this->recount( $closure, $this->manager->user_login, 'manager-pass' );

			$this->assertSame( 200, $response->get_status() );
		}
	}

	/** Overlapping wrong approvals all in flight at once get no more password checks than the limit. */
	public function test_overlapping_wrong_approvals_get_no_more_password_checks_than_the_limit(): void {
		$session     = $this->counting_session();
		$checks      = 0;
		$overlapping = 5;
		$responses   = array();
		// Each password check starts the next request before it answers, so no failure is recorded until every request is in flight.
		add_filter(
			'authenticate',
			function ( $user ) use ( &$checks, &$overlapping, &$responses, $session ) {
				++$checks;
				if ( $overlapping-- > 0 ) {
					$responses[] = $this->approve( $session, $this->manager->user_login, 'wrong' );
				}
				return $user;
			},
			99
		);

		$responses[] = $this->approve( $session, $this->manager->user_login, 'wrong' );

		$this->assertSame( 5, $checks );
		$this->assert_locked( array_shift( $responses ) );
		foreach ( $responses as $refused ) {
			$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		}
		$this->assert_locked( $this->approve( $session, $this->manager->user_login, 'manager-pass' ) );
	}

	/** A right password for an account that may not approve is not a failed attempt. */
	public function test_approval_right_password_without_the_capability_does_not_count_toward_the_lock(): void {
		$closure = $this->closure();
		$other   = self::factory()->user->create_and_get( array( 'user_pass' => 'other-pass' ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 403, $this->recount( $closure, $other->user_login, 'wrong' )->get_status() );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'wcpos_recount_approval_forbidden', $this->recount( $closure, $other->user_login, 'other-pass' )->get_data()['code'] );
		}

		$this->assertSame( 'wcpos_recount_approval_invalid', $this->recount( $closure, $other->user_login, 'wrong' )->get_data()['code'] );
		$this->assert_locked( $this->recount( $closure, $other->user_login, 'other-pass' ) );
	}

	/** A right password refused for the capability leaves the failures' 15-minute window where it was. */
	public function test_approval_right_password_without_the_capability_does_not_extend_the_window(): void {
		global $wpdb;
		$closure = $this->closure();
		$other   = self::factory()->user->create_and_get( array( 'user_pass' => 'other-pass' ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->recount( $closure, $other->user_login, 'wrong' );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT('4|', %d, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name LIKE %s", time() + 600, $wpdb->esc_like( 'wcpos_auth_attempts_' ) . '%' ) );
		$before = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wcpos\_auth\_attempts\_%'" );

		$this->assertSame( 'wcpos_recount_approval_forbidden', $this->recount( $closure, $other->user_login, 'other-pass' )->get_data()['code'] );

		$this->assertCount( 1, $before );
		$this->assertSame( $before, $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wcpos\_auth\_attempts\_%'" ) );
	}

	/** A window about to end stays open while a counted attempt's password is being checked. */
	public function test_approval_attempt_in_flight_keeps_its_window_open(): void {
		global $wpdb;
		$closure = $this->closure();
		for ( $i = 0; $i < 4; $i++ ) {
			$this->recount( $closure, $this->manager->user_login, 'wrong' );
		}
		$ending = time() + 1;
		$like   = $wpdb->esc_like( 'wcpos_auth_attempts_' ) . '%';
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT('4|', %d, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name LIKE %s", $ending, $like ) );
		$during = null;
		add_filter(
			'authenticate',
			static function ( $user ) use ( &$during, $like ) {
				global $wpdb;
				$during = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
				return $user;
			},
			99
		);

		$this->recount( $closure, $this->manager->user_login, 'wrong' );

		list( $count, $expires ) = explode( '|', $during );
		$this->assertSame( '5', $count );
		$this->assertGreaterThanOrEqual( $ending + 50, (int) $expires );
	}

	/** An attempt the database could not count is refused before the password is checked. */
	public function test_approval_attempt_that_cannot_be_counted_is_refused(): void {
		$session = $this->counting_session();
		$checked = false;
		add_filter(
			'authenticate',
			static function ( $user ) use ( &$checked ) {
				$checked = true;
				return $user;
			},
			99
		);
		add_filter(
			'query',
			static function ( $query ) {
				return 0 === strpos( $query, 'INSERT' ) && false !== strpos( $query, 'wcpos_auth_attempts_' ) ? 'SELECT * FROM wcpos_no_such_table' : $query;
			}
		);

		global $wpdb;
		$suppress = $wpdb->suppress_errors();

		$response = $this->approve( $session, $this->manager->user_login, 'manager-pass' );

		$wpdb->suppress_errors( $suppress );
		$this->assert_locked( $response );
		$this->assertFalse( $checked );
	}

	/** Attempts older than the 15-minute window no longer count. */
	public function test_approval_attempts_past_the_window_start_a_new_count(): void {
		global $wpdb;
		$closure = $this->closure();
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 403, $this->recount( $closure, $this->manager->user_login, 'wrong' )->get_status() );
		}
		// Age the window: the stored count keeps its value, its expiry moves into the past.
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = CONCAT( SUBSTRING_INDEX( option_value, '|', 1 ), '|1|', SUBSTRING_INDEX( option_value, '|', -1 ) ) WHERE option_name LIKE 'wcpos\_auth\_attempts\_%'" );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 403, $this->recount( $closure, $this->manager->user_login, 'wrong' )->get_status() );
		}

		$this->assertSame( 200, $this->recount( $closure, $this->manager->user_login, 'manager-pass' )->get_status() );
	}

	/** Approval and login failures for one account and peer fill one lockout that holds on all three. */
	public function test_approval_and_login_failures_share_one_lockout(): void {
		$session = $this->counting_session();
		add_filter(
			'wcpos_authenticate_user',
			static function () {
				return new WP_Error( 'test_sentinel', 'sentinel' );
			}
		);
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 403, $this->approve( $session, $this->manager->user_login, 'wrong' )->get_status() );
		}
		$this->assertSame( 'Invalid username or password.', $this->login( $this->manager->user_login, 'wrong' )->get_error() );

		$login = $this->login( $this->manager->user_login, 'manager-pass' );

		$this->assertSame( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', $login->get_error() );
		$this->assert_locked( $this->approve( $session, $this->manager->user_login, 'manager-pass' ) );
	}
}
