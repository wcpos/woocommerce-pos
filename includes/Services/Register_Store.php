<?php
/**
 * Register resource storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

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
	 * Create a server-owned register.
	 *
	 * @param array                 $fields Validated fields.
	 * @param \WP_REST_Request|null $request REST create context for the Pro fields filter.
	 * @throws \RuntimeException When storage fails.
	 */
	public function create( array $fields, ?\WP_REST_Request $request = null ): array {
		global $wpdb;
		$this->ensure_installed();
		$id           = wp_generate_uuid4();
		$fields['id'] = $id;
		if ( null !== $request ) {
			/** Filter initial registration fields; Pro may set store_id. */
			$fields = apply_filters( 'woocommerce_pos_register_upsert_fields', $fields, $request );
		}
		$fields['id'] = $id;
		$now = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'id' => $fields['id'],
			'name' => $fields['name'],
			'store_id' => $fields['store_id'] ?? null,
			'default_float' => $fields['default_float'] ?? null,
			'status' => 'active',
			'created_at_gmt' => $now,
			'last_seen_at_gmt' => $now,
		);
		if ( false === $wpdb->insert( $this->table_name(), $data ) ) {
			Logger::warning(
				'Register write refused: storage operation failed',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Register write failed.' );
		}
		$row = $this->get( $fields['id'] );
		Logger::log(
			'Register created',
			array(
				'register_id' => $row['id'],
				'name' => $row['name'],
				'default_float' => $row['default_float'],
				'user_id' => get_current_user_id(),
			)
		);
		return $row;
	}

	/** Seed an active register during activation and upgrade only. */
	public function ensure_default(): void {
		if ( ! $this->list( array( 'status' => 'active' ) ) ) {
			$this->create( array( 'name' => __( 'Register', 'woocommerce-pos' ) ) );
		}
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
	 * @throws \RuntimeException When latest closure counters cannot be read.
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
		$latest = array_fill_keys( array_column( $rows, 'id' ), array() );
		if ( $rows ) {
			$closures = new Closure_Store();
			$closures->ensure_installed();
			$closure_table = $closures->table_name();
			$placeholders = implode( ', ', array_fill( 0, count( $rows ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table and generated placeholders.
			$sql = $wpdb->prepare( "SELECT closure.register_id, closure.number, closure.perpetual_sales_total, closure.perpetual_refunds_total FROM {$closure_table} closure INNER JOIN ( SELECT register_id, MAX(number) AS number FROM {$closure_table} WHERE register_id IN ({$placeholders}) GROUP BY register_id ) latest_closure ON latest_closure.register_id = closure.register_id AND latest_closure.number = closure.number", array_column( $rows, 'id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			$closure_rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Register closure read failed.' );
			}
			foreach ( $closure_rows as $closure ) {
				$latest[ $closure['register_id'] ] = $closure;
			}
		}
		return array_map(
			function ( $row ) use ( $latest ) {
				return $this->normalize_row( $row, $latest[ $row['id'] ] );
			},
			$rows
		);
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
		$before = $this->get( $id );
		if ( ! $before ) {
			Logger::warning(
				'Register update refused: register_id not found',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
				)
			);
			return null;
		}
		$data = array_intersect_key( $fields, array_flip( array( 'name', 'default_float', 'status', 'store_id' ) ) );
		if ( $data && false === $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) ) ) {
			Logger::warning(
				'Register write refused: storage operation failed',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Register write failed.' );
		}
		$row = $this->get( $id );
		$changed = array();
		foreach ( array_keys( $data ) as $key ) {
			if ( $before[ $key ] !== $row[ $key ] ) {
				$changed[] = $key;
			}
		}
		if ( $changed ) {
			Logger::log(
				'Register changed',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
					'fields' => $changed,
					'before' => array_intersect_key( $before, array_flip( $changed ) ),
					'after' => array_intersect_key( $row, array_flip( $changed ) ),
				)
			);
		}
		return $row;
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
	 * @param array      $row Database row.
	 * @param null|array $closure Preloaded latest closure; null loads it here.
	 */
	private function normalize_row( array $row, ?array $closure = null ): array {
		$row['store_id'] = null === $row['store_id'] ? null : (int) $row['store_id'];
		$row['default_float'] = null === $row['default_float'] ? null : wc_format_decimal( $row['default_float'], 4 );
		foreach ( array( 'counters_started_at_gmt', 'created_at_gmt', 'last_seen_at_gmt' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? null : str_replace( ' ', 'T', $row[ $key ] ) . 'Z';
		}
		$closure = null === $closure ? ( new Closure_Store() )->last( $row['id'] ) : $closure;
		$row['counters'] = array(
			'last_closure_number' => isset( $closure['number'] ) ? (int) $closure['number'] : 0,
			'perpetual_sales_total' => $closure['perpetual_sales_total'] ?? '0',
			'perpetual_refunds_total' => $closure['perpetual_refunds_total'] ?? '0',
		);
		return $row;
	}
}
