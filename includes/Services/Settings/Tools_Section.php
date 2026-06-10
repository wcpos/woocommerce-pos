<?php
/**
 * Tools Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

/**
 * The Tools Settings Section: developer/support toggles.
 */
class Tools_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'tools';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'use_jwt_as_param' => false,
		);
	}
}
