<?php
/**
 * Cash movement storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Health;

/** Append-only movements, except for the target's one-time void stamp. */
final class Cash_Movement_Store {
	public const TABLE = 'wcpos_cash_movements';
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
			session_id CHAR(36) NOT NULL,
			type VARCHAR(16) NOT NULL,
			amount DECIMAL(19,4) NOT NULL,
			reason TEXT NOT NULL,
			actor BIGINT NOT NULL,
			voids CHAR(36) NULL,
			voided_by CHAR(36) NULL,
			created_at_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY voids (voids)
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

	/** Read one movement.
	 *
	 * @param string $id UUID.
	 */
	public function get( string $id ): ?array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );
		if ( $row ) {
			$row['actor'] = (int) $row['actor'];
		}
		return $row ? $row : null;
	}

	/** List all movements oldest first, including voids.
	 *
	 * @param string $session_id Session UUID.
	 * @throws \RuntimeException On read failure.
	 */
	public function list( string $session_id ): array {
		global $wpdb;
		$this->ensure_installed();
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s ORDER BY created_at_gmt, id", $session_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session movement read failed.' );
		}
		foreach ( $rows as &$row ) {
			$row['actor'] = (int) $row['actor'];
		}
		return $rows;
	}

	/** Insert validated fields; only a null voided_by may subsequently change.
	 *
	 * @param array $fields Movement fields.
	 * @return array|\WP_Error
	 * @throws \RuntimeException On database failure.
	 */
	public function create( array $fields ) {
		global $wpdb;
		$existing = $this->get( $fields['id'] );
		if ( $existing ) {
			// An idempotent replay, not a fault: the outbox retries a movement whose
			// response was lost, and returning the existing row IS the success path.
			// Logging it at warning would put a warning in the merchant's log for
			// every recovered network timeout.
			Logger::log(
				'Cash movement already recorded; returning the existing row',
				array(
					'movement_id' => $existing['id'],
					'session_id'  => $existing['session_id'],
				)
			);
			return $existing;
		}
		$session = ( new Register_Session_Store() )->get( $fields['session_id'] );
		if ( $session && ! $this->accepts( $session, $fields['created_at_gmt'] ) ) {
			return new \WP_Error( 'wcpos_session_not_open', __( 'This movement was recorded after counting began.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		$closure = ( new Closure_Store() )->for_session( $fields['session_id'] );
		if ( $closure ) {
			( new Fiscal_Record_Store() )->ensure_installed();
		}
		$void = 'void' === $fields['type'];
		$transaction = $void || null !== $closure;
		if ( $transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
			Logger::warning(
				'Movement transaction failed.',
				array(
					'movement_id' => $fields['id'],
					'session_id' => $fields['session_id'],
					'user_id' => $fields['actor'],
				)
			);
			throw new \RuntimeException( 'Movement transaction failed.' );
		}
		try {
			if ( false === $wpdb->insert( $this->table_name(), $fields ) ) {
				Logger::warning(
					'Movement write failed.',
					array(
						'movement_id' => $fields['id'],
						'session_id' => $fields['session_id'],
						'user_id' => $fields['actor'],
					)
				);
				throw new \RuntimeException( 'Movement write failed.' );
			}
			if ( $void ) {
				$updated = $wpdb->update(
					$this->table_name(),
					array( 'voided_by' => $fields['id'] ),
					array(
						'id' => $fields['voids'],
						'session_id' => $fields['session_id'],
						'voided_by' => null,
					)
				);
				if ( false === $updated ) {
					Logger::warning(
						'Movement void stamp failed.',
						array(
							'movement_id' => $fields['id'],
							'session_id' => $fields['session_id'],
							'user_id' => $fields['actor'],
						)
					);
					throw new \RuntimeException( 'Movement void stamp failed.' );
				}
				if ( 0 === $updated ) {
					Logger::warning(
						'Cash movement refused: voids target already voided or unavailable',
						array(
							'movement_id' => $fields['id'],
							'session_id' => $fields['session_id'],
							'voids' => $fields['voids'],
							'user_id' => $fields['actor'],
						)
					);
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'wcpos_movement_void_refused', __( 'The movement has already been voided or is unavailable.', 'woocommerce-pos' ), array( 'status' => 409 ) );
				}
			}
			if ( $closure && null === ( new Fiscal_Record_Store() )->record(
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
			) ) {
				Logger::warning(
					'Late movement write failed.',
					array(
						'movement_id' => $fields['id'],
						'session_id' => $fields['session_id'],
						'user_id' => $fields['actor'],
					)
				);
				throw new \RuntimeException( 'Late movement write failed.' );
			}
			if ( $transaction ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					Logger::warning(
						'Movement commit failed.',
						array(
							'movement_id' => $fields['id'],
							'session_id' => $fields['session_id'],
							'user_id' => $fields['actor'],
						)
					);
					throw new \RuntimeException( 'Movement commit failed.' );
				}
			}
		} catch ( \RuntimeException $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		}
		$row = $this->get( $fields['id'] );
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
	/** Offline movements must predate the counting cutoff, strictly.
	 *
	 * @param array  $session Session row.
	 * @param string $created_at UTC SQL timestamp.
	 */
	public function accepts( array $session, string $created_at ): bool {
		$accepted = 'open' === $session['status'] || ( in_array( $session['status'], array( 'counting', 'closed' ), true ) && null !== $session['counting_started_at_gmt'] && $created_at < $session['counting_started_at_gmt'] );
		if ( ! $accepted ) {
			Logger::warning(
				'Cash movement refused: created_at_gmt is past the counting cutoff',
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
}
