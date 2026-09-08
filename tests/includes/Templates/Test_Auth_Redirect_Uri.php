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
}
