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
 * The digest's SQL is MySQL/MariaDB only (BIT_XOR, CONV, GROUP_CONCAT …
 * SEPARATOR, ON DUPLICATE KEY, SET SESSION), so on a site running the SQLite
 * Database Integration drop-in it fails on every save and every scan (#2661).
 * There the digest is switched off and sync relies on the sequence log alone.
 * This does not make SQLite a supported database.
 *
 * Both signals come from that drop-in, which defines `DB_ENGINE` as 'sqlite'
 * and replaces `$wpdb` with a `WP_SQLite_DB`. Neither runs a query, and both
 * are false on MySQL/MariaDB. `wpdb::db_server_info()` and `wpdb::$is_mysql`
 * are not used: what the drop-in returns for them is unverified.
 *
 * No public filter: the override below is test tooling only.
 */
final class Digest_Support {

	/**
	 * The memoised answer for this request; null until first asked.
	 *
	 * @var bool|null
	 */
	private static ?bool $disabled = null;

	/**
	 * The forced answer in tests; null detects.
	 *
	 * @var bool|null
	 */
	private static ?bool $override = null;

	/**
	 * Whether the integrity digest is switched off (the database is SQLite).
	 */
	public static function is_disabled(): bool {
		if ( null !== self::$override ) {
			return self::$override;
		}
		if ( null === self::$disabled ) {
			self::$disabled = ( \defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE )
				|| ( class_exists( 'WP_SQLite_DB', false ) && $GLOBALS['wpdb'] instanceof \WP_SQLite_DB );
		}

		return self::$disabled;
	}

	/**
	 * Force the answer in tests; null clears the override.
	 *
	 * @internal
	 *
	 * @param bool|null $disabled Forced answer, or null to detect.
	 */
	public static function set_override_for_tests( ?bool $disabled ): void {
		self::$override = $disabled;
	}

	/**
	 * Clear the memoised answer and the test override.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$disabled = null;
		self::$override = null;
	}
}
