<?php
/**
 * Register resource storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Owns the registers table; callers validate fields. */
final class Register_Store {
	/** Table suffix. */
	public const TABLE = 'wcpos_registers';

	/**
	 * Whether the table was verified this request.
	 *
	 * @var bool
	 */
	private static $installed = false;

	/**
	 * Create the table if an install predates it (dbDelta is idempotent; the
	 * check is memoised per request so the cost is one SHOW TABLES).
	 */
	public function ensure_installed(): void {
		if ( self::$installed ) {
			return;
		}
		if ( ! \WCPOS\WooCommercePOS\Sync\Health::table_exists( $this->table_name() ) ) {
			$this->install();
		}
		self::$installed = true;
	}

	/** Resolve the current site's table. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Build the portable register schema.
	 *
	 * @param string $table_name Table name.
	 * @param string $charset_collate Charset and collation.
	 */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (
			id CHAR(36) NOT NULL,
			name VARCHAR(191) NOT NULL,
			store_id BIGINT NULL,
			default_float DECIMAL(19,4) NULL,
			platform VARCHAR(16) NOT NULL DEFAULT '',
			app_version VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			counters_started_at_gmt DATETIME NULL,
			created_at_gmt DATETIME NOT NULL,
			last_seen_at_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY store_status (store_id, status)
		) {$charset_collate};";
	}

	/**
	 * Install the table, independently of sync health. A no-op once the table
	 * exists: dbDelta is DDL, and DDL commits any open transaction (the PHPUnit
	 * per-test transaction included), so it must not run on every upgrade pass.
	 */
	public function install(): void {
		global $wpdb;
		if ( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $this->table_name() ) ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Register a till, or refresh only its client-owned fields.
	 *
	 * @param array $fields Validated fields.
	 * @throws \RuntimeException When storage fails.
	 */
	public function upsert( array $fields ): array {
		global $wpdb;
		$this->ensure_installed();
		$id = $fields['id'];
		$data = array_intersect_key( $fields, array_flip( array( 'platform', 'app_version' ) ) );
		$data['last_seen_at_gmt'] = gmdate( 'Y-m-d H:i:s' );
		if ( $this->exists( $id ) ) {
			// An existing register only refreshes what the till reports about
			// itself; its name is set at creation and renamed only through PATCH.
			$result = $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );
		} else {
			$data['id'] = $id;
			$data['name'] = $fields['name'];
			$data['store_id'] = $fields['store_id'] ?? null;
			$data['created_at_gmt'] = $data['last_seen_at_gmt'];
			$result = $wpdb->insert( $this->table_name(), $data );
			if ( false === $result && $this->exists( $id ) ) {
				// Two first registrations raced on the primary key: the loser touches
				// the row the winner created instead of failing the till's sign-in.
				unset( $data['id'], $data['name'], $data['store_id'], $data['created_at_gmt'] );
				$result = $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );
			}
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Register write failed.' );
		}
		return $this->get( $id );
	}

	/**
	 * Read one register.
	 *
	 * @param string $id Register UUID.
	 */
	public function get( string $id ): ?array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );
		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * List registers by name, optionally scoped by store and status.
	 *
	 * @param array $args Filters.
	 */
	public function list( array $args ): array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		$where = array( '1=1' );
		if ( array_key_exists( 'store_id', $args ) ) {
			$where[] = null === $args['store_id'] ? 'store_id IS NULL' : $wpdb->prepare( 'store_id = %d', $args['store_id'] );
		}
		if ( isset( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		$where = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table and prepared predicates.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY name", ARRAY_A );
		return array_map( array( $this, 'normalize_row' ), $rows );
	}

	/**
	 * Edit admin-owned fields; Pro may explicitly pass store_id.
	 *
	 * @param string $id Register UUID.
	 * @param array  $fields Validated admin fields.
	 * @throws \RuntimeException When storage fails.
	 */
	public function update( string $id, array $fields ): ?array {
		global $wpdb;
		if ( ! $this->exists( $id ) ) {
			return null;
		}
		$data = array_intersect_key( $fields, array_flip( array( 'name', 'default_float', 'status', 'store_id' ) ) );
		if ( $data && false === $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) ) ) {
			throw new \RuntimeException( 'Register write failed.' );
		}
		return $this->get( $id );
	}

	/**
	 * Whether a register is known.
	 *
	 * @param string $id Register UUID.
	 */
	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * Convert database types to the REST resource types.
	 *
	 * @param array $row Database row.
	 */
	private function normalize_row( array $row ): array {
		$row['store_id'] = null === $row['store_id'] ? null : (int) $row['store_id'];
		$row['default_float'] = null === $row['default_float'] ? null : wc_format_decimal( $row['default_float'], 4 );
		foreach ( array( 'counters_started_at_gmt', 'created_at_gmt', 'last_seen_at_gmt' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? null : str_replace( ' ', 'T', $row[ $key ] ) . 'Z';
		}
		return $row;
	}
}
