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
	 * Clean the option between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_uuid' );
		parent::tearDown();
	}

	/**
	 * Generates once, persists, and returns the same value thereafter.
	 */
	public function test_generates_once_and_is_stable(): void {
		delete_option( 'woocommerce_pos_uuid' );

		$first  = wcpos_get_site_uuid();
		$second = wcpos_get_site_uuid();

		$this->assertNotEmpty( $first );
		$this->assertEquals( $first, $second );
		$this->assertEquals( $first, get_option( 'woocommerce_pos_uuid' ) );
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
		$this->assertEquals( 'existing-uuid-value', wcpos_get_site_uuid() );
	}
}
