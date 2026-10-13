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
		return array();
	}

	/**
	 * Drop the retired `use_jwt_as_param` toggle from stored options, in memory only.
	 *
	 * The admin toggle (2023-07 to 2026-04) was never enforced: the app probes the
	 * host and keeps its own flag, and `?authorization=` is accepted on every REST
	 * request. Sites that saved it still hold the key, so it is removed on read.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		unset( $raw['use_jwt_as_param'] );

		return $raw;
	}
}
