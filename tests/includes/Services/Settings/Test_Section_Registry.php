<?php
/**
 * Tests for the Section Registry.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Abstract_Section;
use WCPOS\WooCommercePOS\Services\Settings\Section_Registry;
use WP_UnitTestCase;

/**
 * Registry fixture section. Defined locally — each phpunit invocation runs a
 * single file, so test classes must not depend on classes declared in other
 * test files.
 */
class Registry_Fixture_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'fixture';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'alpha' => true,
			'beta'  => 'b-default',
		);
	}
}

/**
 * Test_Section_Registry class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Section_Registry extends WP_UnitTestCase {
	/**
	 * Register and retrieve a section by id; unknown ids return null.
	 */
	public function test_register_get_and_unknown(): void {
		$registry = new Section_Registry();
		$section  = new Registry_Fixture_Section();

		$registry->register( $section );

		$this->assertSame( $section, $registry->get( 'fixture' ) );
		$this->assertTrue( $registry->has( 'fixture' ) );
		$this->assertNull( $registry->get( 'nope' ) );
		$this->assertFalse( $registry->has( 'nope' ) );
		$this->assertEquals( array( 'fixture' => $section ), $registry->all() );
	}

	/**
	 * Registering the same id twice replaces the first (last-wins override).
	 */
	public function test_register_same_id_last_wins(): void {
		$registry = new Section_Registry();
		$first    = new Registry_Fixture_Section();
		$second   = new Registry_Fixture_Section();

		$registry->register( $first );
		$registry->register( $second );

		$this->assertSame( $second, $registry->get( 'fixture' ) );
	}
}
