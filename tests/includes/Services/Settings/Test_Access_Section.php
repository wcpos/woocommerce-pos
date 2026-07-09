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
	 * Granting a capability listed in the access settings view succeeds.
	 */
	public function test_write_grants_in_group_capability(): void {
		$role = get_role( 'subscriber' );
		$role->remove_cap( 'access_woocommerce_pos' );

		$result = $this->section->write(
			array(
				'subscriber' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);

		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'access_woocommerce_pos' ) );
		$this->assertTrue( $result['subscriber']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * Granting a capability added via the woocommerce_pos_access_settings filter succeeds.
	 */
	public function test_write_grants_filter_added_capability(): void {
		$capability = 'wcpos_extension_test_cap';
		$role       = get_role( 'subscriber' );
		$role->remove_cap( $capability );

		$filter = static function ( array $settings ) use ( $capability ): array {
			foreach ( array_keys( $settings ) as $slug ) {
				$role = get_role( $slug );

				$settings[ $slug ]['capabilities']['extensions'][ $capability ] = $role
					? $role->has_cap( $capability )
					: false;
			}

			return $settings;
		};

		add_filter( 'woocommerce_pos_access_settings', $filter );

		try {
			$result = $this->section->write(
				array(
					'subscriber' => array(
						'capabilities' => array(
							'extensions' => array(
								$capability => true,
							),
						),
					),
				)
			);

			$this->assertTrue( get_role( 'subscriber' )->has_cap( $capability ) );
			$this->assertTrue( $result['subscriber']['capabilities']['extensions'][ $capability ] );
		} finally {
			remove_filter( 'woocommerce_pos_access_settings', $filter );
		}
	}

	/**
	 * A capability outside the access settings view is ignored.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/1159
	 */
	public function test_write_ignores_out_of_band_capability(): void {
		$role = get_role( 'subscriber' );
		$role->remove_cap( 'manage_options' );

		$this->section->write(
			array(
				'subscriber' => array(
					'capabilities' => array(
						'wp' => array(
							'manage_options' => true,
						),
					),
				),
			)
		);

		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'manage_options' ) );
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
		$cashier_role = get_role( 'cashier' );
		$this->assertNotNull( $cashier_role, 'Expected cashier role to be registered in test fixtures.' );
		$this->assertFalse( $cashier_role->has_cap( 'edit_others_products' ) );

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
		$cashier_role = get_role( 'cashier' );
		$this->assertNotNull( $cashier_role, 'Expected cashier role to be registered in test fixtures.' );
		$this->assertFalse( $cashier_role->has_cap( 'edit_others_products' ) );
	}
}
