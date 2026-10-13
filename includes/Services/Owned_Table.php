<?php
/**
 * Shared create-if-missing plumbing for owned tables.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

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

	/** Create a missing table without changing an existing schema. */
	protected function create_table_if_missing(): void {
		global $wpdb;
		if ( Health::table_exists( $this->table_name() ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
	}
}
