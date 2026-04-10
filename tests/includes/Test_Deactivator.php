<?php
/**
 * Tests for the Deactivator class.
 *
 * Covers the activation/deactivation lifecycle to ensure POS capabilities
 * are properly removed on deactivation and restored on reactivation.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Deactivator;

/**
 * Tests for Deactivator behavior.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Deactivator
 */
class Test_Deactivator extends WP_UnitTestCase {
	/**
	 * Map of roles to the POS capabilities they should receive during activation.
	 *
	 * @var array<string, string[]>
	 */
	private const ROLE_CAPS = array(
		'administrator' => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
		'shop_manager'  => array( 'manage_woocommerce_pos', 'access_woocommerce_pos' ),
		'cashier'       => array( 'access_woocommerce_pos' ),
	);

	/**
	 * Ensure capabilities are in a known state before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->run_single_activate();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Restore capabilities so other tests are unaffected.
		$this->run_single_activate();
		parent::tearDown();
	}

	/**
	 * Test that deactivation removes POS capabilities from all roles.
	 *
	 * @covers ::single_deactivate
	 */
	public function test_deactivation_removes_pos_capabilities(): void {
		// Sanity check: caps exist before deactivation.
		$this->assertTrue(
			get_role( 'administrator' )->has_cap( 'manage_woocommerce_pos' ),
			'Precondition failed: administrator should have manage_woocommerce_pos before deactivation'
		);

		$this->run_single_deactivate();

		foreach ( self::ROLE_CAPS as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$this->assertFalse(
					$role->has_cap( $cap ),
					"$role_slug should NOT have $cap after deactivation"
				);
			}
		}
	}

	/**
	 * Test that reactivation after deactivation restores POS capabilities.
	 *
	 * Regression test: deactivating the plugin removed capabilities, and if the
	 * activation hook did not fire reliably on reactivation the admin menu would
	 * silently disappear because Menu::__construct() gates on
	 * current_user_can('manage_woocommerce_pos').
	 *
	 * @covers ::single_deactivate
	 */
	public function test_reactivation_restores_pos_capabilities(): void {
		$this->run_single_deactivate();

		// Verify caps are gone.
		$this->assertFalse(
			get_role( 'administrator' )->has_cap( 'manage_woocommerce_pos' ),
			'Precondition: administrator should NOT have manage_woocommerce_pos after deactivation'
		);

		// Reactivate.
		$this->run_single_activate();

		foreach ( self::ROLE_CAPS as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$this->assertTrue(
					$role->has_cap( $cap ),
					"$role_slug should have $cap after reactivation"
				);
			}
		}
	}

	/**
	 * Test that multiple deactivation/reactivation cycles don't lose capabilities.
	 *
	 * Simulates a user toggling the plugin on and off multiple times.
	 *
	 * @covers ::single_deactivate
	 */
	public function test_multiple_deactivation_reactivation_cycles_preserve_capabilities(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->run_single_deactivate();
			$this->run_single_activate();
		}

		foreach ( self::ROLE_CAPS as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$this->assertTrue(
					$role->has_cap( $cap ),
					"$role_slug should have $cap after multiple deactivation/reactivation cycles"
				);
			}
		}
	}

	/**
	 * Test the full scenario: deactivate → activate free → deactivate free → reactivate.
	 *
	 * This simulates the exact flow that triggered the original bug: a user
	 * deactivates Pro, tries the free plugin, deactivates free, then reactivates
	 * Pro. Each deactivation strips capabilities, and the final reactivation must
	 * restore them. Both Pro and free use the same Activator/Deactivator classes
	 * so this test covers both plugins.
	 *
	 * @covers ::single_deactivate
	 */
	public function test_switching_between_pro_and_free_preserves_capabilities(): void {
		// Step 1: Deactivate (Deactivator fires).
		$this->run_single_deactivate();
		$this->assertFalse(
			get_role( 'administrator' )->has_cap( 'manage_woocommerce_pos' ),
			'Caps should be removed after deactivation'
		);

		// Step 2: Activate free plugin.
		$this->run_single_activate();
		$this->assertTrue(
			get_role( 'administrator' )->has_cap( 'manage_woocommerce_pos' ),
			'Caps should be restored after free activation'
		);

		// Step 3: Deactivate free plugin.
		$this->run_single_deactivate();
		$this->assertFalse(
			get_role( 'administrator' )->has_cap( 'manage_woocommerce_pos' ),
			'Caps should be removed after free deactivation'
		);

		// Step 4: Reactivate (Activator fires).
		$this->run_single_activate();

		foreach ( self::ROLE_CAPS as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$this->assertTrue(
					$role->has_cap( $cap ),
					"$role_slug should have $cap after reactivation (step 4)"
				);
			}
		}
	}

	/**
	 * Test that the cashier role itself is preserved after deactivation.
	 *
	 * Deactivation should strip capabilities but not remove the role, so that
	 * users assigned to the cashier role are not orphaned.
	 *
	 * @covers ::single_deactivate
	 */
	public function test_deactivation_preserves_cashier_role(): void {
		$this->assertNotNull(
			get_role( 'cashier' ),
			'Precondition: cashier role should exist before deactivation'
		);

		$this->run_single_deactivate();

		$this->assertNotNull(
			get_role( 'cashier' ),
			'Cashier role should still exist after deactivation (only caps are removed)'
		);
	}

	/**
	 * Helper: run the Activator's single_activate() logic.
	 */
	private function run_single_activate(): void {
		$activator  = new Activator();
		$reflection = new \ReflectionClass( $activator );
		$method     = $reflection->getMethod( 'single_activate' );
		$method->setAccessible( true );
		$method->invoke( $activator );
	}

	/**
	 * Helper: run the Deactivator's single_deactivate() logic.
	 */
	private function run_single_deactivate(): void {
		$deactivator = new Deactivator();
		$reflection  = new \ReflectionClass( $deactivator );
		$method      = $reflection->getMethod( 'single_deactivate' );
		$method->setAccessible( true );
		$method->invoke( $deactivator );
	}
}
