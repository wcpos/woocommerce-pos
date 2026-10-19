<?php
/**
 * Legacy table probes for the session-row folds.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync;
use WCPOS\WooCommercePOS\Sync\Health;

/** Which legacy tables of a fold still exist, read only until the fold's latch is set. */
final class Legacy_Tables {
	/** Every existing legacy name of a fold, or none once the latch reaches the fold's schema.
	 *
	 * A fold renames its table before the drop, and a pre-fold request can recreate the old
	 * name beside an interrupted rename, so both names are probed. The latch is autoloaded:
	 * a folded site never probes.
	 *
	 * @param string $suffix Legacy table suffix, without the site prefix.
	 * @param string $folded_at Sync schema whose latch proves the fold is done.
	 * @param string $warning Warning logged when a probe fails.
	 * @param string $failure Exception message when a probe fails.
	 * @return string[] Full table names.
	 * @throws \RuntimeException When a probe fails; a caller must not assume absence.
	 */
	public static function pending( string $suffix, string $folded_at, string $warning, string $failure ): array {
		global $wpdb;
		$latch = get_option( Sync\Api::SCHEMA_OPTION, null );
		if ( null !== $latch && version_compare( (string) $latch, $folded_at, '>=' ) ) {
			return array();
		}
		$tables = array();
		foreach ( array( $suffix, $suffix . '_folded' ) as $name ) {
			$exists = Health::table_exists( $wpdb->prefix . $name );
			if ( '' !== $wpdb->last_error ) {
				Logger::warning( $warning );
				throw new \RuntimeException( esc_html( $failure ) );
			}
			if ( $exists ) {
				$tables[] = $wpdb->prefix . $name;
			}
		}
		return $tables;
	}
}
