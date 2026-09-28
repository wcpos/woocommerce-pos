<?php
/**
 * Tests for the site UUID helper.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Test_Site_Uuid class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Site_Uuid extends WP_UnitTestCase {
	/**
	 * Start each test without a saved site identity.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'woocommerce_pos_uuid' );
		delete_option( 'woocommerce_pos_uuid_home' );
	}

	/**
	 * Clean the option between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_uuid' );
		delete_option( 'woocommerce_pos_uuid_home' );
		parent::tearDown();
	}

	/**
	 * Generates once, persists, and returns the same value thereafter.
	 */
	public function test_generates_once_and_is_stable(): void {
		$first  = wcpos_get_site_uuid();
		$second = wcpos_get_site_uuid();

		$this->assertNotEmpty( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, get_option( 'woocommerce_pos_uuid' ) );
		$this->assertSame( wcpos_get_site_identity_home(), get_option( 'woocommerce_pos_uuid_home' ) );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			$first
		);
	}

	/**
	 * An existing UUID is returned untouched.
	 */
	public function test_existing_uuid_is_preserved(): void {
		update_option( 'woocommerce_pos_uuid', 'existing-uuid-value' );

		$uuid = wcpos_get_site_uuid();

		$this->assertSame( 'existing-uuid-value', $uuid );
		$this->assertSame( $uuid, get_option( 'woocommerce_pos_uuid' ) );
		$this->assertSame( wcpos_get_site_identity_home(), get_option( 'woocommerce_pos_uuid_home' ) );
	}

	/**
	 * A clone at a different path gets its own persisted identity.
	 */
	public function test_site_uuid_changed_home_mints_new_uuid(): void {
		update_option( 'home', 'https://example.com' );
		$original = wcpos_get_site_uuid();

		update_option( 'home', 'https://example.com/staging' );
		$changed = wcpos_get_site_uuid();

		$this->assertNotSame( $original, $changed );
		$this->assertSame( $changed, get_option( 'woocommerce_pos_uuid' ) );
		$this->assertSame( 'example.com/staging', get_option( 'woocommerce_pos_uuid_home' ) );
		$this->assertSame( $changed, wcpos_get_site_uuid() );
	}

	/**
	 * Scheme, host case and trailing slashes do not change the identity.
	 */
	public function test_site_identity_home_normalization_preserves_uuid(): void {
		$home   = 'https://Example.com/';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );

		try {
			$uuid = wcpos_get_site_uuid();
			foreach ( array( 'https://Example.com/', 'http://example.com', 'example.com' ) as $home ) {
				$this->assertSame( 'example.com', wcpos_get_site_identity_home() );
				$this->assertSame( $uuid, wcpos_get_site_uuid() );
			}

			$home = 'example.com/staging';
			$this->assertSame( 'example.com/staging', wcpos_get_site_identity_home() );
			$home = 'https://Example.com:8080/Staging/';
			$this->assertSame( 'example.com:8080/Staging', wcpos_get_site_identity_home() );
		} finally {
			remove_filter( 'home_url', $filter );
		}
	}
}
