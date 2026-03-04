<?php
/**
 * Test the update-available badge count in Admin Settings.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Settings;
use WCPOS\WooCommercePOS\Services\Extensions as ExtensionsService;
use WP_UnitTestCase;

/**
 * Test case for the extensions update badge count.
 */
class Test_Settings_Extensions_Badge extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_transient( ExtensionsService::TRANSIENT_KEY );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_transient( ExtensionsService::TRANSIENT_KEY );
		parent::tearDown();
	}

	/**
	 * Test returns null when catalog transient is empty.
	 */
	public function test_returns_null_when_no_catalog_cached(): void {
		$settings = new Settings();
		$method   = new \ReflectionMethod( $settings, 'get_update_available_count' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( $settings ) );
	}

	/**
	 * Test returns 0 when no extensions have updates.
	 */
	public function test_returns_zero_when_no_updates(): void {
		set_transient(
			ExtensionsService::TRANSIENT_KEY,
			array(
				array(
					'slug'           => 'nonexistent-extension-xyz',
					'latest_version' => '1.0.0',
				),
			),
			3600
		);

		$settings = new Settings();
		$method   = new \ReflectionMethod( $settings, 'get_update_available_count' );
		$method->setAccessible( true );

		$this->assertSame( 0, $method->invoke( $settings ) );
	}

	/**
	 * Test returns count of extensions with available updates.
	 */
	public function test_returns_count_of_updatable_extensions(): void {
		// Use WooCommerce as a known installed plugin.
		set_transient(
			ExtensionsService::TRANSIENT_KEY,
			array(
				array(
					'slug'           => 'woocommerce',
					'latest_version' => '999.0.0',
				),
				array(
					'slug'           => 'nonexistent-extension-xyz',
					'latest_version' => '1.0.0',
				),
			),
			3600
		);

		$settings = new Settings();
		$method   = new \ReflectionMethod( $settings, 'get_update_available_count' );
		$method->setAccessible( true );

		// Only woocommerce is installed and has a "newer" version.
		$this->assertSame( 1, $method->invoke( $settings ) );
	}
}
