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

	/**
	 * The Settings facade routes get_settings/save_settings through a
	 * registered section, and fires the registration action once.
	 */
	public function test_facade_routes_through_registered_section(): void {
		$fired = 0;
		add_action(
			'woocommerce_pos_register_settings_sections',
			function ( $registry ) use ( &$fired ) {
				++$fired;
				$registry->register( new Registry_Fixture_Section() );
			}
		);

		// Force a fresh registry build: the facade is a singleton, so use a
		// dedicated method to reset it for tests.
		\WCPOS\WooCommercePOS\Services\Settings::instance()->reset_sections_for_testing();

		$settings = wcpos_get_settings( 'fixture' );
		$this->assertIsArray( $settings );
		$this->assertEquals( 'b-default', $settings['beta'] );

		$value = wcpos_get_settings( 'fixture', 'alpha' );
		$this->assertTrue( $value );

		$error = wcpos_get_settings( 'fixture', 'missing_key' );
		$this->assertInstanceOf( \WP_Error::class, $error );

		// Accessing settings again must not re-fire registration.
		wcpos_get_settings( 'fixture' );
		$this->assertEquals( 1, $fired );

		remove_all_actions( 'woocommerce_pos_register_settings_sections' );
		\WCPOS\WooCommercePOS\Services\Settings::instance()->reset_sections_for_testing();
		delete_option( 'woocommerce_pos_settings_fixture' );
	}
}
