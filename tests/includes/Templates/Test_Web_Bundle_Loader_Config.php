<?php
/**
 * Web bundle configuration tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates\Web_Bundle_Loader_Config as Config;
use WP_UnitTestCase;

class Test_Web_Bundle_Loader_Config extends WP_UnitTestCase {
	/** @dataProvider worker_sources */
	public function test_worker_version_source_returns_unique_version( string $source, ?string $expected ): void {
		// Arrange.
		$path = wp_tempnam( 'worker' );
		file_put_contents( $path, $source );
		$hash = hash_file( 'sha256', $path );
		try {
			// Act.
			$actual = Config::worker_rxdb_version( $path, $hash );
			// Assert.
			$this->assertSame( $expected, $actual );
		} finally {
			unlink( $path );
		}
	}

	public function worker_sources(): array {
		return array(
			array( 'var Xe="17.6.0";', '17.6.0' ),
			array( "RXDB_VERSION = '17.6.0'", '17.6.0' ),
			array( 'var a=0,Xe="17.6.0";var y="17.6.0";', '17.6.0' ),
			array( 'var nothing=0;', null ),
			array( 'var Xe="17.6.0",Y="17.4.0";', null ),
		);
	}

	/** @dataProvider worker_sources */
	public function test_worker_version_cached_hash_avoids_rescanning( string $source, ?string $expected ): void {
		// Arrange: an unchanged deploy hash should reuse even a cached null.
		$path = wp_tempnam( 'worker' );
		file_put_contents( $path, $source );
		$hash = hash_file( 'sha256', $path );
		Config::worker_rxdb_version( $path, $hash );
		file_put_contents( $path, 'var replaced="99.0.0";' );
		try {
			// Act.
			$actual = Config::worker_rxdb_version( $path, $hash );
			// Assert.
			$this->assertSame( $expected, $actual );
		} finally {
			unlink( $path );
		}
	}

	public function test_prerelease_suffix_returns_expected_boolean(): void {
		// Arrange.
		$versions = array( '2.0.0' => false, '2.0.0-beta.1' => true, '2.0.0+build' => false );
		foreach ( $versions as $version => $expected ) {
			// Act.
			$actual = Config::is_prerelease( $version );
			// Assert.
			$this->assertSame( $expected, $actual );
		}
	}

	public function test_manifest_url_optional_values_preserves_order(): void {
		// Arrange.
		$base = 'https://cdn.wcpos.com/manifest';
		// Act.
		$full = Config::manifest_url( 'next', '2.0.0', '2.0.0', '17.6.0', 'site-uuid' );
		$free = Config::manifest_url( '2.0', '2.0.0', null, '17.6.0', null );
		$bare = Config::manifest_url( 'next', '2.0.0', null, null, null );
		// Assert.
		$this->assertSame( $base . '?ref=next&plugin=2.0.0&worker=17.6.0&pro=2.0.0&site=site-uuid', $full );
		$this->assertSame( $base . '?ref=2.0&plugin=2.0.0&worker=17.6.0', $free );
		$this->assertSame( $base . '?ref=next&plugin=2.0.0', $bare );
	}

	/** @dataProvider manifest_modes */
	public function test_uses_manifest_mode_returns_expected_boolean( string $ref, bool $development, bool $overridden, bool $expected ): void {
		// Arrange: inputs supplied by provider.
		// Act.
		$actual = Config::uses_manifest( $ref, $development, $overridden );
		// Assert.
		$this->assertSame( $expected, $actual );
	}

	public function manifest_modes(): array {
		return array(
			array( 'https://preview.test/build', false, true, false ),
			array( '2.0', true, false, false ),
			array( 'next', true, true, true ),
			array( '2.0', false, false, true ),
			array( 'abc1234', false, true, true ),
		);
	}
	/** @dataProvider footer_refs */
	public function test_footer_ref_emits_loader_without_changing_existing_urls( string $ref, bool $development, ?string $lane, string $base, bool $uses_manifest ): void {
		// Arrange.
		wp_set_current_user( self::factory()->user->create() );
		$ref_filter = static function () use ( $ref ) { return $ref; };
		$dev_filter = static function () use ( $development ) { return $development; };
		$vars       = null;
		$capture    = static function ( $value ) use ( &$vars ) { $vars = $value; return $value; };
		add_filter( 'woocommerce_pos_web_bundle_ref', $ref_filter );
		add_filter( 'woocommerce_pos_development_mode', $dev_filter );
		add_filter( 'woocommerce_pos_inline_vars', $capture );
		ob_start();
		try {
			// Act.
			( new \WCPOS\WooCommercePOS\Templates\Frontend() )->footer();
			// Assert.
			$this->assertSame( $lane, $vars['loader']['lane'] );
			$this->assertSame( $base, $vars['loader']['fallback_base'] );
			$this->assertSame( $vars['manifest'], $vars['loader']['fallback_manifest'] );
			$this->assertSame( $uses_manifest, null !== $vars['loader']['manifest_url'] );
			if ( $uses_manifest ) {
				parse_str( wp_parse_url( $vars['loader']['manifest_url'], PHP_URL_QUERY ), $query );
				$this->assertSame( $ref, $query['ref'] );
				$this->assertSame( \WCPOS\WooCommercePOS\VERSION, $query['plugin'] );
				if ( null === $vars['loader']['pro_version'] && ! Config::is_prerelease( \WCPOS\WooCommercePOS\VERSION ) ) {
					$this->assertArrayNotHasKey( 'site', $query );
				} else {
					$this->assertSame( $vars['site']['uuid'], $query['site'] );
				}
			}
		} finally {
			ob_end_clean();
			remove_filter( 'woocommerce_pos_web_bundle_ref', $ref_filter );
			remove_filter( 'woocommerce_pos_development_mode', $dev_filter );
			remove_filter( 'woocommerce_pos_inline_vars', $capture );
			wp_set_current_user( 0 );
		}
	}

	public function footer_refs(): array {
		return array(
			array( 'next', false, 'next', 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@next/build', true ),
			array( '2.0', false, '2.0', 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@2.0/build', true ),
			array( 'abc1234', false, null, 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@abc1234/build', true ),
			array( 'v2.0.0', false, null, 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@v2.0.0/build', true ),
			array( 'https://preview.test/build/', false, null, 'https://preview.test/build', false ),
			array( '2.0', true, '2.0', 'http://localhost:4567/build', false ),
			array( 'next', true, 'next', 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@next/build', true ),
		);
	}
}
