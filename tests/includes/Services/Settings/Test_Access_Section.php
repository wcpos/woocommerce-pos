<?php
/**
 * Tests for the Access Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WP_UnitTestCase;

/**
 * Test_Access_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Access_Section extends WP_UnitTestCase {
	/**
	 * Section under test.
	 *
	 * @var Access_Section
	 */
	private $section;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->section = new Access_Section();
	}

	/**
	 * Tear down: reset current user and flush in-memory role state so capability-mutation
	 * tests cannot bleed into each other.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring role state after capability-mutation tests.
		parent::tearDown();
	}

	/**
	 * Verify read() returns role capability groups structured by wcpos/wc/wp keys.
	 */
	public function test_read_returns_role_capability_groups(): void {
		$settings = $this->section->read();

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'administrator', $settings );

		$admin = $settings['administrator'];
		$this->assertArrayHasKey( 'name', $admin );
		$this->assertArrayHasKey( 'capabilities', $admin );
		$this->assertArrayHasKey( 'wcpos', $admin['capabilities'] );
		$this->assertArrayHasKey( 'wc', $admin['capabilities'] );
		$this->assertArrayHasKey( 'wp', $admin['capabilities'] );
		$this->assertArrayHasKey( 'access_woocommerce_pos', $admin['capabilities']['wcpos'] );
		$this->assertArrayHasKey( 'read', $admin['capabilities']['wp'] );
		$this->assertTrue( $admin['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $admin['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * Verify write() grants/revokes a capability on the cashier role and returns the fresh view.
	 */
	public function test_write_grants_and_revokes_capability_on_cashier_role(): void {
		// Grant access_woocommerce_pos to the cashier role.
		$result = $this->section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cashier', $result );
		$this->assertTrue( $result['cashier']['capabilities']['wcpos']['access_woocommerce_pos'] );

		// Now revoke it.
		$result = $this->section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => false,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cashier']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * The administrator `read` capability cannot be removed via write().
	 */
	public function test_write_cannot_remove_administrator_read_capability(): void {
		// Attempt to revoke the `read` capability from the administrator role.
		$result = $this->section->write(
			array(
				'administrator' => array(
					'capabilities' => array(
						'wp' => array(
							'read' => false,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		// The sanity check skips this mutation; administrator must still have read = true.
		$this->assertTrue( $result['administrator']['capabilities']['wp']['read'] );
	}

	/**
	 * Without edit_users + promote_users, write() refuses with a 403 WP_Error
	 * and mutates nothing.
	 */
	public function test_write_requires_capabilities(): void {
		wp_set_current_user( 0 );

		// Confirm the cashier role does not have edit_others_products before we attempt the write.
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_others_products' ) );

		$section = new Access_Section();
		$result  = $section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wc' => array( 'edit_others_products' => true ),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
		// Capability must not have been mutated.
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_others_products' ) );
	}
}
