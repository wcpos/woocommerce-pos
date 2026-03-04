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
	 * Invoke the private get_update_available_count method via reflection.
	 */
	private function invoke_get_update_available_count(): ?int {
		$settings = new Settings();
		$method   = new \ReflectionMethod( $settings, 'get_update_available_count' );
		$method->setAccessible( true );

		return $method->invoke( $settings );
	}

	/**
	 * Test returns null when catalog transient is empty.
	 */
	public function test_returns_null_when_no_catalog_cached(): void {
		$this->assertNull( $this->invoke_get_update_available_count() );
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

		$this->assertSame( 0, $this->invoke_get_update_available_count() );
	}

	/**
	 * Test returns count of extensions with available updates.
	 */
	public function test_returns_count_of_updatable_extensions(): void {
		if ( ! \function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Find a plugin whose path contains a directory (slug/file.php).
		$slug = null;
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( str_contains( (string) $plugin_file, '/' ) ) {
				$slug = dirname( (string) $plugin_file );
				break;
			}
		}
		$this->assertNotNull( $slug, 'No directory-based plugin found in test environment' );

		set_transient(
			ExtensionsService::TRANSIENT_KEY,
			array(
				array(
					'slug'           => $slug,
					'latest_version' => '999.0.0',
				),
				array(
					'slug'           => 'nonexistent-extension-xyz',
					'latest_version' => '1.0.0',
				),
			),
			3600
		);

		// Only the detected installed plugin has a "newer" version.
		$this->assertSame( 1, $this->invoke_get_update_available_count() );
	}
}
