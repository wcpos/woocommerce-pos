<?php
/**
 * In-memory Settings Section fixture.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WCPOS\WooCommercePOS\Services\Settings\Abstract_Section;
use WCPOS\WooCommercePOS\Services\Settings\Section_Registry;

/**
 * A stand-in for an extension-owned Settings Section.
 *
 * Registers through the public `woocommerce_pos_register_settings_sections`
 * seam exactly the way Pro's License_Section does, so tests can prove that a
 * section registered by a third party gets a working HTTP surface — GET, POST,
 * and endpoint-arg validation — without the free plugin knowing it exists.
 *
 * Storage is in memory: the fixture never touches wp_options, so it cannot
 * leak state into option-based fixtures.
 */
class Fixture_Settings_Section extends Abstract_Section {
	/**
	 * Section id. The underscore also exercises the id -> dashed-slug rule
	 * (route: /settings/test-fixture).
	 */
	const ID = 'test_fixture';

	/**
	 * In-memory store, shared by every instance of the fixture.
	 *
	 * @var array
	 */
	private static $store = array();

	/**
	 * Hook the registration action. Call before rest_api_init runs.
	 */
	public static function register(): void {
		add_action( 'woocommerce_pos_register_settings_sections', array( __CLASS__, 'register_section' ) );
	}

	/**
	 * Unhook the registration action.
	 */
	public static function unregister(): void {
		remove_action( 'woocommerce_pos_register_settings_sections', array( __CLASS__, 'register_section' ) );
	}

	/**
	 * Register this section on the given registry.
	 *
	 * @param Section_Registry $registry The Section Registry.
	 */
	public static function register_section( Section_Registry $registry ): void {
		$registry->register( new self() );
	}

	/**
	 * Empty the in-memory store.
	 */
	public static function reset(): void {
		self::$store = array();
	}

	/**
	 * Section id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'alpha' => 'default-alpha',
			'beta'  => 0,
		);
	}

	/**
	 * Read the in-memory view, defaults merged.
	 */
	public function read(): array {
		return array_merge( $this->defaults(), self::$store );
	}

	/**
	 * Persist to the in-memory store.
	 *
	 * @param array $settings Full settings array.
	 *
	 * @return array
	 */
	public function write( array $settings ) {
		self::$store = $settings;

		return $this->read();
	}

	/**
	 * REST endpoint args, so the projected POST route validates payloads.
	 */
	public function endpoint_args(): array {
		return array(
			'alpha' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'beta'  => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_int( $param );
				},
			),
		);
	}
}
