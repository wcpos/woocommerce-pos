<?php
/**
 * Cash movement storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Append-only movements, except for the target's one-time void stamp.
 *
 * A session row carries its movements as one JSON array, `movements`, in
 * (created_at_gmt, id) order. The idempotency key is (session, uuid): every
 * lookup reads one session's array, and every write is a read-modify-write of
 * that array under the session's register lock.
 */
final class Cash_Movement_Store {
	/** The table movements lived in before sync schema 11; kept for the fold and its guards. */
	public const LEGACY_TABLE = 'wcpos_cash_movements';

	/** The sync schema whose latch proves the legacy table is folded. */
	private const FOLDED_SCHEMA = '11';

	/** Read one movement of a session.
	 *
	 * @param string $session_id Session UUID.
	 * @param string $id Movement UUID.
	 * @throws \RuntimeException On read failure.
	 */
	public function get( string $session_id, string $id ): ?array {
		return self::find( $this->list( $session_id ), $id );
	}

	/** List all movements oldest first, including voids.
	 *
	 * Mid-fold a session's movements may still live in a legacy table, so those rows are
	 * merged in, de-duplicated by id: no figure misses a movement during the window.
	 *
	 * @param string $session_id Session UUID.
	 * @throws \RuntimeException On probe or read failure.
	 */
	public function list( string $session_id ): array {
		global $wpdb;
		$rows = array();
		foreach ( self::entries( $session_id ) as $entry ) {
			$rows[ $entry['id'] ] = self::row( $session_id, $entry );
		}
		foreach ( self::migrating_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table; the id is prepared.
			$legacy = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Session movement read failed.' );
			}
			foreach ( $legacy as $entry ) {
				$row = self::row( $session_id, self::entry( $entry ) );
				// A stamp only goes from null to set, and pre-fold code can void a target after its
				// session was copied: a stamped legacy row wins over the stale folded entry.
				if ( ! isset( $rows[ $row['id'] ] ) || null !== $row['voided_by'] ) {
					$rows[ $row['id'] ] = $row;
				}
			}
		}
		return self::ordered( array_values( $rows ) );
	}

	/** Append validated fields; only a null voided_by may subsequently change.
	 *
	 * @param array $fields Movement fields.
	 * @return array|\WP_Error
	 * @throws \RuntimeException On database failure.
	 * @throws \Throwable On unexpected failure, after rolling back the transaction.
	 */
	public function create( array $fields ) {
		global $wpdb;
		$existing = $this->get( $fields['session_id'], $fields['id'] );
		if ( $existing ) {
			// An idempotent replay, not a fault: the outbox retries a movement whose
			// response was lost, and returning the existing row IS the success path.
			// Logging it at warning would put a warning in the merchant's log for
			// every recovered network timeout.
			$this->log_replay( $existing );
			return $existing;
		}
		$context = array(
			'movement_id' => $fields['id'],
			'session_id' => $fields['session_id'],
			'user_id' => $fields['actor'],
		);
		if ( array() !== self::migrating_tables() ) {
			// Step 11 has not copied every legacy movement yet: an append now could be lost
			// to, or duplicated by, the copy. The outbox retries the 500.
			Logger::warning( 'Cash movement refused: movement storage is migrating', $context );
			throw new \RuntimeException( 'Movement storage is migrating.' );
		}
		$session = ( new Register_Session_Store() )->get( $fields['session_id'] );
		if ( ! $session ) {
			Logger::warning( 'Movement session missing.', $context );
			throw new \RuntimeException( 'Movement session missing.' );
		}
		$register = $session['register_id'];
		if ( ! ( new Register_Store() )->lock( $register ) ) {
			Logger::warning( 'Movement register lock failed.', $context );
			throw new \RuntimeException( 'Movement register lock failed.' );
		}
		$fiscal = new Fiscal_Record_Store();
		$sequence_locked = false;
		$transaction = false;
		try {
			// A fresh read under the lock: a retry that committed while this one waited is a replay.
			$entries = self::entries( $fields['session_id'], $stored );
			$existing = self::find( $entries, $fields['id'] );
			if ( $existing ) {
				$existing = self::row( $fields['session_id'], $existing );
				$this->log_replay( $existing );
				return $existing;
			}
			// A late_movement record's identity is (type, source_id) with the raw uuid, so a uuid another
			// session already holds as a late movement would have its correction absorbed: refuse it,
			// and the till mints a new uuid. A replay into the same session was answered above.
			$claimed = $fiscal->find_by_source( 'late_movement', $fields['id'] );
			if ( $claimed && 0 !== strcasecmp( (string) $claimed['session_id'], $fields['session_id'] ) ) {
				return $this->refuse_reused_id( $fields, (string) $claimed['session_id'] );
			}
			$session = ( new Register_Session_Store() )->get( $fields['session_id'] );
			if ( ! $session || ! $this->accepts( $session, $fields['created_at_gmt'] ) ) {
				return new \WP_Error( 'wcpos_session_not_open', __( 'This movement was recorded after counting began.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			}
			$entry = self::entry( $fields + array( 'voids' => null ) );
			if ( 'void' === $entry['type'] ) {
				$target = self::position( $entries, (string) $entry['voids'] );
				if ( null === $target || null !== $entries[ $target ]['voided_by'] || 'void' === $entries[ $target ]['type'] ) {
					Logger::warning(
						'Cash movement refused: voids target already voided or unavailable',
						array(
							'movement_id' => $fields['id'],
							'session_id' => $fields['session_id'],
							'voids' => $entry['voids'],
							'user_id' => $fields['actor'],
						)
					);
					return new \WP_Error( 'wcpos_movement_void_refused', __( 'The movement has already been voided or is unavailable.', 'woocommerce-pos' ), array( 'status' => 409 ) );
				}
				// The stamp and the reversal are one array write, so neither lands without the other.
				$entries[ $target ]['voided_by'] = $entry['id'];
			}
			$entries[] = $entry;
			$movements = wp_json_encode( self::ordered( $entries ) );
			if ( false === $movements ) {
				throw new \RuntimeException( 'Movement write failed.' );
			}
			$closure = ( new Closure_Store() )->for_session( $fields['session_id'] );
			if ( $closure ) {
				$fiscal->ensure_installed();
				Register_Store::warn_if_locks_cannot_nest();
				$sequence_locked = $fiscal->lock_sequence();
				if ( ! $sequence_locked ) {
					throw new \RuntimeException( 'Unable to acquire fiscal record sequence lock.' );
				}
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					throw new \RuntimeException( 'Movement transaction failed.' );
				}
				$transaction = true;
			}
			$sessions = ( new Register_Session_Store() )->table_name();
			// The register lock serialises writers, but a server that cannot nest named locks drops it
			// when the fiscal lock is taken: the write also requires the array exactly as read (MD5 of
			// the stored bytes, so the collation cannot equate two different arrays). A stale write
			// fails, the transaction rolls back, and the outbox retry re-reads.
			$unchanged = null === $stored ? 'movements IS NULL' : $wpdb->prepare( 'MD5(movements) = %s', md5( $stored ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; values and the condition are prepared.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET movements = %s WHERE id = %s AND {$unchanged}", $movements, $fields['session_id'] ) ) ) {
				throw new \RuntimeException( 'Movement write failed.' );
			}
			if ( $closure ) {
				$record = $fiscal->record(
					array(
						'type' => 'late_movement',
						'source_id' => $fields['id'],
						'closure_id' => $closure['id'],
						'session_id' => $fields['session_id'],
						'register_id' => $closure['register_id'],
						'store_id' => $closure['store_id'],
						'cashier_id' => $fields['actor'],
						'payload' => $fields,
					)
				);
				if ( null === $record ) {
					throw new \RuntimeException( 'Late movement write failed.' );
				}
				// The check above ran under this register's lock only: a writer on another register
				// can record the same uuid first, and record() then returns its row. Never commit
				// a late movement whose correction belongs to another session.
				if ( 0 !== strcasecmp( (string) $record['session_id'], $fields['session_id'] ) ) {
					$wpdb->query( 'ROLLBACK' );
					$transaction = false;
					return $this->refuse_reused_id( $fields, (string) $record['session_id'] );
				}
			}
			if ( $transaction ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'Movement commit failed.' );
				}
			}
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			// Logged after the rollback: WooCommerce's database log handler writes
			// through this same connection, so a warning emitted inside the
			// transaction is erased with it and the merchant loses exactly the
			// failure record this audit exists to keep.
			Logger::warning( $error->getMessage(), $context );
			throw $error;
		} finally {
			if ( $sequence_locked ) {
				$fiscal->unlock_sequence();
			}
			( new Register_Store() )->unlock( $register );
		}
		$row = self::row( $fields['session_id'], $entry );
		Logger::log(
			'Cash movement accepted',
			array(
				'movement_id' => $row['id'],
				'session_id' => $row['session_id'],
				'type' => $row['type'],
				'amount' => $row['amount'],
				'reason_given' => '' !== trim( $row['reason'] ),
				'reason' => $row['reason'],
				'user_id' => $row['actor'],
			)
		);
		return $row;
	}

	/** Refuse a uuid another session already holds as a late movement; the till mints a new one.
	 *
	 * @param array  $fields Movement fields.
	 * @param string $owner Session holding the late_movement record.
	 */
	private function refuse_reused_id( array $fields, string $owner ): \WP_Error {
		Logger::warning(
			'Cash movement refused: movement id already names a late movement of another session',
			array(
				'movement_id' => $fields['id'],
				'session_id' => $fields['session_id'],
				'owning_session_id' => $owner,
				'user_id' => $fields['actor'],
			)
		);
		return new \WP_Error( 'wcpos_movement_id_reused', __( 'This movement id is already used by another session.', 'woocommerce-pos' ), array( 'status' => 409 ) );
	}

	/** An idempotent replay returned the existing row: the success path, not a fault.
	 *
	 * The REST controller answers a replay before create() runs, so it calls this too.
	 *
	 * @param array $row Existing movement row.
	 */
	public function log_replay( array $row ): void {
		Logger::log(
			'Cash movement already recorded; returning the existing row',
			array(
				'movement_id' => $row['id'],
				'session_id' => $row['session_id'],
			)
		);
	}

	/** Offline movements must predate the counting cutoff, strictly.
	 *
	 * @param array  $session Session row.
	 * @param string $created_at UTC SQL timestamp.
	 */
	public function accepts( array $session, string $created_at ): bool {
		$accepted = 'open' === $session['status'] || ( in_array( $session['status'], array( 'counting', 'closed' ), true ) && null !== $session['counting_started_at_gmt'] && $created_at < $session['counting_started_at_gmt'] );
		if ( ! $accepted ) {
			Logger::warning(
				'Cash movement refused: session status or counting cutoff rejects created_at_gmt',
				array(
					'session_id' => $session['id'],
					'status' => $session['status'],
					'created_at_gmt' => $created_at,
					'counting_started_at_gmt' => $session['counting_started_at_gmt'],
					'user_id' => get_current_user_id(),
				)
			);
		}
		return $accepted;
	}

	/** Build one stored array entry with the casts a movement row has always had.
	 *
	 * Amounts are four-place decimal strings, as the DECIMAL(19,4) column returned them;
	 * an amount not already in that shape is cast by the database, never by a float. The
	 * cast clamps out-of-range values and reads text as zero where the strict column refused
	 * them, so anything but a decimal of at most fifteen integer digits is refused first.
	 *
	 * @param array $fields Movement fields or a legacy row.
	 * @throws \RuntimeException When the amount is not a decimal the column could hold, or the cast fails.
	 */
	public static function entry( array $fields ): array {
		global $wpdb;
		$amount = (string) $fields['amount'];
		if ( ! preg_match( '/^-?\d{1,15}(\.\d+)?$/D', $amount ) ) {
			throw new \RuntimeException( 'Movement amount cast failed.' );
		}
		if ( ! preg_match( '/^-?\d+\.\d{4}$/D', $amount ) ) {
			$amount = $wpdb->get_var( $wpdb->prepare( 'SELECT CAST(%s AS DECIMAL(19,4))', $amount ) );
			if ( '' !== $wpdb->last_error || ! is_string( $amount ) ) {
				throw new \RuntimeException( 'Movement amount cast failed.' );
			}
		}
		return array(
			'id' => (string) $fields['id'],
			'type' => (string) $fields['type'],
			'amount' => $amount,
			'reason' => (string) $fields['reason'],
			'actor' => (int) $fields['actor'],
			'voids' => isset( $fields['voids'] ) ? (string) $fields['voids'] : null,
			'voided_by' => isset( $fields['voided_by'] ) ? (string) $fields['voided_by'] : null,
			'created_at_gmt' => (string) $fields['created_at_gmt'],
		);
	}

	/** Order entries as the table's ORDER BY created_at_gmt, id did.
	 *
	 * @param array $entries Entries or rows.
	 */
	public static function ordered( array $entries ): array {
		usort(
			$entries,
			static function ( $a, $b ) {
				return array( $a['created_at_gmt'], $a['id'] ) <=> array( $b['created_at_gmt'], $b['id'] );
			}
		);
		return $entries;
	}

	/** The session's stored entries, read fresh; NULL and an empty array both mean none.
	 *
	 * @param string      $session_id Session UUID.
	 * @param string|null $stored The column exactly as read, for a write that requires it unchanged.
	 * @param-out string|null $stored
	 * @throws \RuntimeException On read failure.
	 */
	private static function entries( string $session_id, ?string &$stored = null ): array {
		global $wpdb;
		$sessions = new Register_Session_Store();
		$sessions->ensure_installed();
		$table = $sessions->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
		$movements = $wpdb->get_var( $wpdb->prepare( "SELECT movements FROM {$table} WHERE id = %s", $session_id ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session movement read failed.' );
		}
		$stored = $movements;
		$entries = null === $movements ? array() : json_decode( $movements, true );
		if ( ! is_array( $entries ) ) {
			throw new \RuntimeException( 'Session movement read failed.' );
		}
		return $entries;
	}

	/** One entry as a movement row: the session id restored, in the table's column order.
	 *
	 * @param string $session_id Session UUID.
	 * @param array  $entry Stored entry.
	 */
	private static function row( string $session_id, array $entry ): array {
		return array(
			'id' => $entry['id'],
			'session_id' => $session_id,
			'type' => $entry['type'],
			'amount' => $entry['amount'],
			'reason' => $entry['reason'],
			'actor' => (int) $entry['actor'],
			'voids' => $entry['voids'],
			'voided_by' => $entry['voided_by'],
			'created_at_gmt' => $entry['created_at_gmt'],
		);
	}

	/** The entry with an id, compared as the CHAR(36) column did (case-insensitively).
	 *
	 * @param array  $entries Entries or rows.
	 * @param string $id Movement UUID.
	 */
	private static function find( array $entries, string $id ): ?array {
		$position = self::position( $entries, $id );
		return null === $position ? null : $entries[ $position ];
	}

	/** The index of the entry with an id, or null.
	 *
	 * @param array  $entries Entries or rows.
	 * @param string $id Movement UUID.
	 */
	private static function position( array $entries, string $id ): ?int {
		foreach ( $entries as $index => $entry ) {
			if ( 0 === strcasecmp( $entry['id'], $id ) ) {
				return $index;
			}
		}
		return null;
	}

	/** Every legacy movements table still to be folded, or none once the latch reaches schema 11.
	 *
	 * @throws \RuntimeException When a probe fails; a caller must not assume absence.
	 */
	private static function migrating_tables(): array {
		return Legacy_Tables::pending( self::LEGACY_TABLE, self::FOLDED_SCHEMA, 'Cash movement read refused: movement storage probe failed', 'Movement storage probe failed.' );
	}
}
