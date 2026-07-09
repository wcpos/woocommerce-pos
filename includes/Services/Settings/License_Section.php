<?php
/**
 * License Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

/**
 * The License Settings Section.
 *
 * In the free plugin this is a shim: the view is whatever the Pro plugin
 * injects via the woocommerce_pos_license_settings filter (empty array
 * otherwise). A follow-up Pro PR replaces the filter hook with a real
 * registered section via woocommerce_pos_register_settings_sections.
 *
 * NOTE: write() is not overridden — it inherits Abstract_Section::write()
 * and persists to woocommerce_pos_settings_license — but read() never
 * consults that option; persisted data is silently ignored until Pro
 * overrides this section. (Legacy save_settings('license') behaved the
 * same way; preserved deliberately.)
 */
class License_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'license';
	}

	/**
	 * No defaults; the view is Pro-injected.
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Read the license view from the Pro-injected filter.
	 */
	public function read(): array {
		/**
		 * Filters the license settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings The license settings.
		 *
		 * @hook woocommerce_pos_license_settings
		 */
		return apply_filters( 'woocommerce_pos_license_settings', array() );
	}
}
