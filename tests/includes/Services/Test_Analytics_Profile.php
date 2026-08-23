<?php
/**
 * Tests for the Analytics_Profile service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Analytics_Profile;
use WP_UnitTestCase;

/**
 * Tests the analytics site profile.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Analytics_Profile
 */
class Test_Analytics_Profile extends WP_UnitTestCase {
	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_transient( 'wcpos_landing_profile' );

		parent::tearDown();
	}

	/**
	 * Counts on and around each band edge land in the documented band.
	 */
	public function test_band_maps_counts_to_documented_ranges(): void {
		$this->assertSame( '0', Analytics_Profile::band( 0 ) );
		$this->assertSame( '1-10', Analytics_Profile::band( 1 ) );
		$this->assertSame( '1-10', Analytics_Profile::band( 10 ) );
		$this->assertSame( '11-100', Analytics_Profile::band( 11 ) );
		$this->assertSame( '11-100', Analytics_Profile::band( 100 ) );
		$this->assertSame( '101-1000', Analytics_Profile::band( 101 ) );
		$this->assertSame( '101-1000', Analytics_Profile::band( 1000 ) );
		$this->assertSame( '1000+', Analytics_Profile::band( 1001 ) );
		$this->assertSame( '1000+', Analytics_Profile::band( 250000 ) );
	}

	/**
	 * The profile reports the platform and market fields the spec calls for.
	 */
	public function test_group_properties_include_platform_and_market_fields(): void {
		$properties = ( new Analytics_Profile() )->get_group_properties();

		foreach ( array( 'php_version', 'wp_version', 'wc_version', 'mysql_version', 'wcpos_version', 'wcpos_edition', 'wc_country', 'wc_currency', 'locale', 'timezone', 'multisite', 'hpos_enabled', 'tax_enabled', 'multi_currency', 'days_since_install', 'product_count_band', 'order_count_band', 'pos_user_count', 'gateway_count' ) as $key ) {
			$this->assertArrayHasKey( $key, $properties, "Missing group property: {$key}" );
		}

		$this->assertSame( PHP_VERSION, $properties['php_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $properties['wp_version'] );
		$this->assertSame( 'free', $properties['wcpos_edition'] );
	}

	/**
	 * Store size is reported as a band, never as a raw count.
	 */
	public function test_group_properties_report_bands_not_raw_counts(): void {
		set_transient(
			'wcpos_landing_profile',
			array(
				'days_since_install' => 12,
				'product_count'      => 4711,
				'order_count'        => 42,
				'pos_user_count'     => 3,
				'active_gateways'    => array( 'pos_cash', 'pos_card' ),
				'active_extensions'  => array(),
			),
			HOUR_IN_SECONDS
		);

		$properties = ( new Analytics_Profile() )->get_group_properties();

		$this->assertSame( '1000+', $properties['product_count_band'] );
		$this->assertSame( '11-100', $properties['order_count_band'] );
		$this->assertArrayNotHasKey( 'product_count', $properties );
		$this->assertArrayNotHasKey( 'order_count', $properties );

		// Gateways are a count, never names.
		$this->assertSame( 2, $properties['gateway_count'] );
		$this->assertArrayNotHasKey( 'active_gateways', $properties );
	}

	/**
	 * The profile never carries identifying data.
	 *
	 * Landing_Profile deliberately collects the site and admin domains for the
	 * updates server. Those must not reach PostHog — this pins the boundary.
	 */
	public function test_group_properties_exclude_identifying_fields(): void {
		$properties = ( new Analytics_Profile() )->get_group_properties();

		foreach ( array( 'site_domain', 'admin_domain', 'site_uuid', 'user_uuid', 'admin_email', 'site_url' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $properties, "Identifying field leaked: {$forbidden}" );
		}
	}

	/**
	 * Integrations can correct the multi-currency signal.
	 */
	public function test_multi_currency_is_filterable(): void {
		add_filter( 'woocommerce_pos_analytics_multi_currency', '__return_true' );
		$properties = ( new Analytics_Profile() )->get_group_properties();
		remove_filter( 'woocommerce_pos_analytics_multi_currency', '__return_true' );

		$this->assertTrue( $properties['multi_currency'] );
	}
}
