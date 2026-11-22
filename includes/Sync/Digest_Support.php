<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Whether the integrity digest runs on this database engine.
 *
 * Stub: the digest is never switched off.
 */
final class Digest_Support {

	/**
	 * Whether the integrity digest is switched off.
	 */
	public static function is_disabled(): bool {
		return false;
	}

	/**
	 * Force the answer in tests; null clears the override.
	 *
	 * @internal
	 *
	 * @param bool|null $disabled Forced answer, or null to detect.
	 */
	public static function set_override_for_tests( ?bool $disabled ): void {
	}

	/**
	 * Clear the memoised answer and the test override.
	 *
	 * @internal
	 */
	public static function reset(): void {
	}
}
