<?php
/**
 * Section Registry.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;

/**
 * The Section Registry — the seam where Settings Sections are registered.
 *
 * The free plugin registers its core sections; Pro and extensions register
 * theirs via the `woocommerce_pos_register_settings_sections` action (added in a later phase of this refactor) instead
 * of hooking ad-hoc filters. Registering an existing id replaces the previous
 * section (last-wins), which is also the supported override mechanism.
 * Override sections should extend Abstract_Section (not implement the
 * interface directly) so the typed accessors' default fallback
 * (Settings::section_value()) keeps working.
 */
class Section_Registry {
	/**
	 * Registered sections, keyed by id.
	 *
	 * @var array<string, Settings_Section_Interface>
	 */
	private $sections = array();

	/**
	 * Register (or replace) a section.
	 *
	 * @param Settings_Section_Interface $section The section.
	 */
	public function register( Settings_Section_Interface $section ): void {
		$this->sections[ $section->id() ] = $section;
	}

	/**
	 * Get a section by id.
	 *
	 * @param string $id Section id.
	 *
	 * @return Settings_Section_Interface|null
	 */
	public function get( string $id ): ?Settings_Section_Interface {
		return $this->sections[ $id ] ?? null;
	}

	/**
	 * Whether a section is registered.
	 *
	 * @param string $id Section id.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->sections[ $id ] );
	}

	/**
	 * All registered sections, keyed by id.
	 *
	 * @return array<string, Settings_Section_Interface>
	 */
	public function all(): array {
		return $this->sections;
	}
}
