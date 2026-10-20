<?php
/**
 * Shared create-if-missing plumbing for owned tables.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Health;

/**
 * An Owned Table is created only when missing: DDL commits any open transaction
 * (the PHPUnit per-test transaction included), so it must not run on every upgrade
 * pass. A column change ships as a schema-latch upgrade, never as an install() diff.
 * The using class declares TABLE and schema_sql().
 */
trait Owned_Table {
	/**
	 * Tables verified this request, keyed by full table name so each site is checked once.
	 *
	 * @var array<string,bool>
	 */
	private static $installed = array();

	/**
	 * Build the table schema.
	 *
	 * @param string $table_name      Full table name.
	 * @param string $charset_collate Charset and collation.
	 * @return string Schema SQL.
	 */
	abstract public function schema_sql( string $table_name, string $charset_collate = '' ): string;

	/** Resolve the current site's table. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Install without DDL when the table already exists. */
	public function install(): void {
		$this->create_table_if_missing();
	}

	/** Verify once per site per request. */
	public function ensure_installed(): void {
		$table = $this->table_name();
		if ( ! isset( self::$installed[ $table ] ) ) {
			$this->install();
			self::$installed[ $table ] = true;
		}
	}

	/**
	 * Create a missing table without changing an existing schema.
	 *
	 * WordPress's dbDelta() reports nothing when the CREATE fails (a denied CREATE privilege, a
	 * full disk), and the till tables are outside Sync\Health's required set, so the
	 * failure would otherwise first surface as a bare insert error at the till. Log
	 * it here, once per request, with the database's own reason.
	 */
	protected function create_table_if_missing(): void {
		global $wpdb;
		$table = $this->table_name();
		if ( Health::table_exists( $table ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_sql( $table, $wpdb->get_charset_collate() ) );
		$db_error = (string) $wpdb->last_error; // Read before the probe below clears it.
		if ( ! Health::table_exists( $table ) ) {
			Logger::error(
				'Table could not be created',
				array(
					'table'    => $table,
					'db_error' => $db_error,
				)
			);
		}
	}
}
