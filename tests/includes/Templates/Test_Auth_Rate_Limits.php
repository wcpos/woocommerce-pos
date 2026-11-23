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
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', %d) WHERE option_name LIKE %s", time() - 1, $wpdb->esc_like( 'wcpos_auth_attempts_' ) . '%' ) );

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
			$this->assertTrue( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		}
		$c    = null;
		$step = 0;
		// B's read-back starts C; C's read-back runs after A gives its slot back.
		add_filter(
			'query',
			static function ( $query ) use ( &$c, &$step, $bucket ) {
				if ( 0 === strpos( $query, 'SELECT option_value' ) && false !== strpos( $query, 'wcpos_auth_attempts_' . $bucket ) ) {
					++$step;
					if ( 1 === $step ) {
						$c = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );
					} elseif ( 2 === $step ) {
						Credential_Lockout::release_bucket_attempt( $bucket );
					}
				}
				return $query;
			}
		);

		// Act.
		$b = Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 );

		// Assert: with A's slot back, at most one of B and C may check a password.
		$this->assertLessThanOrEqual( 1, (int) $b + (int) $c );
	}

	/**
	 * Attempts refused past the limit are counted but do not hold the window open.
	 */
	public function test_rate_limit_refused_attempts_do_not_extend_the_window(): void {
		// Arrange.
		global $wpdb;
		$bucket  = md5( 'refused-hold' );
		$expires = time() + 5;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wcpos_auth_attempts_' . $bucket, '10|' . $expires ) );

		// Act.
		$this->assertFalse( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );

		// Assert.
		$this->assertSame( '10|' . $expires, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wcpos_auth_attempts_' . $bucket ) ) );
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
		$this->assertTrue( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		$this->assertFalse( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		Credential_Lockout::release_bucket_attempt( $bucket );

		// Assert: the peer still has its tenth attempt.
		$this->assertFalse( Credential_Lockout::bucket_is_full( $bucket, 10 ) );
		$this->assertTrue( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
	}

	/**
	 * A right password that outlives its window gives nothing back from the window that replaced it.
	 */
	public function test_rate_limit_release_after_the_hold_leaves_a_later_window_alone(): void {
		// Arrange: A is counted, then its window ends and B's failure starts a new one.
		global $wpdb;
		$bucket = md5( 'late-release' );
		$this->assertTrue( Credential_Lockout::reserve_bucket_attempt( $bucket, 10, 900 ) );
		$later = '1|' . ( time() + 900 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $later, 'wcpos_auth_attempts_' . $bucket ) );
		$reserved_at = new \ReflectionProperty( Credential_Lockout::class, 'reserved_at' );
		$reserved_at->setAccessible( true );
		$reserved_at->setValue( null, array( $bucket => time() - 61 ) );

		// Act: A's password check finally answers.
		Credential_Lockout::release_bucket_attempt( $bucket );

		// Assert: B's failure is still counted.
		$this->assertSame( $later, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wcpos_auth_attempts_' . $bucket ) ) );
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
