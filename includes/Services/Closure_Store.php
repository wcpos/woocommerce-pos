<?php
/**
 * Immutable register closures.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Owns closure documents; only print bookkeeping can change.
 *
 * A closure is its closed session row: the session table carries the closure UUID,
 * the register-sequenced number, print bookkeeping and the frozen document as JSON.
 */
final class Closure_Store {
	/** The pre-schema-10 table; sync-schema step 10 copies it into sessions and drops it. */
	public const LEGACY_TABLE = 'wcpos_closures';

	/** Shared cap for list consumers, including the whole-store export. */
	public const MAX_PER_PAGE = 100;

	/** How far a till's number may run ahead of the register sequence: wide enough for any
	 * real run of offline closes, narrow enough that a cashier cannot push the sequence
	 * toward the 18-digit limit. */
	private const MAX_NUMBER_JUMP = 10000;

	/**
	 * The closure row shape, in order: each key maps to the session column it is read
	 * from, or null when it lives in the frozen `closure` document.
	 */
	private const ROW = array(
		'id' => 'closure_id',
		'register_id' => 'register_id',
		'session_id' => 'id',
		'store_id' => 'store_id',
		'number' => 'number',
		'printed_number' => 'printed_number',
		'opened_at_gmt' => 'opened_at_gmt',
		'business_day' => 'business_day',
		'closed_at_gmt' => 'closed_at_gmt',
		'opened_by' => 'opened_by',
		'closed_by' => null,
		'approved_by' => null,
		'expected' => null,
		'till_expected' => null,
		'counted' => null,
		'variance' => null,
		'period_sales_total' => null,
		'period_refunds_total' => null,
		'perpetual_sales_total' => null,
		'perpetual_refunds_total' => null,
		'unsynced_count' => null,
		'unsynced_total' => null,
		'first_sale_counter' => null,
		'last_sale_counter' => null,
		'first_receipt_id' => null,
		'last_receipt_id' => null,
		'software_version' => null,
		'printed_at_gmt' => null,
		'breakdowns' => null,
		'findings' => null,
		'print_count' => 'print_count',
		'last_printed_at_gmt' => 'last_printed_at_gmt',
		'received_at_gmt' => null,
		// The till's own timestamps when they differ from the session's (a deferred close).
		'till_opened_at_gmt' => null,
		'till_closed_at_gmt' => null,
	);

	/** Integer fields of a closure row, cast on every read. */
	private const INTEGERS = array( 'number', 'printed_number', 'store_id', 'opened_by', 'closed_by', 'approved_by', 'unsynced_count', 'first_sale_counter', 'last_sale_counter', 'first_receipt_id', 'last_receipt_id', 'print_count' );

	/** Build the frozen document from closure-shaped fields, keeping only document keys.
	 *
	 * @param array $fields Closure fields; nested figures are arrays, not JSON strings.
	 */
	public static function document( array $fields ): array {
		$document = array();
		foreach ( self::ROW as $key => $column ) {
			if ( null === $column ) {
				$document[ $key ] = $fields[ $key ] ?? null;
			}
		}
		return $document;
	}

	/** Build the closure row from a session row that carries a closure.
	 *
	 * @param array $session Raw session row, closure columns included.
	 */
	private static function hydrate( array $session ): array {
		$document = json_decode( (string) $session['closure'], true );
		$row = array();
		foreach ( self::ROW as $key => $column ) {
			$row[ $key ] = null === $column ? ( $document[ $key ] ?? null ) : $session[ $column ];
		}
		return self::normalize( $row );
	}

	/** Cast a closure-shaped row's integer fields, leaving absent and null ones alone.
	 *
	 * @param array $row Closure row or legacy closure table row.
	 */
	public static function normalize( array $row ): array {
		foreach ( self::INTEGERS as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		return $row;
	}

	/** Validate a business day without normalizing impossible calendar dates.
	 *
	 * @param mixed $value Client-supplied business day.
	 */
	public static function is_business_day( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
			return false;
		}
		return checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) );
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
		return self::with_legacy( $this->list( array( 'session_id' => $id ) )[0] ?? null, 'session_id', $id );
	}

	/** Latest numbered document, independently of a till's clock.
	 *
	 * @param string $register_id Register UUID.
	 * @param array  $args Optional list() filters (a caller's reach and dates); the legacy read applies only the dates.
	 */
	public function last( string $register_id, array $args = array() ): ?array {
		$row = $this->list(
			array(
				'register_id' => $register_id,
				'number_order' => true,
				'page' => 1,
				'per_page' => 1,
			) + $args
		)[0] ?? null;
		// Mid-fold the highest number may still live only in a legacy table.
		return self::with_legacy( $row, 'register_id', $register_id, $args );
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
	 * The after/before filters compare the business day when stamped, otherwise closed_at_gmt.
	 * For stamped rows use after_business_day/before_business_day (the original local date),
	 * or the boundary's date portion when no separate date is supplied.
	 * Date-only boundaries include the whole day for unstamped rows too.
	 *
	 * @param array $args Filters and paging.
	 * @throws \RuntimeException On read failure.
	 */
	public function list( array $args ): array {
		global $wpdb;
		$sessions = new Register_Session_Store();
		$sessions->ensure_installed();
		$table = $sessions->table_name();
		// A deferred close is a closed session without a number until its document lands.
		$where = array( 'number IS NOT NULL' );
		foreach ( array(
			'id' => 'closure_id',
			'session_id' => 'id',
			'register_id' => 'register_id',
			'number' => 'number',
			'store_id' => 'store_id',
		) as $key => $column ) {
			if ( 'store_id' === $key && isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$where[] = Store_Reach::store_predicate( (array) $args['store_id'] );
			} elseif ( array_key_exists( $key, $args ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted column.
				$where[] = null === $args[ $key ] ? "{$column} IS NULL" : $wpdb->prepare( "{$column} = %s", $args[ $key ] );
			}
		}
		$where = array_merge( $where, self::date_predicates( $args ) );
		$where = implode( ' AND ', $where );
		$limit = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $limit;
		$order = empty( $args['number_order'] ) ? 'closed_at_gmt DESC, number DESC, closure_id DESC' : 'number DESC';
		if ( 'register_asc' === ( $args['number_order'] ?? null ) ) {
			$order = 'register_id ASC, number ASC, closure_id ASC';
		}
		$columns = self::columns();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed columns, prepared predicates and integer pagination.
		$rows = $wpdb->get_results( "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Closure read failed.' );
		}
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** Prepared after/before predicates over business_day, falling back to closed_at_gmt.
	 *
	 * @param array $args Filters.
	 */
	private static function date_predicates( array $args ): array {
		global $wpdb;
		$where = array();
		foreach ( array(
			'after' => '>=',
			'before' => '<=',
		) as $key => $operator ) {
			if ( isset( $args[ $key ] ) ) {
				$boundary = $args[ $key ];
				$closed_operator = $operator;
				if ( self::is_business_day( $boundary ) ) {
					$boundary .= ' 00:00:00';
					if ( 'before' === $key ) {
						$boundary = gmdate( 'Y-m-d H:i:s', strtotime( $boundary . ' UTC +1 day' ) );
						$closed_operator = '<';
					}
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed operator.
				$where[] = $wpdb->prepare( "(business_day {$operator} %s OR (business_day IS NULL AND closed_at_gmt {$closed_operator} %s))", $args[ $key . '_business_day' ] ?? substr( $args[ $key ], 0, 10 ), $boundary );
			}
		}
		return $where;
	}

	/** The latest numbered closure of each register, in one query once folded.
	 *
	 * Each row also carries `counters_started_at_gmt`: the opening of the register's first
	 * numbered session, which is when its counters started.
	 *
	 * @param string[] $register_ids Register UUIDs.
	 * @return array<string, array> Closure rows keyed by register id; registers without one are absent.
	 * @throws \RuntimeException On read failure.
	 */
	public function latest_for_registers( array $register_ids ): array {
		global $wpdb;
		if ( ! $register_ids ) {
			return array();
		}
		$sessions = new Register_Session_Store();
		$sessions->ensure_installed();
		$table = $sessions->table_name();
		$columns = self::columns();
		$placeholders = implode( ', ', array_fill( 0, count( $register_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed columns and generated placeholders.
		$sql = $wpdb->prepare( "SELECT {$columns}, latest_started FROM {$table} INNER JOIN ( SELECT register_id AS latest_register_id, MAX(number) AS latest_number, MIN(opened_at_gmt) AS latest_started FROM {$table} WHERE register_id IN ({$placeholders}) AND number IS NOT NULL GROUP BY register_id ) latest ON latest_register_id = register_id AND latest_number = number", array_values( $register_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( self::read_failed() ) {
			throw new \RuntimeException( 'Register closure read failed.' );
		}
		$rows = array_column(
			array_map(
				static function ( $row ) {
					return self::hydrate( $row ) + array( 'counters_started_at_gmt' => $row['latest_started'] );
				},
				$rows
			),
			null,
			'register_id'
		);
		// Mid-fold a register's newest closure may still live only in a legacy table.
		if ( self::migrating_tables() ) {
			foreach ( $register_ids as $register_id ) {
				$row = self::with_legacy( $rows[ $register_id ] ?? null, 'register_id', $register_id );
				if ( null !== $row ) {
					$rows[ $register_id ] = $row + array( 'counters_started_at_gmt' => $rows[ $register_id ]['counters_started_at_gmt'] ?? null );
				}
			}
		}
		return $rows;
	}

	/** The session columns a closure read selects: those ROW maps, plus the document. */
	private static function columns(): string {
		return implode( ', ', array_unique( array_filter( self::ROW ) ) ) . ', closure';
	}

	/** Add counts to a page with one grouped fiscal query, never one query per closure.
	 *
	 * @param array $rows Closure rows.
	 * @throws \RuntimeException On read failure.
	 */
	public function with_correction_counts( array $rows ): array {
		global $wpdb;
		if ( ! $rows ) {
			return $rows;
		}
		$store = new Fiscal_Record_Store();
		$store->ensure_installed();
		$table = $store->table_name();
		$placeholders = implode( ',', array_fill( 0, count( $rows ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table and placeholders only.
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT closure_id, COUNT(*) AS total FROM {$table} WHERE closure_id IN ({$placeholders}) GROUP BY closure_id", array_column( $rows, 'id' ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Closure correction count failed.' );
		}
		$counts = array_column( $counts, 'total', 'closure_id' );
		foreach ( $rows as &$row ) {
			$row['corrections_count'] = (int) ( $counts[ $row['id'] ] ?? 0 );
		}
		return $rows;
	}

	/** Project all linked fiscal corrections, ordered by server creation time then id.
	 *
	 * Figures are four-place decimal strings; actor labels resolve current user names.
	 *
	 * @param string $closure_uuid Closure UUID.
	 * @throws \RuntimeException On read or calculation failure.
	 */
	public function corrections_for( string $closure_uuid ): array {
		global $wpdb;
		$store = new Fiscal_Record_Store();
		$store->ensure_installed();
		$table = $store->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; deliberately unpaginated.
		$records = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE closure_id = %s ORDER BY received_at_gmt, id", $closure_uuid ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Closure corrections read failed.' );
		}
		$rows = array();
		foreach ( $records as $record ) {
			$payload = json_decode( $record['payload'], true );
			$figures = array();
			if ( 'late_sale' === $record['type'] ) {
				$tenders = $payload['tender_rows'];
				$figures['expected_delta'] = Session_Figures::tender_totals( $tenders );
				$sales = array();
				$refunds = array();
				foreach ( $tenders as $tender ) {
					if ( Session_Figures::is_refund_tender( $tender ) ) {
						$refunds[] = ltrim( $tender['amount'], '-' );
					} else {
						$sales[] = $tender['amount'];
					}
				}
				$figures['sales_delta'] = self::sum( $sales );
				$figures['refunds_delta'] = self::sum( $refunds );
			} elseif ( 'late_refund' === $record['type'] ) {
				$reallocation = 'reallocation' === ( $payload['kind'] ?? null );
				$refund = $reallocation ? null : wc_get_order( $record['refund_id'] );
				if ( self::read_failed() ) {
					throw new \RuntimeException( 'Closure correction refund read failed.' );
				}
				$tenders = $refund instanceof \WC_Order_Refund ? Session_Figures::refund_tender_rows( $refund ) : $payload['tender_rows'];
				$figures['expected_delta'] = Session_Figures::tender_totals( $tenders );
				$figures['refunds_delta'] = $reallocation ? '0.0000' : self::sum(
					array_map(
						static function ( $row ) {
							return ltrim( $row['amount'], '-' );
						},
						$tenders
					)
				);
			} elseif ( 'late_movement' === $record['type'] ) {
				$void = 'void' === $payload['type'];
				$movement = $void ? ( new Cash_Movement_Store() )->get( $payload['session_id'], $payload['voids'] ) : $payload;
				if ( ! $movement ) {
					throw new \RuntimeException( 'Closure correction movement missing.' );
				}
				$negative = ( 'paid_out' === $movement['type'] ) !== $void;
				$amount = in_array( $movement['type'], array( 'paid_in', 'paid_out' ), true ) ? $movement['amount'] : '0';
				$figures['cash_delta'] = self::normalize_amount( ( $negative ? '-' : '' ) . $amount );
			} elseif ( 'recount' === $record['type'] ) {
				foreach ( array( 'counted', 'variance' ) as $key ) {
					$figures[ $key ] = array_map(
						static function ( $amount ) {
							return self::normalize_amount( $amount );
						},
						$payload[ $key ]
					);
				}
			}
			$actor_id = (int) $record['cashier_id'];
			$approver_id = (int) $record['approver_id'];
			$rows[] = array(
				'id' => (int) $record['id'],
				'type' => $record['type'],
				'actor' => array(
					'id' => $actor_id,
					'name' => get_userdata( $actor_id )->display_name ?? '',
				),
				'approver' => $approver_id ? array(
					'id' => $approver_id,
					'name' => get_userdata( $approver_id )->display_name ?? '',
				) : null,
				'reason' => $payload['reason'] ?? '',
				'created_at' => str_replace( ' ', 'T', $record['received_at_gmt'] ) . 'Z',
				'figures' => $figures,
			);
		}
		return $rows;
	}

	/** Carry only the selected corrections under create()'s register lock and transaction.
	 *
	 * @param string $register_id Register UUID.
	 * @param string $closure_id New closure UUID.
	 * @throws \RuntimeException On read or stamp failure; create() rolls everything back.
	 */
	private function carry_corrections( string $register_id, string $closure_id ): array {
		global $wpdb;
		$fiscal = ( new Fiscal_Record_Store() )->table_name();
		$sessions = ( new Register_Session_Store() )->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned tables; all earlier closures of this register, without pagination.
		$pending = $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.closure_id FROM {$fiscal} f INNER JOIN {$sessions} s ON s.closure_id = f.closure_id WHERE s.register_id = %s AND f.type IN ('late_sale', 'late_refund') AND f.carried_by_closure_id IS NULL", $register_id ), ARRAY_A );
		if ( self::read_failed() ) {
			throw new \RuntimeException( 'Closure carry read failed.' );
		}
		$ids = array_fill_keys( array_column( $pending, 'id' ), true );
		$totals = array(
			'sales' => array(),
			'refunds' => array(),
		);
		foreach ( array_unique( array_column( $pending, 'closure_id' ) ) as $previous_id ) {
			foreach ( $this->corrections_for( $previous_id ) as $correction ) {
				// A correction arriving after the pending read belongs to the next closure.
				if ( ! isset( $ids[ $correction['id'] ] ) ) {
					continue;
				}
				foreach ( $totals as $kind => $_amounts ) {
					$totals[ $kind ][] = $correction['figures'][ $kind . '_delta' ] ?? '0';
				}
				if ( 1 !== $wpdb->update(
					$fiscal,
					array( 'carried_by_closure_id' => $closure_id ),
					array(
						'id' => $correction['id'],
						'carried_by_closure_id' => null,
					)
				) ) {
					throw new \RuntimeException( 'Closure carry stamp failed.' );
				}
			}
		}
		return array_map( array( self::class, 'sum' ), $totals );
	}

	/** Every legacy closures table still to be folded, or none once the latch reaches schema 10.
	 *
	 * @throws \RuntimeException When a probe fails; a caller must not assume absence.
	 */
	private static function migrating_tables(): array {
		return Legacy_Tables::pending( self::LEGACY_TABLE, '10', 'Register closure refused: closure storage probe failed', 'Closure storage probe failed.' );
	}

	/** Whether the closure fold is pending; free once the latch is set. Throws when a probe fails. */
	public function is_folding(): bool {
		return array() !== self::migrating_tables();
	}

	/** The highest-numbered of a session-table row and each legacy table's match; a tie keeps the session row.
	 *
	 * @param array|null $row Session-table closure.
	 * @param string     $column session_id or register_id.
	 * @param string     $value UUID.
	 * @param array      $args Optional after/before filters.
	 * @throws \RuntimeException On probe or read failure.
	 */
	private static function with_legacy( ?array $row, string $column, string $value, array $args = array() ): ?array {
		foreach ( self::migrating_tables() as $table ) {
			$legacy = self::legacy_row( $table, $column, $value, $args );
			if ( null !== $legacy && $legacy['number'] > ( $row['number'] ?? 0 ) ) {
				$row = $legacy;
			}
		}
		return $row;
	}

	/** The highest-numbered legacy closure matching one column, in the closure row shape.
	 *
	 * @param string $table Legacy table.
	 * @param string $column session_id or register_id.
	 * @param string $value UUID.
	 * @param array  $args Optional after/before filters.
	 * @throws \RuntimeException On read failure.
	 */
	private static function legacy_row( string $table, string $column, string $value, array $args = array() ): ?array {
		global $wpdb;
		$where = implode( ' AND ', array_merge( array( $wpdb->prepare( "{$column} = %s", $value ) ), self::date_predicates( $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted column.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table, prepared predicates.
		$legacy = $wpdb->get_row( "SELECT * FROM {$table} WHERE {$where} ORDER BY number DESC LIMIT 1", ARRAY_A );
		if ( self::read_failed() ) {
			throw new \RuntimeException( 'Closure read failed.' );
		}
		if ( ! $legacy ) {
			return null;
		}
		$row = array();
		foreach ( array_keys( self::ROW ) as $key ) {
			$row[ $key ] = $legacy[ $key ] ?? null;
		}
		foreach ( array( 'expected', 'till_expected', 'counted', 'variance', 'breakdowns', 'findings' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? null : json_decode( $row[ $key ], true );
		}
		return self::normalize( $row );
	}

	/** Whether the last database query failed (read fresh, after any earlier check). */
	private static function read_failed(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	/** Validate and normalize tender maps, allowing negative expectations.
	 *
	 * @param mixed $value Input map.
	 * @param bool  $signed Allow negative balances.
	 */
	public static function tenders( $value, bool $signed = false ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['cash'] ) ) {
			return null;
		}
		foreach ( $value as $key => &$amount ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,191}$/D', $key ) ) {
				return null;
			}
			if ( ! is_string( $amount ) || ! preg_match( $signed ? '/^-?\d{1,15}(?:\.\d{1,4})?$/D' : '/^\d{1,15}(?:\.\d{1,4})?$/D', $amount ) ) {
				return null;
			}
			$amount = self::sum( array( $amount ) );
		}
		return $value;
	}

	/** Normalize a validated scalar without SQL, floats or integer overflow.
	 *
	 * @param string $amount Signed decimal string with at most four fractional places.
	 */
	private static function normalize_amount( string $amount ): string {
		$negative = '-' === substr( $amount, 0, 1 );
		$parts = explode( '.', ltrim( $amount, '-' ), 2 );
		$whole = ltrim( $parts[0], '0' );
		$fraction = str_pad( $parts[1] ?? '', 4, '0' );
		$normalized = ( '' === $whole ? '0' : $whole ) . '.' . $fraction;
		return $negative && '0.0000' !== $normalized ? '-' . $normalized : $normalized;
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

	/** Write once: one conditional session UPDATE claims the number, a second writes the document.
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
			$this->log_replay( $existing );
			return $existing;
		}
		if ( array() !== self::migrating_tables() ) {
			// Step 10 has not copied every legacy closure yet: a number minted now could
			// collide with one only a legacy table holds. The outbox retries the 500.
			// Once the latch reads the current schema the fold is done and nothing is probed.
			Logger::warning(
				'Register closure refused: closure storage is migrating',
				array(
					'closure_id' => $fields['id'],
					'session_id' => $fields['session_id'],
				)
			);
			throw new \RuntimeException( 'Closure storage is migrating.' );
		}
		$sessions = new Register_Session_Store();
		$session = $sessions->get( $fields['session_id'] );
		// The session row is the provenance: a register its repository no longer holds still closes.
		if ( ! $session || ! in_array( $session['status'], array( 'counting', 'closed' ), true ) ) {
			Logger::warning(
				'Register closure refused: session missing, or status is not counting or closed',
				array(
					'closure_id' => $fields['id'],
					'session_id' => $fields['session_id'],
					'register_id' => $session['register_id'] ?? null,
					'status' => $session['status'] ?? null,
					'field' => ! $session ? 'session_id' : 'status',
				)
			);
			return new \WP_Error( 'wcpos_closure_session_invalid', __( 'The session cannot be closed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		( new Fiscal_Record_Store() )->ensure_installed();
		if ( ! ( new Register_Store() )->lock( $session['register_id'] ) ) {
			Logger::warning(
				'Closure register lock failed.',
				array(
					'closure_id' => $fields['id'],
					'session_id' => $fields['session_id'],
				)
			);
			throw new \RuntimeException( 'Closure register lock failed.' );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			( new Register_Store() )->unlock( $session['register_id'] );
			Logger::warning(
				'Closure transaction failed.',
				array(
					'closure_id' => $fields['id'],
					'session_id' => $fields['session_id'],
				)
			);
			throw new \RuntimeException( 'Closure transaction failed.' );
		}
		$rolled_back = false;
		// Refusal and failure warnings raised inside the transaction are held here and
		// emitted in the finally block, AFTER the rollback: WooCommerce's database log
		// handler writes through this same connection, so a warning logged inside the
		// transaction is erased with it and the merchant loses exactly the record this
		// audit exists to keep.
		$deferred = null;
		// A replay found only under the register lock is logged from finally too: its
		// transaction is rolled back on the way out and would take the row with it.
		$replay = null;
		$figures = null;
		try {
			$existing = $this->get( $fields['id'] );
			if ( $existing ) {
				// Idempotent replay under the counter lock — the success path, not a fault.
				$replay = $existing;
				return $existing;
			}
			$existing = $this->for_session( $session['id'] );
			if ( $existing ) {
				// The recount is written outside the closure transaction: the finally block
				// must not roll it back with the abandoned closure attempt. The refusal is
				// logged after the rollback for the reason given at $deferred above.
				$wpdb->query( 'ROLLBACK' );
				$rolled_back = true;
				Logger::warning(
					'Register closure refused: session already has a closure',
					array(
						'closure_id' => $existing['id'],
						'session_id' => $session['id'],
						'requested_closure_id' => $fields['id'],
					)
				);
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
				$deferred = array(
					'Register closure refused: session status changed',
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
						'status' => $session['status'],
					),
				);
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
				// Logged after the commit (from the row's printed_number): until then no
				// closure has received the next number, and a rollback would leave a file
				// logger claiming one had.
				$fields['printed_number'] = $fields['number'];
				$fields['number'] = $next;
			} elseif ( $fields['number'] > $next + self::MAX_NUMBER_JUMP ) {
				$deferred = array(
					'Register closure refused: number exceeds register sequence',
					array(
						'closure_id' => $fields['id'],
						'register_id' => $session['register_id'],
						'number' => $fields['number'],
						'next_number' => $next,
					),
				);
				return new \WP_Error( 'wcpos_closure_number_invalid', __( 'The closure number is too far ahead of the register sequence.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			} elseif ( $fields['number'] < $next ) {
				$deferred = array(
					'Register closure refused: number precedes register sequence',
					array(
						'closure_id' => $fields['id'],
						'register_id' => $session['register_id'],
						'number' => $fields['number'],
						'next_number' => $next,
					),
				);
				return new \WP_Error( 'wcpos_closure_number_invalid', __( 'The closure number precedes the register sequence.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			}
			$figures = Session_Figures::for_session( $session );
			$fields['expected'] = $figures->expected();
			$fields['variance'] = $this->variance( $fields['counted'], $fields['expected'] );
			$totals = $figures->period_amounts();
			$figures->stamp( $fields['id'] );
			$carried = $this->carry_corrections( $session['register_id'], $fields['id'] );
			$findings = array();
			foreach ( $totals as $kind => $amounts ) {
				$period = self::sum( $amounts );
				foreach ( array(
					'period' => $period,
					'perpetual' => self::sum( array( $previous[ 'perpetual_' . $kind . '_total' ] ?? '0', $period, $carried[ $kind ] ) ),
				) as $scope => $derived ) {
					$key = $scope . '_' . $kind . '_total';
					if ( ! preg_match( '/^\d{1,15}\.\d{4}$/D', $derived ) ) {
						$deferred = array(
							'Closure total exceeds storage precision.',
							array(
								'closure_id' => $fields['id'],
								'session_id' => $fields['session_id'],
								'field' => $key,
							),
						);
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
			$fields['business_day'] = $session['business_day'] ?? null;
			$fields['closed_by'] = 'closed' === $session['status'] ? $session['closed_by'] : get_current_user_id();
			$store = wcpos_get_store( (int) $fields['store_id'] );
			$resolver = new Receipt_Store_Resolver( is_object( $store ) ? $store : new \WCPOS\WooCommercePOS\Abstracts\Store() );
			$fields['breakdowns']['currency'] = $resolver->resolve_store_option_string( 'get_currency', get_woocommerce_currency() );
			$fields['breakdowns']['money_format'] = array_intersect_key(
				$resolver->build_presentation_hints( $fields['breakdowns']['currency'] ),
				array_flip( array( 'currency_position', 'price_decimal_separator', 'price_thousand_separator', 'price_num_decimals', 'currency_symbol' ) )
			);
			$fields['breakdowns']['timezone'] = $resolver->resolve_store_timezone()->getName();
			$fields['breakdowns']['store'] = array_intersect_key( $resolver->build_store_section(), array_flip( array( 'name', 'address_lines' ) ) );
			$fields['breakdowns']['labels'] = array( 'register_name' => ( new Register_Store() )->display_name( $session['register_id'] ) );
			foreach ( array( 'opened_by', 'closed_by', 'approved_by' ) as $key ) {
				$fields['breakdowns']['labels'][ $key . '_name' ] = get_userdata( (int) ( $fields[ $key ] ?? 0 ) )->display_name ?? '';
			}
			$fields['received_at_gmt'] = current_time( 'mysql', true );
			$table = ( new Fiscal_Record_Store() )->table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned fiscal table.
			$receipts = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(id) AS first_receipt_id, MAX(id) AS last_receipt_id FROM {$table} WHERE session_id = %s AND type = 'sale'", $session['id'] ), ARRAY_A );
			if ( null === $receipts ) {
				$deferred = array(
					'Closure receipt read failed.',
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
					),
				);
				throw new \RuntimeException( 'Closure receipt read failed.' );
			}
			$fields += $receipts;
			$approval_missing = null;
			try {
				$result = $sessions->transition(
					$session,
					array(
						'status' => 'closed',
						'closure_id' => $fields['id'],
						'number' => $fields['number'],
						'printed_number' => $fields['printed_number'],
						'counted' => $fields['counted'],
						'expected' => $fields['expected'],
					) + ( 'counting' === $session['status'] ? array(
						'closed_at_gmt' => $fields['closed_at_gmt'],
						'closed_by' => $fields['closed_by'],
					) : array() ),
					null !== $fields['printed_at_gmt'],
					$approval_missing,
					true
				);
			} catch ( \RuntimeException $error ) {
				// The session store stays silent on a closure-driven write (see its
				// transition()); the failure is restated here after the rollback.
				$deferred = array(
					'Closure session write failed.',
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
					),
				);
				throw $error;
			}
			if ( is_wp_error( $result ) ) {
				// The session store's own refusal line was written inside this transaction
				// and is lost under database logging; restate it once the rollback is done.
				$deferred = array(
					'Register closure refused: ' . $result->get_error_message(),
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
						'code' => $result->get_error_code(),
					) + array_diff_key( (array) $result->get_error_data(), array( 'status' => true ) ),
				);
				return $result;
			}
			if ( null !== $approval_missing ) {
				$fields['findings']['approval_missing'] = $approval_missing;
			}
			$fields['approved_by'] = $result['approved_by'];
			$fields['breakdowns']['labels']['approved_by_name'] = get_userdata( (int) ( $result['approved_by'] ?? 0 ) )->display_name ?? '';
			// The row reads the session's timestamps so filter, sort and display agree; a
			// deferred close keeps the till's own claims in the document.
			$fields['till_closed_at_gmt'] = 'closed' === $session['status'] && $fields['closed_at_gmt'] !== $session['closed_at_gmt'] ? $fields['closed_at_gmt'] : null;
			$fields['till_opened_at_gmt'] = $fields['opened_at_gmt'] === $session['opened_at_gmt'] ? null : $fields['opened_at_gmt'];
			$document = wp_json_encode( self::document( $fields ) );
			if ( false === $document ) {
				$deferred = array(
					'Closure JSON encoding failed.',
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
						'field' => 'closure',
					),
				);
				throw new \RuntimeException( 'Closure JSON encoding failed.' );
			}
			$table = $sessions->table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, prepared values.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET closure = %s WHERE id = %s AND closure_id = %s", $document, $session['id'], $fields['id'] ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				$deferred = array(
					'Closure write failed.',
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
					),
				);
				throw new \RuntimeException( 'Closure write failed.' );
			}
			$created = true;
		} catch ( \RuntimeException $error ) {
			// A calculation or read that throws before any branch above has recorded
			// the failure still leaves the closure attempt on the log after the rollback.
			if ( ! $deferred ) {
				$deferred = array(
					$error->getMessage(),
					array(
						'closure_id' => $fields['id'],
						'session_id' => $fields['session_id'],
					),
				);
			}
			throw $error;
		} finally {
			if ( ! $created && ! $rolled_back ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( $figures ) {
				$figures->release( ! $created );
			}
			( new Register_Store() )->unlock( $session['register_id'] );
			if ( $deferred ) {
				Logger::warning( $deferred[0], $deferred[1] );
			}
			if ( $replay ) {
				$this->log_replay( $replay );
			}
		}
		$row = $this->get( $fields['id'] );
		if ( null !== $row['printed_number'] ) {
			Logger::warning(
				'Register closure number already exists: assigned next number',
				array(
					'closure_id' => $row['id'],
					'register_id' => $row['register_id'],
					'number' => $row['printed_number'],
					'next_number' => $row['number'],
				)
			);
		}
		// Only the write that stamped this closure onto the session may claim the
		// state change: a concurrent close that won the conditional update returns
		// here as the current row, carrying the other closure's id.
		if ( $session['status'] !== $result['status'] && $result['closure_id'] === $fields['id'] ) {
			Logger::log(
				'Register session state changed',
				array(
					'session_id' => $session['id'],
					'from' => $session['status'],
					'to' => $result['status'],
					'user_id' => get_current_user_id(),
					'approved_by' => $result['approved_by'],
				)
			);
		}
		Logger::log(
			'Register closure written',
			array(
				'closure_id' => $row['id'],
				'number' => $row['number'],
				'register_id' => $row['register_id'],
				'session_id' => $row['session_id'],
				'variance' => $row['variance'],
				'user_id' => $row['closed_by'],
			)
		);
		return $row;
	}

	/** An idempotent replay returned the existing row: the outbox retries a write whose
	 * response was lost, and returning the existing row IS the success path. A warning
	 * here would appear in the merchant's log for every recovered network timeout. The
	 * REST controller answers a replay before create() runs, so it calls this too.
	 *
	 * @param array $row Existing closure row.
	 */
	public function log_replay( array $row ): void {
		Logger::log(
			'Register closure already recorded; returning the existing row',
			array(
				'closure_id' => $row['id'],
				'session_id' => $row['session_id'],
			)
		);
	}

	/** Append a replay-safe recount; never update the document.
	 *
	 * @param array    $closure Frozen document.
	 * @param string   $id Client source UUID.
	 * @param array    $counted New counts.
	 * @param string   $reason Operator reason.
	 * @param int|null $approver_id Credential override approver, if used.
	 * @throws \RuntimeException On write failure.
	 */
	public function recount( array $closure, string $id, array $counted, string $reason, ?int $approver_id = null ): array {
		$row = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'recount',
				'source_id' => $id,
				'closure_id' => $closure['id'],
				'register_id' => $closure['register_id'],
				'session_id' => $closure['session_id'],
				'store_id' => $closure['store_id'],
				'cashier_id' => get_current_user_id(),
				'approver_id' => $approver_id,
				'payload' => array(
					'counted' => $counted,
					'variance' => $this->variance( $counted, $closure['expected'] ),
					'reason' => $reason,
				),
			)
		);
		if ( null === $row || $row['closure_id'] !== $closure['id'] ) {
			Logger::warning(
				'Register closure recount refused: record missing or closure_id mismatch',
				array(
					'closure_id' => $closure['id'],
					'source_id' => $id,
				)
			);
			throw new \RuntimeException( 'Closure recount failed.' );
		}
		Logger::warning(
			'Register closure recount recorded',
			array(
				'closure_id' => $closure['id'],
				'source_id' => $id,
				'old_variance' => $closure['variance'],
				'new_variance' => $row['payload']['variance'],
				'reason' => $row['payload']['reason'],
				'user_id' => $row['cashier_id'],
			)
		);
		return $row;
	}

	/** Increment only mutable print bookkeeping.
	 *
	 * @param string $id Closure UUID.
	 * @throws \RuntimeException On write failure.
	 */
	public function record_print( string $id ): ?array {
		global $wpdb;
		$sessions = new Register_Session_Store();
		$sessions->ensure_installed();
		$table = $sessions->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET print_count = print_count + 1, last_printed_at_gmt = %s WHERE closure_id = %s", current_time( 'mysql', true ), $id ) ) ) {
			// Callers log this: Closure_Print_Counter runs it inside a transaction whose
			// rollback would erase a warning written here under database logging.
			throw new \RuntimeException( 'Closure print stamp failed.' );
		}
		$row = $this->get( $id );
		if ( ! $row ) {
			Logger::warning( 'Register closure reprint refused: closure_id not found', array( 'closure_id' => $id ) );
		}
		return $row;
	}

	/** Record a produced print. Callers emit this only once the print has committed:
	 * Closure_Print_Counter rolls the count back when rendering fails, and a file
	 * logger keeps whatever was written before that rollback.
	 *
	 * @param array $row Closure row after record_print().
	 */
	public function log_printed( array $row ): void {
		Logger::log(
			$row['print_count'] > 1 ? 'Register closure reprinted' : 'Register closure printed',
			array(
				'closure_id' => $row['id'],
				'print_count' => $row['print_count'],
				'user_id' => get_current_user_id(),
			)
		);
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
