<?php
/**
 * Auth account lockout and connecting-peer rate limit tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

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
}
