<?php
/**
 * Auth account lockout and connecting-peer rate limit tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Services\Credential_Lockout;
use WCPOS\WooCommercePOS\Templates\Auth;
use WP_Error;
use WP_UnitTestCase;

/**
 * Login throttles share account aliases but isolate connecting peers.
 */
class Test_Auth_Rate_Limits extends WP_UnitTestCase {

	/**
	 * Original request globals.
	 *
	 * @var array
	 */
	private $request;

	/**
	 * Auth session state shared by attempts within a test.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Whether a correct password reached the authentication filter.
	 *
	 * @var bool
	 */
	private $sentinel_reached = false;

	/**
	 * Set up a cashier and prevent successful authentication from redirecting.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request          = array( $_REQUEST, $_SERVER, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test-owned request fixture.
		$this->state            = wp_generate_uuid4();
		$this->sentinel_reached = false;
		self::factory()->user->create_and_get(
			array(
				'user_login' => 'till-cashier',
				'user_email' => 'till-cashier@example.com',
				'user_pass'  => 'correct horse',
				'role'       => 'cashier',
			)
		);
		add_filter( 'wcpos_authenticate_user', array( $this, 'authentication_sentinel' ), 10 );
	}

	/**
	 * Restore request state and remove the test authentication filter.
	 */
	public function tearDown(): void {
		remove_filter( 'wcpos_authenticate_user', array( $this, 'authentication_sentinel' ), 10 );
		delete_transient( 'wcpos_auth_session_' . md5( $this->state . 'wcpos://' ) );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		list( $_REQUEST, $_SERVER, $_POST ) = $this->request;
		parent::tearDown();
	}

	/**
	 * Record password validation without reaching the redirect and exit.
	 *
	 * @return WP_Error Sentinel authentication error.
	 */
	public function authentication_sentinel(): WP_Error {
		$this->sentinel_reached = true;

		return new WP_Error( 'test_sentinel', 'sentinel' );
	}

	/**
	 * Submit a login attempt through the real auth template constructor.
	 *
	 * @param string $peer       Connecting address, or empty to omit REMOTE_ADDR.
	 * @param string $identifier Login or email as typed.
	 * @param string $password   Password as typed.
	 *
	 * @return Auth Authentication outcome.
	 */
	private function attempt( string $peer, string $identifier, string $password ): Auth {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = $peer;
		if ( '' === $peer ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
		$_REQUEST = array(
			'redirect_uri' => 'wcpos://',
			'state'        => $this->state,
		);
		$_POST = array(
			'_wpnonce'      => wp_create_nonce( 'wcpos_auth' ),
			'wcpos-log'     => $identifier,
			'wcpos-pwd'     => $password,
			'wcpos_website' => '',
		);

		return new Auth();
	}

	/**
	 * End the windows of the matching counter rows, keeping everything after the expiry.
	 *
	 * @param string $like Option name pattern, already escaped for LIKE.
	 */
	private function end_windows( string $like ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', %d, SUBSTRING(option_value, LENGTH(SUBSTRING_INDEX(option_value, '|', 2)) + 1)) WHERE option_name LIKE %s", time() - 1, $like ) );
	}

	/**
	 * A counter row's stored value.
	 *
	 * @param string $bucket The bucket key.
	 */
	private function row( string $bucket ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wcpos_auth_attempts_' . $bucket ) );
	}

	/**
	 * A stranger cannot lock the cashier out at another peer.
	 */
	public function test_lockout_five_failures_from_another_peer_do_not_lock_the_cashier_at_their_own_peer(): void {
		// Arrange.
		for ( $i = 0; $i < 5; $i++ ) {
			$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}

		// Act.
		$auth = $this->attempt( '198.51.100.20', 'till-cashier', 'correct horse' );

		// Assert.
		$this->assertTrue( $this->sentinel_reached );
		$this->assertSame( 'sentinel', $auth->get_error() );
	}

	/**
	 * The fifth failure locks the same peer before password validation.
	 */
	public function test_lockout_five_failures_from_the_same_peer_lock_that_peer(): void {
		// Arrange.
		for ( $i = 0; $i < 5; $i++ ) {
			$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}

		// Act.
		$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'correct horse' );

		// Assert.
		$this->assertSame( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', $auth->get_error() );
		$this->assertFalse( $this->sentinel_reached );
	}

	/**
	 * Login, email and login case variants consume the same account budget.
	 */
	public function test_lockout_login_and_email_aliases_share_one_bucket(): void {
		// Arrange.
		for ( $i = 0; $i < 5; $i++ ) {
			$identifier = $i < 3 ? 'till-cashier' : 'till-cashier@example.com';
			$auth       = $this->attempt( '203.0.113.10', $identifier, 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}

		// Act.
		$auth = $this->attempt( '203.0.113.10', 'TILL-CASHIER', 'correct horse' );

		// Assert.
		$this->assertSame( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', $auth->get_error() );
		$this->assertFalse( $this->sentinel_reached );
	}

	/**
	 * Rotating forwarded headers cannot reset the connecting peer's budget.
	 */
	public function test_rate_limit_forwarded_headers_do_not_select_the_bucket(): void {
		// Arrange.
		for ( $i = 0; $i < 10; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR']  = '10.0.0.' . $i;
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '10.0.0.' . $i;
			$auth = $this->attempt( '203.0.113.10', 'ghost-' . $i, 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}

		// Act.
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '10.0.0.10';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '10.0.0.10';
		$auth = $this->attempt( '203.0.113.10', 'ghost-10', 'wrong' );

		// Assert.
		$this->assertSame( 'Too many requests. Please try again later.', $auth->get_error() );
	}

	/**
	 * A lock at one peer leaves the same account open at another peer.
	 */
	public function test_lockout_at_one_peer_leaves_the_account_open_at_another_peer(): void {
		// Arrange.
		for ( $i = 0; $i < 5; $i++ ) {
			$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}
		$auth = $this->attempt( '198.51.100.20', 'till-cashier', 'wrong' );
		$this->assertSame( 'Invalid username or password.', $auth->get_error() );

		// Act / Assert: peer A remains locked before password validation.
		$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'correct horse' );
		$this->assertSame( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', $auth->get_error() );
		$this->assertFalse( $this->sentinel_reached );

		// Act / Assert: peer B can validate its password without redirecting.
		$auth = $this->attempt( '198.51.100.20', 'till-cashier', 'correct horse' );
		$this->assertTrue( $this->sentinel_reached );
		$this->assertSame( 'sentinel', $auth->get_error() );
	}

	/**
	 * Missing connecting addresses share the unknown peer's rate limit.
	 */
	public function test_rate_limit_unknown_peer_uses_the_unknown_bucket(): void {
		// Arrange.
		for ( $i = 0; $i < 10; $i++ ) {
			$auth = $this->attempt( '', 'ghost-' . $i, 'wrong' );
			$this->assertSame( 'Invalid username or password.', $auth->get_error() );
		}

		// Act.
		$auth = $this->attempt( '', 'ghost-10', 'wrong' );

		// Assert.
		$this->assertSame( 'Too many requests. Please try again later.', $auth->get_error() );
	}

	/**
	 * Wrong passwords in flight at once from one peer get no more password checks than its limit.
	 */
	public function test_rate_limit_overlapping_wrong_passwords_get_no_more_checks_than_the_limit(): void {
		// Arrange: eleven accounts, so no account reaches its own lockout.
		$logins = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$logins[] = self::factory()->user->create_and_get( array( 'user_pass' => 'right-' . $i ) )->user_login;
		}
		$started   = 1;
		$checks    = 0;
		$responses = array();
		// Each password check starts the next request before it answers, so no failure is recorded until every request is in flight.
		add_filter(
			'check_password',
			function ( $check ) use ( &$started, &$checks, &$responses, $logins ) {
				++$checks;
				if ( $started < \count( $logins ) ) {
					$login       = $logins[ $started++ ];
					$responses[] = $this->attempt( '203.0.113.10', $login, 'wrong' )->get_error();
				}
				return $check;
			}
		);

		// Act.
		$responses[] = $this->attempt( '203.0.113.10', $logins[0], 'wrong' )->get_error();

		// Assert.
		$this->assertSame( 10, $checks );
		$counts = array_count_values( $responses );
		ksort( $counts );
		$this->assertSame(
			array(
				'Invalid username or password.'              => 10,
				'Too many requests. Please try again later.' => 1,
			),
			$counts
		);
		$this->assertSame( 'Too many requests. Please try again later.', $this->attempt( '203.0.113.10', 'ghost', 'wrong' )->get_error() );
	}

	/**
	 * Right passwords, with or without POS access, leave the peer's budget for wrong ones untouched.
	 */
	public function test_rate_limit_right_passwords_do_not_count_toward_the_peer_limit(): void {
		// Arrange.
		remove_filter( 'wcpos_authenticate_user', array( $this, 'authentication_sentinel' ), 10 );
		self::factory()->user->create(
			array(
				'user_login' => 'shopper',
				'user_pass'  => 'shopper pass',
				'role'       => 'subscriber',
			)
		);
		add_filter(
			'wp_redirect',
			static function () {
				throw new \DomainException( 'redirected' );
			}
		);
		for ( $i = 0; $i < 11; $i++ ) {
			try {
				$this->attempt( '203.0.113.10', 'till-cashier', 'correct horse' );
				$this->fail( 'A right password should redirect.' );
			} catch ( \DomainException $redirected ) {
				$this->assertSame( 'redirected', $redirected->getMessage() );
			}
			$auth = $this->attempt( '203.0.113.10', 'shopper', 'shopper pass' );
			$this->assertNotSame( 'Too many requests. Please try again later.', $auth->get_error() );
		}

		// Act / Assert: the peer still has its whole budget of ten wrong passwords.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 'Invalid username or password.', $this->attempt( '203.0.113.10', 'ghost-' . $i, 'wrong' )->get_error() );
		}
		$this->assertSame( 'Too many requests. Please try again later.', $this->attempt( '203.0.113.10', 'ghost-10', 'wrong' )->get_error() );
	}

	/**
	 * A peer's failures older than the 15-minute window no longer count.
	 */
	public function test_rate_limit_window_past_its_end_starts_a_new_count(): void {
		// Arrange.
		global $wpdb;
		for ( $i = 0; $i < 10; $i++ ) {
			$this->attempt( '203.0.113.10', 'ghost-' . $i, 'wrong' );
		}
		$this->assertSame( 'Too many requests. Please try again later.', $this->attempt( '203.0.113.10', 'ghost-10', 'wrong' )->get_error() );
		$this->end_windows( $wpdb->esc_like( 'wcpos_auth_attempts_' ) . '%' );

		// Act.
		$auth = $this->attempt( '203.0.113.10', 'ghost-11', 'wrong' );

		// Assert.
		$this->assertSame( 'Invalid username or password.', $auth->get_error() );
	}

	/**
	 * A refund between two attempts' writes and their read-backs cannot admit both past the limit.
	 */
	public function test_rate_limit_refund_between_write_and_read_admits_no_extra_attempt(): void {
		// Arrange: nine failures, then A takes the tenth slot (its account turns out to be locked).
		$bucket = md5( 'refund-race' );
		for ( $i = 0; $i < 10; $i++ ) {
			$a = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
			$this->assertNotNull( $a );
		}
		$c    = null;
		$step = 0;
		// B's read starts C; C's read runs after A gives its slot back.
		add_filter(
			'query',
			static function ( $query ) use ( &$c, &$step, $bucket, $a ) {
				if ( 0 === strpos( $query, 'SELECT option_value' ) && false !== strpos( $query, 'wcpos_auth_attempts_' . $bucket ) ) {
					++$step;
					if ( 1 === $step ) {
						$c = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
					} elseif ( 2 === $step ) {
						Credential_Lockout::release_bucket_attempt( $bucket, $a );
					}
				}
				return $query;
			}
		);

		// Act.
		$b = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

		// Assert: with A's slot back, at most one of B and C may check a password.
		$this->assertLessThanOrEqual( 1, (int) ( null !== $b ) + (int) ( null !== $c ) );
	}

	/**
	 * Make the next $conflicts reservation writes on a bucket lose to another request's count.
	 *
	 * @param string $bucket    The bucket key.
	 * @param int    $conflicts How many writes lose.
	 */
	private function lose_writes( string $bucket, int $conflicts ): void {
		add_filter(
			'query',
			static function ( $query ) use ( &$conflicts, $bucket ) {
				global $wpdb;
				if ( $conflicts > 0 && 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, "= 'wcpos_auth_attempts_{$bucket}' AND option_value = '" ) ) {
					--$conflicts;
					// Another request counts between this reservation's read and its write.
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', SUBSTRING_INDEX(option_value, '|', -2)) WHERE option_name = %s", 'wcpos_auth_attempts_' . $bucket ) );
				}
				return $query;
			}
		);
	}

	/**
	 * A reservation whose write loses to another request's reads the row again and counts once.
	 */
	public function test_rate_limit_reservation_that_loses_a_write_counts_once_on_the_retry(): void {
		// Arrange: one attempt counted; the next one's first write loses.
		$bucket = md5( 'one-conflict' );
		$first  = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
		$this->assertNotNull( $first );
		$this->lose_writes( $bucket, 1 );

		// Act.
		$second = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

		// Assert: the first attempt, the other request and this one.
		list( $count, , $generation ) = explode( '|', $this->row( $bucket ) );
		$this->assertSame( $first, $second );
		$this->assertSame( array( '3', $first ), array( $count, $generation ) );
	}

	/**
	 * A reservation whose every write loses is refused without counting.
	 */
	public function test_rate_limit_reservation_that_loses_every_write_is_refused_uncounted(): void {
		// Arrange.
		$bucket = md5( 'every-conflict' );
		$first  = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
		$this->assertNotNull( $first );
		$this->lose_writes( $bucket, 5 );

		// Act.
		$refused = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

		// Assert: the first attempt and the five others, none of this one's.
		list( $count, , $generation ) = explode( '|', $this->row( $bucket ) );
		$this->assertNull( $refused );
		$this->assertSame( array( '6', $first ), array( $count, $generation ) );
	}

	/**
	 * A refused attempt writes nothing, so it holds no slot and moves no window. Replaces the
	 * check that a refused attempt held its window until refunded: a refusal now counts nothing,
	 * so it has nothing to give back.
	 */
	public function test_rate_limit_refused_attempt_leaves_its_row_untouched(): void {
		// Arrange: a full bucket whose window is about to end.
		global $wpdb;
		$bucket = md5( 'refused-untouched' );
		$full   = '10|' . ( time() + 1 ) . '|full-window';
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wcpos_auth_attempts_' . $bucket, $full ) );

		// Act.
		$refused = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

		// Assert.
		$this->assertNull( $refused );
		$this->assertSame( $full, $this->row( $bucket ) );
	}

	/**
	 * An attempt refused while another holds the last slot does not keep that slot once it is given back.
	 */
	public function test_rate_limit_refused_attempt_leaves_no_slot_behind(): void {
		// Arrange: nine failures.
		$bucket = md5( 'refused-slot' );
		for ( $i = 0; $i < 9; $i++ ) {
			Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
		}

		// Act: A holds the tenth slot, B is refused, A's password is right.
		$a = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
		$this->assertNotNull( $a );
		$this->assertNull( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		Credential_Lockout::release_bucket_attempt( $bucket, $a );

		// Assert: the peer still has its tenth attempt.
		$this->assertFalse( Credential_Lockout::bucket_is_full( $bucket, 10 ) );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
	}

	/**
	 * A give-back after its window ended and restarted leaves the new window's count alone.
	 * Replaces the check of the 60-second release guard: the window here ends under A however
	 * soon A answers, which stands in for a restart between that guard's time check and its UPDATE.
	 */
	public function test_rate_limit_give_back_after_its_window_restarted_leaves_the_new_window_alone(): void {
		// Arrange: A is counted, its window ends, B is counted in the next window and fails.
		global $wpdb;
		$bucket = md5( 'restart-give-back' );
		$a      = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
		$this->assertNotNull( $a );
		$this->end_windows( $wpdb->esc_like( 'wcpos_auth_attempts_' . $bucket ) );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		Credential_Lockout::record_bucket_failure( $bucket, 900 );
		$after_b = $this->row( $bucket );

		// Act: A's right password gives its attempt back.
		Credential_Lockout::release_bucket_attempt( $bucket, $a );

		// Assert: B's failure is still counted.
		$this->assertStringStartsWith( '1|', $after_b );
		$this->assertSame( $after_b, $this->row( $bucket ) );
	}

	/**
	 * A give-back after a successful check cleared the account leaves the fresh count alone.
	 */
	public function test_lockout_give_back_after_clear_leaves_the_fresh_count_alone(): void {
		// Arrange: B and A are counted; A's password is right and clears the account; C fails.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$b                      = Credential_Lockout::reserve_attempt( 'till-cashier' );
		$this->assertNotNull( $b );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		Credential_Lockout::clear( 'till-cashier' );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		Credential_Lockout::record_failure( 'till-cashier' );
		$bucket  = md5( 'user:' . get_user_by( 'login', 'till-cashier' )->ID . '|203.0.113.10' );
		$after_c = $this->row( $bucket );

		// Act: B's right password is refused for another reason and gives its attempt back.
		Credential_Lockout::release_attempt( 'till-cashier', $b );

		// Assert: C's failure is still counted.
		$this->assertStringStartsWith( '1|', $after_c );
		$this->assertSame( $after_c, $this->row( $bucket ) );
	}

	/**
	 * A lock set while a right-password attempt held a slot is lifted when that attempt is given back.
	 */
	public function test_lockout_give_back_lifts_a_lock_that_counted_it(): void {
		// Arrange: three failures, then A (right password) holds the fourth slot.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
			Credential_Lockout::record_failure( 'till-cashier' );
		}
		$a = Credential_Lockout::reserve_attempt( 'till-cashier' );
		$this->assertNotNull( $a );

		// Act: B fails in the fifth slot, which locks; A is refused for another reason and gives back.
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		Credential_Lockout::record_failure( 'till-cashier' );
		$this->assertNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		Credential_Lockout::release_attempt( 'till-cashier', $a );

		// Assert: four wrong passwords do not lock; the fifth attempt is admitted, the sixth is not.
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		$this->assertNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
	}

	/**
	 * A give-back that lands between a failure's count and its lock write leaves no lock behind.
	 */
	public function test_lockout_give_back_during_the_lock_write_leaves_no_lock(): void {
		// Arrange: three failures, A (right password) holds the fourth slot, B holds the fifth.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
			Credential_Lockout::record_failure( 'till-cashier' );
		}
		$a = Credential_Lockout::reserve_attempt( 'till-cashier' );
		$this->assertNotNull( $a );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		$bucket    = md5( 'user:' . get_user_by( 'login', 'till-cashier' )->ID . '|203.0.113.13' );
		$give_back = static function ( $value ) use ( $a ) {
			Credential_Lockout::release_attempt( 'till-cashier', $a );
			return $value;
		};
		add_filter( 'pre_set_transient_wcpos_auth_lock_' . $bucket, $give_back );

		// Act: B fails; A gives back after B read five attempts and before B writes the lock.
		Credential_Lockout::record_failure( 'till-cashier' );
		remove_filter( 'pre_set_transient_wcpos_auth_lock_' . $bucket, $give_back );

		// Assert: four wrong passwords do not lock.
		$this->assertFalse( get_transient( 'wcpos_auth_lock_' . $bucket ) );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
	}

	/**
	 * A give-back that matches no counted attempt leaves a lock in place.
	 */
	public function test_lockout_late_give_back_leaves_a_lock_in_place(): void {
		// Arrange: A is counted and cleared by a successful check; five failures then lock.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
		$a                      = Credential_Lockout::reserve_attempt( 'till-cashier' );
		$this->assertNotNull( $a );
		Credential_Lockout::clear( 'till-cashier' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
			Credential_Lockout::record_failure( 'till-cashier' );
		}

		// Act: A's late give-back belongs to the cleared count.
		Credential_Lockout::release_attempt( 'till-cashier', $a );

		// Assert: the account is still locked.
		$bucket = md5( 'user:' . get_user_by( 'login', 'till-cashier' )->ID . '|203.0.113.12' );
		$this->assertNotFalse( get_transient( 'wcpos_auth_lock_' . $bucket ) );
		$this->assertNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
	}

	/**
	 * A row in the format before generations, or any value that is not a counter, reads as an
	 * ended window: never a fatal, and the limit holds from the next attempt.
	 */
	public function test_rate_limit_row_in_an_older_format_reads_as_an_ended_window(): void {
		global $wpdb;
		foreach ( array( '10|' . ( time() + 900 ), 'not-a-counter', '' ) as $i => $stored ) {
			// Arrange.
			$bucket = md5( 'older-format-' . $i );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wcpos_auth_attempts_' . $bucket, $stored ) );

			// Act.
			$full  = Credential_Lockout::bucket_is_full( $bucket, 10 );
			$first = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

			// Assert: a fresh window of one, then ten attempts in all, then refused.
			list( $count, , $generation ) = explode( '|', $this->row( $bucket ) ) + array( '', '', '' );
			$this->assertFalse( $full );
			$this->assertNotNull( $first );
			$this->assertSame( array( '1', $first ), array( $count, $generation ) );
			for ( $n = 1; $n < 10; $n++ ) {
				$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
			}
			$this->assertNull( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		}
	}

	/**
	 * Retries against an account whose last attempt died mid-check do not keep its window open.
	 */
	public function test_lockout_retries_on_a_full_account_bucket_leave_its_window_alone(): void {
		// Arrange: four failures, then a fifth attempt counted whose request never answered.
		global $wpdb;
		for ( $i = 0; $i < 4; $i++ ) {
			$this->attempt( '203.0.113.10', 'till-cashier', 'wrong' );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'till-cashier' ) );
		$like = $wpdb->esc_like( 'wcpos_auth_attempts_' ) . '%';
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT('5|', %d, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name LIKE %s AND option_value LIKE %s", time() + 1, $like, '5|%' ) );
		$ending = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $like, '5|%' ) );

		// Act.
		$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'correct horse' );

		// Assert: refused without a write, so the window still ends when it did.
		$this->assertSame( 'This account is temporarily locked due to too many failed login attempts. Please try again later.', $auth->get_error() );
		$this->assertCount( 1, $ending );
		$this->assertSame( $ending, $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $like, '5|%' ) ) );
	}

	/**
	 * A login the database could not count against the peer is refused before the password is checked.
	 */
	public function test_rate_limit_attempt_that_cannot_be_counted_is_refused(): void {
		// Arrange.
		global $wpdb;
		$checked = false;
		add_filter(
			'check_password',
			static function ( $check ) use ( &$checked ) {
				$checked = true;
				return $check;
			}
		);
		add_filter(
			'query',
			static function ( $query ) {
				return 0 === strpos( $query, 'INSERT' ) && false !== strpos( $query, 'wcpos_auth_attempts_' ) ? 'SELECT * FROM wcpos_no_such_table' : $query;
			}
		);
		$suppress = $wpdb->suppress_errors();

		// Act.
		$auth = $this->attempt( '203.0.113.10', 'till-cashier', 'correct horse' );

		// Assert.
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 'Too many requests. Please try again later.', $auth->get_error() );
		$this->assertFalse( $checked );
		$this->assertFalse( $this->sentinel_reached );
	}
}
