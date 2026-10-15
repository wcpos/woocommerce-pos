<?php
/**
 * Tests for the POS login template's redirect_uri allow-list.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use ReflectionClass;
use WCPOS\WooCommercePOS\Templates\Auth;
use WP_UnitTestCase;

/**
 * Class Test_Auth_Redirect_Uri
 */
class Test_Auth_Redirect_Uri extends WP_UnitTestCase {
	/**
	 * Pin the site to `http://example.org`: wp-env's test site is `http://localhost:8895`,
	 * and a loopback host would pass the origin check on its own, which must not be
	 * what the site-origin rows prove.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'home_url', array( $this, 'example_site' ), 10, 0 );
		add_filter( 'site_url', array( $this, 'example_site' ), 10, 0 );
	}

	/**
	 * Unpin the site.
	 */
	public function tearDown(): void {
		remove_filter( 'home_url', array( $this, 'example_site' ), 10 );
		remove_filter( 'site_url', array( $this, 'example_site' ), 10 );
		parent::tearDown();
	}

	/**
	 * The site URL every test sees.
	 *
	 * @return string
	 */
	public function example_site(): string {
		return 'http://example.org';
	}

	/**
	 * Run the private validator without the constructor's request handling.
	 *
	 * @param string $uri The candidate redirect URI.
	 *
	 * @return string Empty string when rejected.
	 */
	private function validate( string $uri ): string {
		$class    = new ReflectionClass( Auth::class );
		$instance = $class->newInstanceWithoutConstructor();
		$method   = $class->getMethod( 'validate_redirect_uri' );
		$method->setAccessible( true );

		return (string) $method->invoke( $instance, $uri );
	}

	/**
	 * Each native build profile has its own scheme; all three must round-trip.
	 *
	 * @dataProvider app_scheme_provider
	 *
	 * @param string $uri A redirect URI using one of the app's schemes.
	 */
	public function test_accepts_every_app_build_profile_scheme( string $uri ): void {
		$this->assertSame( $uri, $this->validate( $uri ) );
	}

	/**
	 * Redirect URIs the native app sends, one per EAS build profile.
	 *
	 * @return array<string, array{string}>
	 */
	public function app_scheme_provider(): array {
		return array(
			'store build'        => array( 'wcpos://' ),
			'development client' => array( 'wcpos-dev://' ),
			'adhoc build'        => array( 'wcpos-adhoc://' ),
			'Expo Go'            => array( 'exp://127.0.0.1:8081/--/' ),
			'case-insensitive'   => array( 'WCPOS-DEV://' ),
		);
	}

	/**
	 * The list is an allow-list: near-misses on the new schemes stay rejected.
	 *
	 * @dataProvider rejected_scheme_provider
	 *
	 * @param string $uri A redirect URI that must not be accepted.
	 */
	public function test_rejects_schemes_outside_the_allow_list( string $uri ): void {
		$this->assertSame( '', $this->validate( $uri ) );
	}

	/**
	 * Schemes that share a prefix with an allowed one but are not it.
	 *
	 * @return array<string, array{string}>
	 */
	public function rejected_scheme_provider(): array {
		return array(
			'longer than wcpos-dev' => array( 'wcpos-devious://' ),
			'other suffix'          => array( 'wcpos-preview://' ),
			'exp plus slug'         => array( 'exp+wcpos://' ),
			'javascript'            => array( 'javascript:alert(1)' ),
			'empty'                 => array( '' ),
		);
	}

	/**
	 * Off-site destinations must not receive cashier credentials.
	 *
	 * @dataProvider rejected_web_destination_provider
	 * @param string $uri The candidate destination.
	 */
	public function test_rejects_web_destinations_off_the_site_origin( string $uri ): void {
		// Arrange: the provider supplies an untrusted destination.
		// Act.
		$result = $this->validate( $uri );
		// Assert.
		$this->assertSame( '', $result );
	}

	/**
	 * Untrusted hosts, ports and misleading destination URLs.
	 *
	 * @return array
	 */
	public function rejected_web_destination_provider(): array {
		return array(
			array( 'https://attacker.example/cb' ),
			array( 'http://attacker.example/cb' ),
			array( 'https://example.org.attacker.example/cb' ),
			array( 'https://example.org@attacker.example/cb' ),
			array( 'http://example.org:8080/cb' ),
			array( 'https://attacker.example/?next=http://example.org' ),
			array( 'https://expo.app/cb' ),
			array( 'https://evil--wcpos.expo.app/cb' ),
			array( 'https://wcpos.expo.app.attacker.example/cb' ),
			array( 'http://wcpos.expo.app/cb' ),
			array( 'http://localhost.attacker.example/cb' ),
		);
	}

	/**
	 * Known web origins retain the existing escaped destination.
	 *
	 * @dataProvider allowed_web_destination_provider
	 * @param string $uri The candidate destination.
	 */
	public function test_accepts_web_destinations_on_known_origins( string $uri ): void {
		// Arrange.
		$expected = esc_url( $uri, array( 'http', 'https' ) );
		// Act.
		$result = $this->validate( $uri );
		// Assert.
		$this->assertSame( $expected, $result );
	}

	/**
	 * Site, hosted client and loopback destinations.
	 *
	 * @return array
	 */
	public function allowed_web_destination_provider(): array {
		return array(
			array( 'http://example.org/wcpos/' ),
			array( 'http://EXAMPLE.org/wcpos/' ),
			array( 'https://example.org/wcpos/' ),
			array( 'https://wcpos.expo.app/' ),
			array( 'https://wcpos.expo.app/auth/callback?x=1' ),
			array( 'http://localhost:8088/' ),
			array( 'https://localhost/cb' ),
			array( 'http://127.0.0.1:8081/' ),
			array( 'http://[::1]:3000/' ),
		);
	}

	/**
	 * The TLS relaxation never permits a downgrade to plain HTTP.
	 */
	public function test_https_site_rejects_plain_http_on_its_own_host(): void {
		// Arrange.
		$site_url = static fn() => 'https://example.org';
		add_filter( 'home_url', $site_url, 10, 0 );
		add_filter( 'site_url', $site_url, 10, 0 );
		try {
			// Act.
			$http  = $this->validate( 'http://example.org/wcpos/' );
			$https = $this->validate( 'https://example.org/wcpos/' );
			// Assert.
			$this->assertSame( '', $http );
			$this->assertSame( 'https://example.org/wcpos/', $https );
		} finally {
			remove_filter( 'home_url', $site_url );
			remove_filter( 'site_url', $site_url );
		}
	}

	/**
	 * Non-default site ports must match, even for a TLS destination.
	 */
	public function test_site_with_explicit_port_requires_that_port(): void {
		// Arrange.
		$site_url = static fn() => 'http://example.org:8080';
		add_filter( 'home_url', $site_url, 10, 0 );
		add_filter( 'site_url', $site_url, 10, 0 );
		try {
			// Act.
			$matching = $this->validate( 'http://example.org:8080/cb' );
			$http     = $this->validate( 'http://example.org/cb' );
			$https    = $this->validate( 'https://example.org/cb' );
			// Assert.
			$this->assertSame( 'http://example.org:8080/cb', $matching );
			$this->assertSame( '', $http );
			$this->assertSame( '', $https );
		} finally {
			remove_filter( 'home_url', $site_url );
			remove_filter( 'site_url', $site_url );
		}
	}

	/**
	 * Developers can opt in one preview origin without allowing its neighbours.
	 */
	public function test_filter_can_allow_a_preview_origin(): void {
		// Arrange.
		$origins = array();
		$allow   = static function ( $allowed, $origin ) use ( &$origins ) {
			$origins[] = $origin;
			return 'https://wcpos--pr-42.expo.app' === $origin || $allowed;
		};
		add_filter( 'woocommerce_pos_auth_redirect_origin_allowed', $allow, 10, 2 );
		try {
			// Act.
			$preview = $this->validate( 'https://WCPOS--PR-42.expo.app:443/cb' );
			$other   = $this->validate( 'https://wcpos--pr-43.expo.app/cb' );
			// Assert.
			$this->assertSame( esc_url( 'https://WCPOS--PR-42.expo.app:443/cb', array( 'http', 'https' ) ), $preview );
			$this->assertSame( '', $other );
			$this->assertSame( array( 'https://wcpos--pr-42.expo.app', 'https://wcpos--pr-43.expo.app' ), $origins );
		} finally {
			remove_filter( 'woocommerce_pos_auth_redirect_origin_allowed', $allow );
		}
	}
}
