<?php
/**
 * Auth popup session reuse tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use ReflectionClass;
use WCPOS\WooCommercePOS\Templates\Auth;
use WP_UnitTestCase;

/**
 * Repeated popup GETs must not invalidate the first form.
 */
class Test_Auth_Session_Reuse extends WP_UnitTestCase {
	/**
	 * Original request globals.
	 *
	 * @var array
	 */
	private $request;

	/**
	 * Set up a distinct popup URL.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request = array( $_REQUEST, $_SERVER );
		$_REQUEST = array(
			'redirect_uri' => 'wcpos://',
			'state'        => wp_generate_uuid4(),
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Restore request state.
	 */
	public function tearDown(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test-owned request fixture.
		delete_transient( 'wcpos_auth_session_' . md5( $_REQUEST['state'] . $_REQUEST['redirect_uri'] ) );
		list( $_REQUEST, $_SERVER ) = $this->request;
		parent::tearDown();
	}

	/**
	 * A second GET keeps the hidden value from the first page valid.
	 */
	public function test_second_get_without_auth_session_reuses_the_stored_value(): void {
		$first  = new Auth();
		$second = new Auth();

		$this->assertNotEmpty( $first->get_auth_session() );
		$this->assertSame( $first->get_auth_session(), $second->get_auth_session() );
		$this->assertSame( '', $first->get_error() );
		$this->assertSame( '', $second->get_error() );
	}

	/**
	 * The first form's POST session value survives an intervening GET.
	 */
	public function test_post_with_the_first_value_after_a_second_get_is_still_valid(): void {
		$first  = new Auth();
		$second = new Auth();
		$_REQUEST['auth_session'] = $first->get_auth_session();
		$third = new Auth();

		// Exercise session validation for POST without credential handling or its redirect/exit.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$method = ( new ReflectionClass( Auth::class ) )->getMethod( 'validate_or_create_auth_session' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( $third ) );
		$this->assertSame( '', $third->get_error() );
	}
}
