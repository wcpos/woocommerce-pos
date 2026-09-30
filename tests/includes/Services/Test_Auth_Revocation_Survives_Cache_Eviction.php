<?php
/**
 * Revocation must not depend on a cache entry surviving.
 *
 * On a site with a persistent object cache (Redis, Memcached) a transient lives only
 * in the cache and can be evicted at any moment. Deleting the `wcpos_blacklist_{jti}`
 * transient directly is exactly what an eviction (or a transient purge on a site
 * without an object cache) does, so these tests revoke, evict, then validate.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Session_Registry;
use WP_Error;
use WP_UnitTestCase;

/**
 * Revocation durability coverage.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Auth_Revocation_Survives_Cache_Eviction extends WP_UnitTestCase {
	/**
	 * Auth service under test.
	 *
	 * @var Auth
	 */
	private $auth_service;

	/**
	 * Test user.
	 *
	 * @var \WP_User
	 */
	private $user;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'] );
		$this->auth_service = Auth::instance();
		$this->user         = $this->factory->user->create_and_get( array( 'role' => 'shop_manager' ) );
	}

	/**
	 * Tear down: never leak a bearer header into later tests, even when a test fails.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
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
	 * What a cache eviction does to the revocation record.
	 *
	 * @param string $jti Session (refresh token) JTI.
	 */
	private function evict_revocation_record( string $jti ): void {
		delete_transient( "wcpos_blacklist_{$jti}" );
	}

	/**
	 * Revoking one session keeps its access token dead after the blacklist record is evicted.
	 */
	public function test_revoke_session_access_token_after_eviction_is_rejected(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();
		$this->assertTrue( $this->auth_service->revoke_session_with_blacklist( $this->user->ID, $jti ) );
		$this->assertInstanceOf( WP_Error::class, $this->auth_service->validate_token( $tokens['access_token'], 'access' ) );

		// Act.
		$this->evict_revocation_record( $jti );
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert: the session is gone from the durable registry, so the token stays dead.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * Revoking every session keeps their access tokens dead after eviction.
	 */
	public function test_revoke_all_sessions_access_token_after_eviction_is_rejected(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();
		$this->assertTrue( $this->auth_service->revoke_all_refresh_tokens( $this->user->ID ) );

		// Act.
		$this->evict_revocation_record( $jti );
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * Revoking all but the current session keeps the others dead after eviction.
	 */
	public function test_revoke_all_except_current_other_access_token_after_eviction_is_rejected(): void {
		// Arrange.
		list( $current_tokens, $current_jti ) = $this->login();
		list( $other_tokens, $other_jti )     = $this->login();
		$this->assertTrue( $this->auth_service->revoke_all_sessions_except( $this->user->ID, $current_jti ) );

		// Act.
		$this->evict_revocation_record( $other_jti );
		$other   = $this->auth_service->validate_token( $other_tokens['access_token'], 'access' );
		$current = $this->auth_service->validate_token( $current_tokens['access_token'], 'access' );

		// Assert: the revoked device stays out, the kept device stays in.
		$this->assertInstanceOf( WP_Error::class, $other );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $other->get_error_code() );
		$this->assertNotInstanceOf( WP_Error::class, $current );
	}

	/**
	 * A revocation that writes no blacklist record (web reload cleanup) still rejects the access token.
	 */
	public function test_revoke_session_without_blacklist_rejects_its_access_token(): void {
		// Arrange: the web-frontend cleanup path revokes the row and writes no transient at all.
		list( $tokens, $jti ) = $this->login();
		$this->assertTrue( $this->auth_service->revoke_session( $this->user->ID, $jti ) );

		// Act.
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * Control: the refresh path already consults the durable registry (passes today).
	 */
	public function test_revoke_session_refresh_token_after_eviction_is_rejected(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();
		$this->auth_service->revoke_session_with_blacklist( $this->user->ID, $jti );

		// Act.
		$this->evict_revocation_record( $jti );
		$result = $this->auth_service->refresh_access_token( $tokens['refresh_token'] );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_refresh_token_revoked', $result->get_error_code() );
	}

	/**
	 * Control: a live session with no blacklist record validates (passes today, must keep passing).
	 */
	public function test_live_session_access_token_is_accepted(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();

		// Act.
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert.
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( $jti, $result->refresh_jti );
	}

	/**
	 * An access token whose session has expired in the registry is rejected.
	 */
	public function test_expired_session_access_token_is_rejected(): void {
		// Arrange: the session expires while its access token is still within its own lifetime.
		list( $tokens, $jti ) = $this->login();

		$sessions                    = get_user_meta( $this->user->ID, Session_Registry::META_KEY, true );
		$sessions[ $jti ]['expires'] = time() - 1;
		update_user_meta( $this->user->ID, Session_Registry::META_KEY, $sessions );

		// Act.
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * Bearer authentication for every REST request refuses a revoked session after eviction.
	 */
	public function test_revoked_session_bearer_is_refused_by_request_authentication_after_eviction(): void {
		// Arrange: the path the global determine_current_user filter takes.
		list( $tokens, $jti )          = $this->login();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( $this->user->ID, $this->auth_service->authenticate_request() );

		// Act.
		$this->auth_service->revoke_session_with_blacklist( $this->user->ID, $jti );
		$this->evict_revocation_record( $jti );
		$result = $this->auth_service->authenticate_request();

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * A session entry of the wrong type rejects the access token instead of raising an error.
	 */
	public function test_malformed_session_entry_rejects_access_token(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();

		$sessions         = get_user_meta( $this->user->ID, Session_Registry::META_KEY, true );
		$sessions[ $jti ] = 'garbage';
		update_user_meta( $this->user->ID, Session_Registry::META_KEY, $sessions );

		// Act.
		$result = $this->auth_service->validate_token( $tokens['access_token'], 'access' );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_session_revoked', $result->get_error_code() );
	}

	/**
	 * A session entry of the wrong type refuses the refresh instead of raising an error.
	 */
	public function test_malformed_session_entry_refuses_refresh(): void {
		// Arrange.
		list( $tokens, $jti ) = $this->login();

		$sessions         = get_user_meta( $this->user->ID, Session_Registry::META_KEY, true );
		$sessions[ $jti ] = 'garbage';
		update_user_meta( $this->user->ID, Session_Registry::META_KEY, $sessions );

		// Act.
		$result = $this->auth_service->refresh_access_token( $tokens['refresh_token'] );

		// Assert.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_auth_refresh_token_revoked', $result->get_error_code() );
	}
}
