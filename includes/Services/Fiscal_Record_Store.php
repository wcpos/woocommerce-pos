<?php
/**
 * Write-once fiscal history.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Health;

/** Owns fiscal storage; no update or delete path is exposed. */
final class Fiscal_Record_Store {
	/** Stable suffix for site-local fiscal history. */
	public const TABLE = 'wcpos_fiscal_records';
	/** PHP validation keeps the portable schema free of ENUMs. */
	public const TYPES = array( 'sale', 'refund', 'void', 'cancellation', 'late_sale', 'late_movement', 'recount' );
	/** Bound payload-heavy read responses. */
	public const MAX_PER_PAGE = 200;
	/** Keep ordinary history reads small. */
	public const DEFAULT_PER_PAGE = 50;
	/** Serialize identity checks and per-type sequence allocation together. */
	private const SEQUENCE_LOCK = 'wcpos_fiscal_sequence_lock';
	/** Match the receipt sequence's bounded lock wait. */
	private const LOCK_WAIT_SECONDS = 5;
	/**
	 * Tables verified this request, keyed by site table name.
	 *
	 * @var array
	 */
	private static $installed = array();

	/** Resolve the current site's table. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Build the portable schema.
	 *
	 * @param string $table_name Table name.
	 * @param string $charset_collate Charset and collation.
	 */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL,
			series VARCHAR(64) NOT NULL DEFAULT '',
			number BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			refund_id BIGINT UNSIGNED NULL,
			payment_id CHAR(36) NULL,
			closure_id CHAR(36) NULL,
			source_id CHAR(36) NULL,
			corrects_record_id BIGINT UNSIGNED NULL,
			register_id CHAR(36) NULL,
			session_id CHAR(36) NULL,
			store_id BIGINT UNSIGNED NULL,
			cashier_id BIGINT UNSIGNED NULL,
			approver_id BIGINT UNSIGNED NULL,
			device_time VARCHAR(40) NULL,
			device_tz VARCHAR(64) NULL,
			received_at_gmt DATETIME NOT NULL,
			payload LONGTEXT NOT NULL,
			checksum CHAR(64) NOT NULL,
			print_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_printed_at_gmt DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_series_number (type, series, number),
			UNIQUE KEY type_source (type, source_id),
			KEY order_id (order_id),
			KEY register_id (register_id),
			KEY session_id (session_id),
			KEY closure_id (closure_id),
			KEY received_at_gmt (received_at_gmt)
		) {$charset_collate};";
	}

	/** Upgrade the closure link only when needed; callers install before transactions.
	 *
	 * @throws \RuntimeException On schema upgrade failure.
	 */
	public function install(): void {
		global $wpdb;
		if ( Health::table_exists( $this->table_name() ) ) {
			$table = $this->table_name();
			// The pre-closure schema used BIGINT; closure documents have client UUIDs.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
			$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'closure_id'", ARRAY_A );
			if ( $column && 'char(36)' !== strtolower( $column['Type'] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table and fixed definition.
				if ( false === $wpdb->query( "ALTER TABLE {$table} MODIFY closure_id CHAR(36) NULL" ) ) {
					throw new \RuntimeException( 'Fiscal closure link upgrade failed.' );
				}
			}
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/** Verify storage once per site per request. */
	public function ensure_installed(): void {
		$table = $this->table_name();
		if ( ! isset( self::$installed[ $table ] ) ) {
			$this->install();
			self::$installed[ $table ] = true;
		}
	}

	/**
	 * Insert once for the type's identity; replays return the original row.
	 *
	 * @param array $fields Record fields; payload is an array or callable receiving the minted number.
	 */
	public function record( array $fields ): ?array {
		global $wpdb;
		$type = $fields['type'] ?? '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}
		$identity = array(
			'sale' => 'order_id',
			'cancellation' => 'order_id',
			'late_sale' => 'order_id',
			'refund' => 'refund_id',
			'void' => 'payment_id',
		)[ $type ] ?? 'source_id';
		if ( empty( $fields[ $identity ] ) || ( ! is_array( $fields['payload'] ?? null ) && ! is_callable( $fields['payload'] ?? null ) ) || ( 'sale' === $type && empty( $fields['number'] ) ) ) {
			return null;
		}
		$this->ensure_installed();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::SEQUENCE_LOCK, self::LOCK_WAIT_SECONDS ) ) ) {
			Logger::log( 'Unable to acquire fiscal record sequence lock.' );
			return null;
		}
		try {
			$match = array(
				'type' => $type,
				$identity => $fields[ $identity ],
			);
			$existing = $this->find( $match );
			if ( $existing ) {
				return $existing;
			}
			$data = array_intersect_key( $fields, array_flip( array( 'type', 'series', 'order_id', 'refund_id', 'payment_id', 'closure_id', 'source_id', 'corrects_record_id', 'register_id', 'session_id', 'store_id', 'cashier_id', 'approver_id', 'device_time', 'device_tz' ) ) );
			$data['series'] = $data['series'] ?? '';
			if ( 'sale' === $type ) {
				$data['number'] = (int) $fields['number'];
			} else {
				$option = 'wcpos_fiscal_sequence_' . $type;
				// The lock protects the database, not a prior request-local option read.
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$data['number'] = (int) get_option( $option, 0 ) + 1;
				if ( ! update_option( $option, $data['number'], false ) ) {
					Logger::log( 'Unable to advance fiscal record sequence.' );
					return null;
				}
			}
			$payload = is_callable( $fields['payload'] ) ? $fields['payload']( $data['number'] ) : $fields['payload'];
			$json = is_array( $payload ) ? wp_json_encode( $payload ) : false;
			if ( ! is_string( $json ) ) {
				Logger::log( 'Unable to encode fiscal record payload.' );
				return null;
			}
			$data['payload'] = $json;
			$data['checksum'] = Receipt_Snapshot_Store::checksum( $json );
			$data['received_at_gmt'] = current_time( 'mysql', true );
			if ( false === $wpdb->insert( $this->table_name(), $data ) ) {
				// A duplicate-key race is a replay only for the same fiscal identity.
				$existing = $this->find( $match );
				if ( ! $existing ) {
					Logger::log( 'Unable to insert fiscal record.' );
				}
				return $existing;
			}
			return $this->get( (int) $wpdb->insert_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::SEQUENCE_LOCK ) );
		}
	}

	/**
	 * Read a record.
	 *
	 * @param int $id Record ID.
	 */
	public function get( int $id ): ?array {
		$this->ensure_installed();
		return $this->find( array( 'id' => $id ) );
	}

	/**
	 * Read an order's original sale.
	 *
	 * @param int $order_id Order ID.
	 */
	public function find_sale( int $order_id ): ?array {
		$this->ensure_installed();
		return $this->find(
			array(
				'type' => 'sale',
				'order_id' => $order_id,
			)
		);
	}

	/**
	 * Read a refund belonging to its parent order.
	 *
	 * @param int $order_id Parent ID.
	 * @param int $refund_id Refund ID.
	 */
	public function find_refund( int $order_id, int $refund_id ): ?array {
		$this->ensure_installed();
		return $this->find(
			array(
				'type' => 'refund',
				'order_id' => $order_id,
				'refund_id' => $refund_id,
			)
		);
	}

	/**
	 * Resolve a frozen refund document belonging to an order.
	 *
	 * @param int    $order_id Parent order ID.
	 * @param string $document Document selector.
	 * @return array|\WP_Error
	 */
	public function resolve_document( int $order_id, string $document ) {
		if ( ! preg_match( '/\Arefund:([1-9][0-9]*)\z/', $document, $matches ) ) {
			return new \WP_Error( 'wcpos_receipt_invalid_document', __( 'Invalid receipt document.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$record = $this->find_refund( $order_id, (int) $matches[1] );
		return $record ? $record['payload'] : new \WP_Error( 'wcpos_receipt_document_missing', __( 'Receipt document not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}

	/**
	 * Resolve an internal equality lookup.
	 *
	 * @param array $fields Class-owned column names and values.
	 */
	private function find( array $fields ): ?array {
		global $wpdb;
		$table = $this->table_name();
		$where = array();
		foreach ( $fields as $key => $value ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned column name.
			$where[] = $wpdb->prepare( "{$key} = %s", $value );
		}
		$where = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table and prepared predicates.
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE {$where} ORDER BY id LIMIT 1", ARRAY_A );
		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * List newest records first.
	 *
	 * @param array $args Filters and pagination.
	 */
	public function list( array $args ): array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		$where = $this->where( $args );
		$limit = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$offset = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table, prepared predicates and integer pagination.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
		return array_map( array( $this, 'normalize_row' ), $rows );
	}

	/**
	 * Count the same filtered collection without pagination.
	 *
	 * @param array $args Filters.
	 */
	public function count( array $args ): int {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		$where = $this->where( $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned table and prepared predicates.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * Build the shared list/count predicates.
	 *
	 * @param array $args Filters.
	 */
	private function where( array $args ): string {
		global $wpdb;
		$where = array( '1=1' );
		foreach ( array( 'order_id', 'register_id', 'session_id', 'closure_id', 'type' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted column.
				$where[] = $wpdb->prepare( "{$key} = %s", $args[ $key ] );
			}
		}
		foreach ( array(
			'after' => '>=',
			'before' => '<=',
		) as $key => $operator ) {
			if ( isset( $args[ $key ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Class-owned operator.
				$where[] = $wpdb->prepare( "received_at_gmt {$operator} %s", $args[ $key ] );
			}
		}
		if ( isset( $args['store_id'] ) ) {
			$ids = array_map( 'intval', (array) $args['store_id'] );
			$where[] = $ids ? 'store_id IN (' . implode( ',', $ids ) . ')' : '1=0';
		}
		return implode( ' AND ', $where );
	}

	/**
	 * Decode payload and database integer fields.
	 *
	 * @param array $row Database row.
	 */
	private function normalize_row( array $row ): array {
		foreach ( array( 'id', 'number', 'order_id', 'refund_id', 'corrects_record_id', 'store_id', 'cashier_id', 'approver_id', 'print_count' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
		}
		$row['payload'] = json_decode( $row['payload'], true );
		return $row;
	}

	/**
	 * Copy sale provenance without inventing missing values.
	 *
	 * @param WC_Order $order Source order.
	 */
	public function provenance_from_order( WC_Order $order ): array {
		$fields = array();
		foreach ( array(
			'register_id' => '_wcpos_register',
			'session_id' => '_wcpos_session',
			'store_id' => '_pos_store',
			'cashier_id' => '_pos_user',
			'device_time' => '_wcpos_sale_time',
			'device_tz' => '_wcpos_sale_tz',
		) as $key => $meta ) {
			$value = $order->get_meta( $meta, true );
			$fields[ $key ] = '' === $value ? null : $value;
		}
		return $fields;
	}
}
