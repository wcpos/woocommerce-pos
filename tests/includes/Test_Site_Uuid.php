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
	 *
	 * The address moves through the `home_url` filter, not the `home` option:
	 * the smoke lane pins WP_HOME, which `home_url()` prefers to the option.
	 */
	public function test_site_uuid_changed_home_mints_new_uuid(): void {
		$home   = 'https://example.com';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );

		try {
			$original = wcpos_get_site_uuid();

			$home    = 'https://example.com/staging';
			$changed = wcpos_get_site_uuid();

			$this->assertNotSame( $original, $changed );
			$this->assertSame( $changed, get_option( 'woocommerce_pos_uuid' ) );
			$this->assertSame( 'example.com/staging', get_option( 'woocommerce_pos_uuid_home' ) );
			$this->assertSame( $changed, wcpos_get_site_uuid() );
		} finally {
			remove_filter( 'home_url', $filter );
		}
	}

	/**
	 * A vetoed uuid write must not advance the home marker: the next call
	 * would otherwise see a matching home and return the old uuid, after this
	 * call handed out one that was never stored. The stored uuid is returned.
	 */
	public function test_site_uuid_vetoed_write_keeps_home_and_returns_stored_uuid(): void {
		$home   = 'https://example.com';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );
		$veto = static function ( $value, $old_value ) {
			return $old_value;
		};

		try {
			$original = wcpos_get_site_uuid();

			$home = 'https://example.com/staging';
			add_filter( 'pre_update_option_woocommerce_pos_uuid', $veto, 10, 2 );
			$returned = wcpos_get_site_uuid();
			remove_filter( 'pre_update_option_woocommerce_pos_uuid', $veto );

			$this->assertSame( $original, $returned );
			$this->assertSame( $original, get_option( 'woocommerce_pos_uuid' ) );
			$this->assertSame( 'example.com', get_option( 'woocommerce_pos_uuid_home' ) );

			// With the veto gone the move goes through on the next call.
			$this->assertNotSame( $original, wcpos_get_site_uuid() );
			$this->assertSame( 'example.com/staging', get_option( 'woocommerce_pos_uuid_home' ) );
		} finally {
			remove_filter( 'pre_update_option_woocommerce_pos_uuid', $veto );
			remove_filter( 'home_url', $filter );
		}
	}

	/**
	 * A vetoed home-marker write after the uuid rotated must put the previous
	 * uuid back, or every later call would mint again while the marker still
	 * differs. The move goes through once the write succeeds.
	 */
	public function test_site_uuid_vetoed_home_write_restores_previous_uuid(): void {
		$home   = 'https://example.com';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );
		$veto = static function ( $value, $old_value ) {
			return $old_value;
		};

		try {
			$original = wcpos_get_site_uuid();

			$home = 'https://example.com/staging';
			add_filter( 'pre_update_option_woocommerce_pos_uuid_home', $veto, 10, 2 );
			$returned = wcpos_get_site_uuid();
			remove_filter( 'pre_update_option_woocommerce_pos_uuid_home', $veto );

			$this->assertSame( $original, $returned );
			$this->assertSame( $original, get_option( 'woocommerce_pos_uuid' ) );
			$this->assertSame( 'example.com', get_option( 'woocommerce_pos_uuid_home' ) );

			$changed = wcpos_get_site_uuid();
			$this->assertNotSame( $original, $changed );
			$this->assertSame( $changed, get_option( 'woocommerce_pos_uuid' ) );
			$this->assertSame( 'example.com/staging', get_option( 'woocommerce_pos_uuid_home' ) );
		} finally {
			remove_filter( 'pre_update_option_woocommerce_pos_uuid_home', $veto );
			remove_filter( 'home_url', $filter );
		}
	}

	/**
	 * A concurrent request that finished the move first is not a failed marker
	 * write. update_option() answers false when the row already holds the new
	 * home; rolling back on that would leave the old uuid behind the new
	 * marker, and every later call would return it for the moved site.
	 *
	 * The other request has its own option cache, so its write reaches this
	 * request only through the database: it is emulated as a direct row update,
	 * leaving this request's cached marker stale exactly as a real second
	 * request would find it.
	 */
	public function test_site_uuid_marker_already_moved_by_concurrent_request_keeps_new_uuid(): void {
		global $wpdb;
		$home   = 'https://example.com';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );
		// The other request lands its whole move between this call's read of the
		// marker and its own marker write: emulated as a side effect of the uuid
		// write, the last thing this call does before writing the marker.
		$other_request_finishes_first = static function ( $value ) use ( $wpdb ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => 'example.com/staging' ), array( 'option_name' => 'woocommerce_pos_uuid_home' ) );

			return $value;
		};

		try {
			$original = wcpos_get_site_uuid();

			$home = 'https://example.com/staging';
			add_filter( 'pre_update_option_woocommerce_pos_uuid', $other_request_finishes_first );
			$returned = wcpos_get_site_uuid();
			remove_filter( 'pre_update_option_woocommerce_pos_uuid', $other_request_finishes_first );

			$this->assertNotSame( $original, $returned );
			$this->assertSame( $returned, get_option( 'woocommerce_pos_uuid' ) );
			// The stale cache was dropped: this request now sees the moved marker
			// and a later call returns the same uuid rather than minting again.
			$this->assertSame( 'example.com/staging', get_option( 'woocommerce_pos_uuid_home' ) );
			$this->assertSame( $returned, wcpos_get_site_uuid() );
		} finally {
			remove_filter( 'pre_update_option_woocommerce_pos_uuid', $other_request_finishes_first );
			remove_filter( 'home_url', $filter );
		}
	}

	/**
	 * Scheme, host case, `www.` and trailing slashes do not change the identity.
	 */
	public function test_site_identity_home_normalization_preserves_uuid(): void {
		$home   = 'https://Example.com/';
		$filter = static function () use ( &$home ) {
			return $home;
		};
		add_filter( 'home_url', $filter );

		try {
			$uuid = wcpos_get_site_uuid();
			foreach ( array( 'https://Example.com/', 'http://example.com', 'example.com', 'https://www.example.com', 'http://WWW.Example.com/' ) as $home ) {
				$this->assertSame( 'example.com', wcpos_get_site_identity_home() );
				$this->assertSame( $uuid, wcpos_get_site_uuid() );
			}

			$home = 'example.com/staging';
			$this->assertSame( 'example.com/staging', wcpos_get_site_identity_home() );
			$home = 'https://Example.com:8080/Staging/';
			$this->assertSame( 'example.com:8080/Staging', wcpos_get_site_identity_home() );
			$home = 'https://www.example.com/staging';
			$this->assertSame( 'example.com/staging', wcpos_get_site_identity_home() );
		} finally {
			remove_filter( 'home_url', $filter );
		}
	}
}
