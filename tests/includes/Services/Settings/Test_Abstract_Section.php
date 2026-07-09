<?php
/**
 * Tests for the Abstract Settings Section base class.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Abstract_Section;
use WP_UnitTestCase;

/**
 * Fixture section used to exercise the option-backed base behaviour.
 */
class Fixture_Section extends Abstract_Section {
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
 * Fixture overriding every template hook to record call order and inputs.
 */
class Hooked_Fixture_Section extends Abstract_Section {
	/**
	 * Recorded hook invocations, in order.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Raw array seen by migrate().
	 *
	 * @var array
	 */
	public $migrate_input;

	/**
	 * Merged array seen by compose().
	 *
	 * @var array
	 */
	public $compose_input;

	/**
	 * Filtered array seen by redact().
	 *
	 * @var array
	 */
	public $redact_input;

	/**
	 * Pre-stamp array seen by sanitize().
	 *
	 * @var array
	 */
	public $sanitize_input;

	/**
	 * Section id.
	 */
	public function id(): string {
		return 'hooked';
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

	/**
	 * Record migrate.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		$this->calls[]       = 'migrate';
		$this->migrate_input = $raw;

		return $raw;
	}

	/**
	 * Record compose.
	 *
	 * @param array $settings Merged settings.
	 */
	protected function compose( array $settings ): array {
		$this->calls[]       = 'compose';
		$this->compose_input = $settings;

		return $settings;
	}

	/**
	 * Record redact.
	 *
	 * @param array $settings Filtered settings.
	 */
	protected function redact( array $settings ): array {
		$this->calls[]        = 'redact';
		$this->redact_input   = $settings;
		$settings['redacted'] = true;

		return $settings;
	}

	/**
	 * Record sanitize.
	 *
	 * @param array $settings Settings about to be saved.
	 */
	protected function sanitize( array $settings ): array {
		$this->calls[]        = 'sanitize';
		$this->sanitize_input = $settings;

		return $settings;
	}
}

/**
 * Test_Abstract_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Abstract_Section extends WP_UnitTestCase {
	/**
	 * Clean the fixture option between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_fixture' );
		delete_option( 'woocommerce_pos_settings_hooked' );
		parent::tearDown();
	}

	/**
	 * Read merges defaults for missing keys but never overwrites stored values.
	 */
	public function test_read_merges_defaults_without_overwriting_stored_values(): void {
		update_option( 'woocommerce_pos_settings_fixture', array( 'alpha' => false ) );

		$section  = new Fixture_Section();
		$settings = $section->read();

		$this->assertFalse( $settings['alpha'] );
		$this->assertEquals( 'b-default', $settings['beta'] );
	}

	/**
	 * Read fires the woocommerce_pos_{id}_settings filter with the merged array.
	 */
	public function test_read_fires_section_filter(): void {
		$received = null;
		add_filter(
			'woocommerce_pos_fixture_settings',
			function ( $settings ) use ( &$received ) {
				$received             = $settings;
				$settings['filtered'] = true;

				return $settings;
			}
		);

		$section  = new Fixture_Section();
		$settings = $section->read();

		$this->assertIsArray( $received );
		$this->assertTrue( $settings['filtered'] );
		remove_all_filters( 'woocommerce_pos_fixture_settings' );
	}

	/**
	 * The template hooks run in the documented order with the documented
	 * inputs: migrate sees the raw pre-defaults array, compose sees the
	 * merged array, redact runs after the section filter, sanitize runs
	 * before the date stamp on write.
	 */
	public function test_template_hooks_run_in_documented_order(): void {
		$section = new Hooked_Fixture_Section();

		update_option( 'woocommerce_pos_settings_hooked', array( 'alpha' => false ) );
		add_filter(
			'woocommerce_pos_hooked_settings',
			function ( $settings ) {
				$settings['from_filter'] = true;

				return $settings;
			}
		);

		$view = $section->read();

		$this->assertEquals( array( 'migrate', 'compose', 'redact' ), $section->calls );
		$this->assertEquals( array( 'alpha' => false ), $section->migrate_input, 'migrate sees raw option, defaults not merged yet' );
		$this->assertArrayHasKey( 'beta', $section->compose_input, 'compose sees merged defaults' );
		$this->assertArrayHasKey( 'from_filter', $section->redact_input, 'redact runs after the section filter' );
		$this->assertTrue( $view['redacted'] );

		$section->calls = array();
		$section->write( array( 'alpha' => true ) );
		$this->assertEquals( 'sanitize', $section->calls[0], 'sanitize runs first on write' );
		$this->assertArrayNotHasKey( 'date_modified_gmt', $section->sanitize_input, 'sanitize runs before the date stamp' );

		remove_all_filters( 'woocommerce_pos_hooked_settings' );
	}

	/**
	 * Write persists, stamps date_modified_gmt, fires pre_save and saved hooks,
	 * and returns the post-save read.
	 */
	public function test_write_persists_and_fires_hooks(): void {
		$pre_save_payload = null;
		$saved_payload    = null;
		add_filter(
			'woocommerce_pos_pre_save_fixture_settings',
			function ( $settings, $id ) use ( &$pre_save_payload ) {
				$pre_save_payload = array( $settings, $id );

				return $settings;
			},
			10,
			2
		);
		add_action(
			'woocommerce_pos_saved_fixture_settings',
			function ( $settings, $id ) use ( &$saved_payload ) {
				$saved_payload = array( $settings, $id );
			},
			10,
			2
		);

		$section = new Fixture_Section();
		$result  = $section->write(
			array(
				'alpha' => false,
				'beta' => 'custom',
			)
		);

		$stored = get_option( 'woocommerce_pos_settings_fixture' );
		$this->assertEquals( 'custom', $stored['beta'] );
		$this->assertArrayHasKey( 'date_modified_gmt', $stored );
		$this->assertIsArray( $pre_save_payload );
		$this->assertEquals( 'fixture', $pre_save_payload[1] );
		$this->assertIsArray( $saved_payload );
		$this->assertEquals( 'custom', $result['beta'] );

		remove_all_filters( 'woocommerce_pos_pre_save_fixture_settings' );
		remove_all_actions( 'woocommerce_pos_saved_fixture_settings' );
	}

	/**
	 * Writing the identical payload twice is a no-op, not a WP_Error.
	 */
	public function test_write_noop_is_not_an_error(): void {
		$section = new Fixture_Section();
		$first   = $section->write( array( 'alpha' => true ) );
		$this->assertIsArray( $first );

		// Re-write the exact stored value (including the stamped date) — update_option
		// returns false for unchanged values; the base must detect the no-op.
		$stored = get_option( 'woocommerce_pos_settings_fixture' );
		unset( $stored['date_modified_gmt'] );
		// Freeze the timestamp so the second write is byte-identical.
		add_filter(
			'woocommerce_pos_pre_save_fixture_settings',
			function ( $settings ) {
				$settings['date_modified_gmt'] = get_option( 'woocommerce_pos_settings_fixture' )['date_modified_gmt'];

				return $settings;
			}
		);
		$saved_fired = 0;
		add_action(
			'woocommerce_pos_saved_fixture_settings',
			function () use ( &$saved_fired ) {
				++$saved_fired;
			}
		);
		$second = $section->write( $stored );
		$this->assertIsArray( $second, 'No-op write must not return WP_Error' );
		$this->assertEquals( 0, $saved_fired, 'saved action must not fire on a no-op write' );
		remove_all_actions( 'woocommerce_pos_saved_fixture_settings' );
		remove_all_filters( 'woocommerce_pos_pre_save_fixture_settings' );
	}

	/**
	 * Default merge is array_replace_recursive.
	 */
	public function test_default_merge_is_replace_recursive(): void {
		$section = new Fixture_Section();
		$merged  = $section->merge(
			array(
				'a' => array(
					'x' => 1,
					'y' => 2,
				),
				'b' => 1,
			),
			array( 'a' => array( 'y' => 3 ) )
		);
		$this->assertEquals(
			array(
				'a' => array(
					'x' => 1,
					'y' => 3,
				),
				'b' => 1,
			),
			$merged
		);
	}
}
