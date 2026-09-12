<?php
/**
 * Register session storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Health;

/** Owns sessions independently of WooCommerce order storage. */
final class Register_Session_Store {
	public const TABLE = 'wcpos_register_sessions';
	/** Tables verified this request.
	 *
	 * @var array
	 */
	private static $installed = array();

	/** Resolve the site table. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Build the schema.
	 *
	 * @param string $table_name Table.
	 * @param string $charset_collate Collation.
	 */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (
			id CHAR(36) NOT NULL,
			register_id CHAR(36) NOT NULL,
			store_id BIGINT NULL,
			status VARCHAR(16) NOT NULL,
			opened_at_gmt DATETIME NOT NULL,
			opened_by BIGINT NOT NULL,
			expected_float DECIMAL(19,4) NULL,
			counted_float DECIMAL(19,4) NOT NULL,
			opening_variance DECIMAL(19,4) NULL,
			counting_started_at_gmt DATETIME NULL,
			closed_at_gmt DATETIME NULL,
			closed_by BIGINT NULL,
			approved_by BIGINT NULL,
			counted LONGTEXT NULL,
			closure_id CHAR(36) NULL,
			created_at_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY register_status (register_id, status),
			KEY store_status (store_id, status)
		) {$charset_collate};";
	}

	/** Install without DDL when already present. */
	public function install(): void {
		global $wpdb;
		if ( ! Health::table_exists( $this->table_name() ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
		}
	}

	/** Verify once per site per request. */
	public function ensure_installed(): void {
		if ( ! isset( self::$installed[ $this->table_name() ] ) ) {
			$this->install();
			self::$installed[ $this->table_name() ] = true;
		}
	}

	/** Read one row.
	 *
	 * @param string $id UUID.
	 */
	public function get( string $id ): ?array {
		$rows = $this->list( array( 'id' => $id ) );
		return $rows[0] ?? null;
	}

	/** List newest sessions with optional equality filters.
	 *
	 * @param array $args Filters and pagination.
	 */
	public function list( array $args ): array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		$where = array( '1=1' );
		foreach ( array( 'id', 'register_id', 'status', 'store_id' ) as $key ) {
			if ( array_key_exists( $key, $args ) && 'all' !== $args[ $key ] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted column.
				$where[] = null === $args[ $key ] ? "{$key} IS NULL" : $wpdb->prepare( "{$key} = %s", $args[ $key ] );
			}
		}
		if ( ! empty( $args['not_closed'] ) ) {
			$where[] = "status != 'closed'";
		}
		$where = implode( ' AND ', $where );
		$limit = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, prepared predicates, integer pagination.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY opened_at_gmt DESC, id DESC LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
		foreach ( $rows as &$row ) {
			foreach ( array( 'store_id', 'opened_by', 'closed_by', 'approved_by' ) as $key ) {
				$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
			}
			$row['counted'] = null === $row['counted'] ? null : json_decode( $row['counted'], true );
		}
		return $rows;
	}

	/** Insert validated opening fields; replay preserves the original.
	 *
	 * @return array|\WP_Error
	 * @param array $fields Opening fields.
	 * @throws \RuntimeException On database failure.
	 */
	public function create( array $fields ) {
		global $wpdb;
		$existing = $this->get( $fields['id'] );
		if ( $existing ) {
			// An idempotent replay, not a fault: the outbox retries a write whose response
			// was lost, and returning the existing row IS the success path. A warning here
			// would appear in the merchant's log for every recovered network timeout.
			Logger::log(
				'Register session already recorded; returning the existing row',
				array(
					'session_id' => $existing['id'],
					'register_id' => $existing['register_id'],
				)
			);
			return $existing;
		}
		$open = $this->list(
			array(
				'register_id' => $fields['register_id'],
				'not_closed' => true,
			)
		);
		if ( $open ) {
			Logger::warning(
				'Register session opening refused: register already has an open session',
				array(
					'register_id' => $fields['register_id'],
					'session_id' => $open[0]['id'],
					'requested_session_id' => $fields['id'],
					'status' => $open[0]['status'],
					'user_id' => get_current_user_id(),
				)
			);
			return new \WP_Error(
				'wcpos_session_already_open',
				__( 'This register already has an open session.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'session_id' => $open[0]['id'],
				)
			);
		}
		$fields['status'] = 'open';
		$fields['created_at_gmt'] = current_time( 'mysql', true );
		$fields['opening_variance'] = null === $fields['expected_float'] ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT CAST(%s AS DECIMAL(19,4)) - CAST(%s AS DECIMAL(19,4))', $fields['counted_float'], $fields['expected_float'] ) );
		if ( false === $wpdb->insert( $this->table_name(), $fields ) ) {
			Logger::warning(
				'Register session write refused: storage operation failed',
				array(
					'session_id' => $fields['id'],
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Session write failed.' );
		}
		$row = $this->get( $fields['id'] );
		Logger::log(
			'Register session opened',
			array(
				'session_id' => $row['id'],
				'register_id' => $row['register_id'],
				'counted_float' => $row['counted_float'],
				'user_id' => $row['opened_by'],
			)
		);
		return $row;
	}

	/** Stamp a manager approval only while the session is counting.
	 *
	 * @param array $session Current row.
	 * @param int   $user_id Approving manager.
	 * @return array|\WP_Error
	 * @throws \RuntimeException On database failure.
	 */
	public function approve( array $session, int $user_id ) {
		global $wpdb;
		$updated = $wpdb->update(
			$this->table_name(),
			array( 'approved_by' => $user_id ),
			array(
				'id' => $session['id'],
				'status' => 'counting',
			)
		);
		if ( false === $updated ) {
			Logger::warning(
				'Register session write refused: storage operation failed',
				array(
					'session_id' => $session['id'],
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Session write failed.' );
		}
		if ( 0 === $updated ) {
			Logger::warning(
				'Register session approval refused: status changed or approval already recorded',
				array(
					'session_id' => $session['id'],
					'user_id' => $user_id,
					'status' => $this->get( $session['id'] )['status'] ?? null,
				)
			);
			return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		Logger::log(
			'Register session approved',
			array(
				'session_id' => $session['id'],
				'user_id' => $user_id,
			)
		);
		return $this->get( $session['id'] );
	}

	/** Apply a validated transition, conditional on the observed state.
	 *
	 * @return array|\WP_Error
	 *
	 * @param array $session Current row.
	 * @param array $fields Transition fields.
	 * @throws \RuntimeException On database failure.
	 */
	public function transition( array $session, array $fields ) {
		global $wpdb;
		if ( 'counting' === $session['status'] && 'closed' === $fields['status'] ) {
			$threshold = Settings::instance()->get_general_settings()['variance_threshold'] ?? '';
			$threshold = apply_filters( 'woocommerce_pos_session_variance_threshold', $threshold, $session );
			if ( null === $session['approved_by'] && ! isset( $fields['approved_by'] ) && is_string( $threshold ) && preg_match( '/^\d+(?:\.\d+)?$/D', $threshold ) ) {
				// Truncate, not round: a four-decimal variance above the threshold must still require approval.
				$comparison_threshold = preg_replace( '/(\.\d{4})\d+$/D', '$1', $threshold );
				$variance = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT variance, ABS(variance) > CAST(%s AS DECIMAL(65,4)) AS exceeds_threshold FROM (SELECT CAST(%s AS DECIMAL(65,4)) - CAST(%s AS DECIMAL(65,4)) AS variance) AS amounts',
						$comparison_threshold,
						$fields['counted']['cash'],
						$this->expected( $session )['cash']
					),
					ARRAY_A
				);
				if ( null === $variance ) {
					Logger::warning( 'Register session closing refused: variance calculation failed', array( 'session_id' => $session['id'] ) );
					throw new \RuntimeException( 'Session variance calculation failed.' );
				}
				if ( '1' === $variance['exceeds_threshold'] ) {
					Logger::warning(
						'Register session closing refused: variance requires manager approval',
						array(
							'session_id' => $session['id'],
							'variance' => $variance['variance'],
							'threshold' => $threshold,
							'user_id' => get_current_user_id(),
						)
					);
					return new \WP_Error(
						'wcpos_override_refused',
						__( 'Manager approval is required to close this session.', 'woocommerce-pos' ),
						array(
							'status' => 403,
							'variance' => $variance['variance'],
							'threshold' => $threshold,
						)
					);
				}
			}
		}
		if ( isset( $fields['counted'] ) ) {
			$fields['counted'] = wp_json_encode( $fields['counted'] );
		}
		$updated = $wpdb->update(
			$this->table_name(),
			$fields,
			array(
				'id' => $session['id'],
				'status' => $session['status'],
			)
		);
		if ( false === $updated ) {
			Logger::warning(
				'Register session write refused: storage operation failed',
				array(
					'session_id' => $session['id'],
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Session write failed.' );
		}
		$row = $this->get( $session['id'] );
		if ( 0 === $updated && ( $row['status'] ?? null ) !== $fields['status'] ) {
			Logger::warning(
				'Register session transition refused: status changed',
				array(
					'session_id' => $session['id'],
					'from' => $session['status'],
					'to' => $fields['status'],
					'status' => $row['status'] ?? null,
					'user_id' => get_current_user_id(),
				)
			);
			return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		// Closure_Store logs its transition after commit, not before a possible rollback.
		if ( $updated > 0 && $session['status'] !== $fields['status'] && ! isset( $fields['closure_id'] ) ) {
			Logger::log(
				'Register session state changed',
				array(
					'session_id' => $session['id'],
					'from' => $session['status'],
					'to' => $fields['status'],
					'user_id' => get_current_user_id(),
					'approved_by' => $row['approved_by'],
				)
			);
		}
		return $row;
	}

	/** Derive four-decimal tender balances, without floating-point arithmetic.
	 *
	 * @param array      $session Session row.
	 * @param array|null $orders Captured orders already read for this report.
	 * @param array|null $movements Movements already read for this report.
	 * @throws \RuntimeException On calculation failure.
	 */
	public function expected( array $session, ?array $orders = null, ?array $movements = null ): array {
		global $wpdb;
		$totals = array( 'cash' => array( $session['counted_float'] ) );
		foreach ( $orders ?? $this->captured_orders( $session ) as $rows ) {
			foreach ( $rows as $row ) {
				// Every cash-kind gateway (pos_cash, cod, an extension's cash tender) is the drawer.
				$method = $row['method'] ?? $row['method_id'];
				$method = 'cash' === ( $row['kind'] ?? '' ) ? 'cash' : ( array( 'pos_card' => 'card' )[ $method ] ?? $method );
				$totals[ $method ][] = $row['amount'];
				// A refund is the original row's refunded_amount (the ledger keeps no refund row).
				$totals[ $method ][] = '-' . ltrim( $row['refunded_amount'] ?? '0', '-' );
			}
		}
		foreach ( $movements ?? ( new Cash_Movement_Store() )->list( $session['id'] ) as $row ) {
			if ( null === $row['voided_by'] && in_array( $row['type'], array( 'paid_in', 'paid_out' ), true ) ) {
				$totals['cash'][] = ( 'paid_out' === $row['type'] ? '-' : '' ) . $row['amount'];
			}
		}
		foreach ( $totals as &$amounts ) {
			$sql = 'SELECT ' . implode( ' + ', array_fill( 0, count( $amounts ), 'CAST(%s AS DECIMAL(65,4))' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only decimal cast placeholders above.
			$amounts = $wpdb->get_var( $wpdb->prepare( $sql, $amounts ) );
			if ( null === $amounts ) {
				throw new \RuntimeException( 'Session expected calculation failed.' );
			}
		}
		return $totals;
	}

	/** Count distinct orders with captured session rows.
	 *
	 * @param array $session Session row.
	 */
	public function sales_count( array $session ): int {
		return count( $this->captured_orders( $session ) );
	}

	/** Read all captured session rows in either storage mode; fiscal totals cannot truncate.
	 *
	 * @param array $session Session row.
	 * @throws \RuntimeException On read failure.
	 */
	public function captured_orders( array $session ): array {
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$key = $hpos ? 'order_id' : 'post_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage-selected identifiers.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$key} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY {$key} DESC", Ledger::META_KEY, '%' . $wpdb->esc_like( '"session_id":"' . $session['id'] . '"' ) . '%' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session ledger read failed.' );
		}
		$result = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				throw new \RuntimeException( 'Session order could not be read.' );
			}
			foreach ( Ledger::instance()->read( $order ) as $row ) {
				if ( ( $row['session_id'] ?? null ) === $session['id'] && 'captured' === ( $row['status'] ?? null ) ) {
					$result[ $id ][] = $row;
				}
			}
		}
		return $result;
	}
}
