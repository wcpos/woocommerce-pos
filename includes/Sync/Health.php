<?php
/**
 * Sync store health checks.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Checks whether the sync store tables are installed.
 */
final class Health {
	public const ORDER_INDEX_TABLE   = 'wcpos_sync_order_index';
	public const CHANGE_LOG_TABLE    = 'wcpos_sync_change_log';
	public const STORED_DIGEST_TABLE = 'wcpos_sync_stored_digest';
	public const MUTATIONS_TABLE     = 'wcpos_sync_mutations';

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table Fully qualified table name.
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Get the fully qualified sync store table names.
	 *
	 * @return string[] Sync store table names.
	 */
	public static function required_tables(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . self::ORDER_INDEX_TABLE,
			$wpdb->prefix . self::CHANGE_LOG_TABLE,
			$wpdb->prefix . self::STORED_DIGEST_TABLE,
			$wpdb->prefix . self::MUTATIONS_TABLE,
		);
	}

	/**
	 * Get sync store tables that have not been installed.
	 *
	 * @return string[] Missing sync store table names.
	 */
	public static function missing_tables(): array {
		$missing_tables = array();

		foreach ( self::required_tables() as $table ) {
			if ( ! self::table_exists( $table ) ) {
				$missing_tables[] = $table;
			}
		}

		return $missing_tables;
	}

	/**
	 * Check whether all required sync store tables exist.
	 */
	public static function is_healthy(): bool {
		return 0 === \count( self::missing_tables() );
	}

	/**
	 * Get the error returned when the sync store is unavailable.
	 */
	public static function unhealthy_error(): \WP_Error {
		return new \WP_Error(
			'wcpos_sync_unavailable',
			__( 'The WCPOS sync store is not installed yet (initialising or a schema install failed). Retry shortly.', 'woocommerce-pos' ),
			array( 'status' => 503 )
		);
	}
}
