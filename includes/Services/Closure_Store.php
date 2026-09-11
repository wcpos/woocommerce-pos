<?php
/**
 * Immutable register closures.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Sync\Health;

/** Owns closure documents; only print bookkeeping can change. */
final class Closure_Store {
	public const TABLE = 'wcpos_closures';
	/** Verified site tables.
	 *
	 * @var array
	 */
	private static $installed = array();

	/** Resolve the site table. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Build the portable schema.
	 *
	 * @param string $table_name Table.
	 * @param string $charset_collate Collation.
	 */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (
			id CHAR(36) NOT NULL,
			register_id CHAR(36) NOT NULL,
			session_id CHAR(36) NOT NULL,
			store_id BIGINT NULL,
			number BIGINT NOT NULL,
			printed_number BIGINT NULL,
			opened_at_gmt DATETIME NOT NULL,
			closed_at_gmt DATETIME NOT NULL,
			opened_by BIGINT NOT NULL,
			closed_by BIGINT NOT NULL,
			approved_by BIGINT NULL,
			expected LONGTEXT NOT NULL,
			till_expected LONGTEXT NOT NULL,
			counted LONGTEXT NOT NULL,
			variance LONGTEXT NOT NULL,
			period_sales_total DECIMAL(19,4) NOT NULL,
			period_refunds_total DECIMAL(19,4) NOT NULL,
			perpetual_sales_total DECIMAL(19,4) NOT NULL,
			perpetual_refunds_total DECIMAL(19,4) NOT NULL,
			unsynced_count INT NOT NULL,
			unsynced_total DECIMAL(19,4) NOT NULL,
			first_sale_counter BIGINT NULL,
			last_sale_counter BIGINT NULL,
			first_receipt_id BIGINT NULL,
			last_receipt_id BIGINT NULL,
			software_version VARCHAR(64) NOT NULL,
			printed_at_gmt DATETIME NULL,
			breakdowns LONGTEXT NOT NULL,
			findings LONGTEXT NULL,
			print_count INT NOT NULL DEFAULT 0,
			last_printed_at_gmt DATETIME NULL,
			received_at_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY register_number (register_id, number),
			UNIQUE KEY session_id (session_id),
			KEY store_closed (store_id, closed_at_gmt)
		) {$charset_collate};";
	}

	/** Install beside sessions, never running DDL inside a write transaction. */
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

	/** Read a document.
	 *
	 * @param string $id UUID.
	 */
	public function get( string $id ): ?array {
		return $this->list( array( 'id' => $id ) )[0] ?? null;
	}

	/** Find the immutable document for a session.
	 *
	 * @param string $id Session UUID.
	 */
	public function for_session( string $id ): ?array {
		return $this->list( array( 'session_id' => $id ) )[0] ?? null;
	}

	/** Latest numbered document, independently of a till's clock.
	 *
	 * @param string $register_id Register UUID.
	 */
	public function last( string $register_id ): ?array {
		return $this->list(
			array(
				'register_id' => $register_id,
				'number_order' => true,
				'per_page' => 1,
			)
		)[0] ?? null;
	}

	/** Last number, or zero before the first closure.
	 *
	 * @param string $register_id Register UUID.
	 */
	public function last_number( string $register_id ): int {
		return $this->last( $register_id )['number'] ?? 0;
	}

	/** List newest first with allowlisted filters.
	 *
	 * @param array $args Filters and paging.
	 * @throws \RuntimeException On read failure.
	 */
	public function list( array $args ): array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		$where = array( '1=1' );
		foreach ( array( 'id', 'session_id', 'register_id', 'number', 'store_id' ) as $key ) {
			if ( 'store_id' === $key && isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$ids = array_map( 'intval', $args[ $key ] );
				$where[] = $ids ? 'store_id IN (' . implode( ',', $ids ) . ')' : '1=0';
			} elseif ( array_key_exists( $key, $args ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted column.
				$where[] = null === $args[ $key ] ? "{$key} IS NULL" : $wpdb->prepare( "{$key} = %s", $args[ $key ] );
			}
		}
		foreach ( array(
			'after' => '>=',
			'before' => '<=',
		) as $key => $operator ) {
			if ( isset( $args[ $key ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed operator.
				$where[] = $wpdb->prepare( "closed_at_gmt {$operator} %s", $args[ $key ] );
			}
		}
		$where = implode( ' AND ', $where );
		$limit = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $limit;
		$order = empty( $args['number_order'] ) ? 'closed_at_gmt DESC, number DESC, id DESC' : 'number DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, prepared predicates and integer pagination.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Closure read failed.' );
		}
		foreach ( $rows as &$row ) {
			foreach ( array( 'number', 'printed_number', 'store_id', 'opened_by', 'closed_by', 'approved_by', 'unsynced_count', 'first_sale_counter', 'last_sale_counter', 'first_receipt_id', 'last_receipt_id', 'print_count' ) as $key ) {
				$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
			}
			foreach ( array( 'expected', 'till_expected', 'counted', 'variance', 'breakdowns', 'findings' ) as $key ) {
				$row[ $key ] = null === $row[ $key ] ? null : json_decode( $row[ $key ], true );
			}
		}
		return $rows;
	}

	/** Exact decimal addition shared by totals and correction deltas.
	 *
	 * @param array $amounts Signed decimal strings.
	 * @throws \RuntimeException On calculation failure.
	 */
	public static function sum( array $amounts ): string {
		global $wpdb;
		$sql = 'SELECT ' . implode( ' + ', array_fill( 0, count( $amounts ? $amounts : array( '0' ) ), 'CAST(%s AS DECIMAL(65,4))' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Decimal placeholders only.
		$result = $wpdb->get_var( $wpdb->prepare( $sql, $amounts ? $amounts : array( '0' ) ) );
		if ( null === $result ) {
			throw new \RuntimeException( 'Closure calculation failed.' );
		}
		return $result;
	}

	/** Counted minus frozen expected, only for counted tenders.
	 *
	 * @param array $counted Tender counts.
	 * @param array $expected Tender expectations.
	 */
	public function variance( array $counted, array $expected ): array {
		foreach ( $counted as $method => &$amount ) {
			$value = $expected[ $method ] ?? '0';
			$negative = '-' === substr( $value, 0, 1 ) ? substr( $value, 1 ) : '-' . $value;
			$amount = self::sum( array( $amount, $negative ) );
		}
		return $counted;
	}

	/** Write once, serializing the register's baseline with one conditional UPDATE.
	 *
	 * @param array     $fields Validated client fields.
	 * @param bool|null $created Whether this call inserted the document.
	 * @param-out bool $created
	 * @return array|\WP_Error
	 * @throws \RuntimeException On database failure; the entire close is rolled back.
	 */
	public function create( array $fields, ?bool &$created = null ) {
		global $wpdb;
		$created = false;
		$existing = $this->get( $fields['id'] );
		if ( $existing ) {
			return $existing;
		}
		$sessions = new Register_Session_Store();
		$session = $sessions->get( $fields['session_id'] );
		if ( ! $session || ! ( new Register_Store() )->exists( $session['register_id'] ) || ! in_array( $session['status'], array( 'counting', 'closed' ), true ) ) {
			return new \WP_Error( 'wcpos_closure_session_invalid', __( 'The session cannot be closed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		( new Cash_Movement_Store() )->ensure_installed();
		( new Fiscal_Record_Store() )->ensure_installed();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Closure transaction failed.' );
		}
		$rolled_back = false;
		try {
			$table = ( new Register_Store() )->table_name();
			// The row lock lasts through commit; subsequent reads see the preceding closure.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned register table.
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET counters_started_at_gmt = COALESCE(counters_started_at_gmt, %s) WHERE id = %s", $session['opened_at_gmt'], $session['register_id'] ) ) ) {
				throw new \RuntimeException( 'Closure counter start failed.' );
			}
			$existing = $this->get( $fields['id'] );
			if ( $existing ) {
				return $existing;
			}
			$existing = $this->for_session( $session['id'] );
			if ( $existing ) {
				// The recount is written outside the closure transaction: the finally block
				// must not roll it back with the abandoned closure attempt.
				$wpdb->query( 'ROLLBACK' );
				$rolled_back = true;
				$this->recount( $existing, $fields['id'], $fields['counted'], '' );
				return new \WP_Error(
					'wcpos_closure_exists',
					__( 'This session already has a closure; the count was recorded as a recount.', 'woocommerce-pos' ),
					array(
						'status' => 409,
						'closure_id' => $existing['id'],
					)
				);
			}
			$session = $sessions->get( $session['id'] );
			if ( ! in_array( $session['status'], array( 'counting', 'closed' ), true ) ) {
				return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			}
			$previous = $this->last( $session['register_id'] );
			$next = ( $previous['number'] ?? 0 ) + 1;
			$fields['printed_number'] = null;
			if ( $this->list(
				array(
					'register_id' => $session['register_id'],
					'number' => $fields['number'],
				)
			) ) {
				$fields['printed_number'] = $fields['number'];
				$fields['number'] = $next;
			} elseif ( $fields['number'] < $next ) {
				return new \WP_Error( 'wcpos_closure_number_invalid', __( 'The closure number precedes the register sequence.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			}
			$fields['expected'] = $sessions->expected( $session );
			$fields['variance'] = $this->variance( $fields['counted'], $fields['expected'] );
			$totals = array(
				'sales' => array(),
				'refunds' => array(),
			);
			foreach ( $sessions->captured_orders( $session ) as $rows ) {
				foreach ( $rows as $row ) {
					$refund = 'refund' === $row['kind'] || '-' === substr( $row['amount'], 0, 1 );
					$totals[ $refund ? 'refunds' : 'sales' ][] = ltrim( $row['amount'], '-' );
					$totals['refunds'][] = $row['refunded_amount'] ?? '0';
				}
			}
			$findings = array();
			foreach ( $totals as $kind => $amounts ) {
				$period = self::sum( $amounts );
				foreach ( array(
					'period' => $period,
					'perpetual' => self::sum( array( $previous[ 'perpetual_' . $kind . '_total' ] ?? '0', $period ) ),
				) as $scope => $derived ) {
					$key = $scope . '_' . $kind . '_total';
					if ( ! preg_match( '/^\d{1,15}\.\d{4}$/D', $derived ) ) {
						throw new \RuntimeException( 'Closure total exceeds storage precision.' );
					}
					if ( '0.0000' !== self::sum( array( $fields[ $key ], '-' . $derived ) ) ) {
						$findings['total_mismatch'][ $key ] = array(
							'reported' => $fields[ $key ],
							'derived' => $derived,
						);
					}
					$fields[ $key ] = $derived;
				}
			}
			$fields['findings'] = $findings ? $findings : null;
			$fields += array_intersect_key( $session, array_flip( array( 'register_id', 'store_id', 'opened_by', 'approved_by' ) ) );
			$fields['closed_by'] = 'closed' === $session['status'] ? $session['closed_by'] : get_current_user_id();
			$fields['received_at_gmt'] = current_time( 'mysql', true );
			$table = ( new Fiscal_Record_Store() )->table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned fiscal table.
			$receipts = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(id) AS first_receipt_id, MAX(id) AS last_receipt_id FROM {$table} WHERE session_id = %s AND type = 'sale'", $session['id'] ), ARRAY_A );
			if ( null === $receipts ) {
				throw new \RuntimeException( 'Closure receipt read failed.' );
			}
			$fields += $receipts;
			$result = $sessions->transition(
				$session,
				array(
					'status' => 'closed',
					'closure_id' => $fields['id'],
				) + ( 'counting' === $session['status'] ? array(
					'closed_at_gmt' => $fields['closed_at_gmt'],
					'closed_by' => $fields['closed_by'],
					'counted' => $fields['counted'],
				) : array() )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( array( 'expected', 'till_expected', 'counted', 'variance', 'breakdowns', 'findings' ) as $key ) {
				$fields[ $key ] = null === $fields[ $key ] ? null : wp_json_encode( $fields[ $key ] );
				if ( false === $fields[ $key ] ) {
					throw new \RuntimeException( 'Closure JSON encoding failed.' );
				}
			}
			if ( false === $wpdb->insert( $this->table_name(), $fields ) || false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Closure write failed.' );
			}
			$created = true;
		} finally {
			if ( ! $created && ! $rolled_back ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
		return $this->get( $fields['id'] );
	}

	/** Append a replay-safe recount; never update the document.
	 *
	 * @param array  $closure Frozen document.
	 * @param string $id Client source UUID.
	 * @param array  $counted New counts.
	 * @param string $reason Operator reason.
	 * @throws \RuntimeException On write failure.
	 */
	public function recount( array $closure, string $id, array $counted, string $reason ): array {
		$row = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'recount',
				'source_id' => $id,
				'closure_id' => $closure['id'],
				'register_id' => $closure['register_id'],
				'session_id' => $closure['session_id'],
				'store_id' => $closure['store_id'],
				'cashier_id' => get_current_user_id(),
				'payload' => array(
					'counted' => $counted,
					'variance' => $this->variance( $counted, $closure['expected'] ),
					'reason' => $reason,
				),
			)
		);
		if ( null === $row || $row['closure_id'] !== $closure['id'] ) {
			throw new \RuntimeException( 'Closure recount failed.' );
		}
		return $row;
	}

	/** Increment only mutable print bookkeeping.
	 *
	 * @param string $id Closure UUID.
	 * @throws \RuntimeException On write failure.
	 */
	public function record_print( string $id ): ?array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET print_count = print_count + 1, last_printed_at_gmt = %s WHERE id = %s", current_time( 'mysql', true ), $id ) ) ) {
			throw new \RuntimeException( 'Closure print stamp failed.' );
		}
		return $this->get( $id );
	}

	/** List immutable findings and missing number ranges for one register.
	 *
	 * @param string $register_id Register UUID.
	 */
	public function health( string $register_id ): array {
		$result = array(
			'closure_gaps' => array(),
			'closure_conflicts' => array(),
			'closure_total_mismatches' => array(),
		);
		$previous = null;
		$page = 1;
		do {
			$rows = $this->list(
				array(
					'register_id' => $register_id,
					'number_order' => true,
					'per_page' => 100,
					'page' => $page++,
				)
			);
			foreach ( $rows as $row ) {
				if ( null !== $previous && $previous - $row['number'] > 1 ) {
					$result['closure_gaps'][] = array(
						'after' => $row['number'],
						'before' => $previous,
						'missing' => $previous - $row['number'] - 1,
					);
				}
				foreach ( array(
					'printed_number' => 'closure_conflicts',
					'findings' => 'closure_total_mismatches',
				) as $key => $list ) {
					if ( null !== $row[ $key ] ) {
						$result[ $list ][] = array(
							'id' => $row['id'],
							'number' => $row['number'],
							$key => $row[ $key ],
						);
					}
				}
				$previous = $row['number'];
			}
			$size = count( $rows );
		} while ( 100 === $size );
		if ( null !== $previous && $previous > 1 ) {
			$result['closure_gaps'][] = array(
				'after' => 0,
				'before' => $previous,
				'missing' => $previous - 1,
			);
		}
		return $result;
	}
}
