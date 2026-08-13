<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolation is limited to the class-owned table name.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database failures are passed to exceptions, not rendered.

use WP_Error;
/**
 * Server-side idempotency + identity resolution for the generic write surface
 * (P1-0). Two concerns, one home, because both are collection-agnostic data access:
 *
 *  - **Idempotency:** a single dedup table records each applied `mutationId` → its
 *    server id, so a retried push never double-applies. ONE schema serves every
 *    collection (postmeta / usermeta / termmeta / tax tables all differ — a table
 *    is *more* uniform than meta), and it SURVIVES a delete (a delete removes the
 *    record + its meta, but the mutation row persists so a retried delete is still
 *    recognised as done).
 *  - **Identity resolution:** the client record uuid → the existing server numeric
 *    id. The server REUSES the client's `_woocommerce_pos_uuid` (#219 stamping) and
 *    NEVER re-keys; this is the lookup that finds the row to update/delete and the
 *    born-twice guard for create.
 *
 * Duck-typed by the controller (it accepts any object with these methods), so a
 * test injects an in-memory fake and the controller's apply logic stays unit-testable.
 */
class Mutation_Store {

	/**
	 * Seconds after which a still-`pending` reservation is treated as a CRASHED
	 * in-flight push and may be reclaimed. A conservative FIXED lease — deliberately
	 * NOT derived from `max_execution_time`: on non-Windows PHP that counts CPU time,
	 * not wall-clock, so a request blocked in a slow WooCommerce write / DB call / stream can
	 * run far longer than it (and `max_execution_time = 0` disables it entirely). The
	 * real wall-clock bound is the web/proxy request timeout, which kills a hung
	 * request long before this default. So a live push completes well within the
	 * lease; a genuinely crashed one self-heals after it. Reclaiming a still-running
	 * reservation would reopen the duplicate-create race the reservation prevents, so
	 * we err large. Filterable for unusual setups.
	 */
	public function reservation_ttl(): int {
		return max( 60, (int) apply_filters( 'woocommerce_pos_sync_reservation_ttl', 900 ) );
	}

	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Health::MUTATIONS_TABLE;
	}

	/** Build the mutation store schema SQL. */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (
"
			. '  mutation_id VARCHAR(36) NOT NULL,
'
			. '  collection VARCHAR(32) NOT NULL,
'
			. '  record_uuid VARCHAR(36) NOT NULL,
'
			. '  remote_id BIGINT NOT NULL,
'
			. '  operation VARCHAR(8) NOT NULL,
'
			. '  fingerprint CHAR(64) NOT NULL,
'
			. '  status VARCHAR(8) NOT NULL,
'
			. '  response_status SMALLINT NULL,
'
			. '  created_at DATETIME NOT NULL,
'
			. '  PRIMARY KEY  (mutation_id),
'
			. '  KEY collection_uuid (collection, record_uuid),
'
			. '  KEY status_created (status, created_at)
'
			. ") {$charset_collate};";
	}

	/** Install the mutation store table. */
	public function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/** A prior reservation or application of this globally unique mutationId, or null. */
	public function lookup( string $collection, string $mutation_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT collection, remote_id, operation, record_uuid, fingerprint, status, response_status FROM {$this->table_name()} WHERE mutation_id = %s",
				$mutation_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically CLAIM a mutationId before its (non-idempotent) side effect runs.
	 * `INSERT IGNORE` on the PRIMARY KEY is the atomic gate: exactly one concurrent
	 * caller inserts the `pending` row (returns true); the rest lose (false) and must
	 * replay or wait. This is what makes the create path safe against a timeout-retry
	 * overlapping its own in-flight push.
	 */
	public function reserve( string $collection, string $mutation_id, string $record_uuid, string $operation, string $fingerprint = '' ): bool {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table_name()} (mutation_id, collection, record_uuid, remote_id, operation, fingerprint, status, created_at) VALUES (%s, %s, %s, 0, %s, %s, 'pending', %s)",
				$mutation_id,
				$collection,
				$record_uuid,
				$operation,
				$fingerprint,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return 1 === (int) $affected;
	}

	/** Checkpoint a completed WooCommerce side effect before the final done transition. */
	public function mark_applied( string $mutation_id, int $remote_id, int $response_status ): bool {
		global $wpdb;
		$affected = $wpdb->update(
			$this->table_name(),
			array(
				'remote_id' => $remote_id,
				'status' => 'applied',
				'response_status' => $response_status,
			),
			array(
				'mutation_id' => $mutation_id,
				'status' => 'pending',
			)
		);
		return false !== $affected && 1 === (int) $affected;
	}

	/** Preserve a create side effect whose client identity has not yet been stamped. */
	public function mark_poison( string $mutation_id, int $remote_id, int $response_status = 201 ): bool {
		global $wpdb;
		$affected = $wpdb->update(
			$this->table_name(),
			array(
				'remote_id' => $remote_id,
				'status' => 'poison',
				'response_status' => $response_status,
			),
			array(
				'mutation_id' => $mutation_id,
				'status' => 'pending',
			)
		);
		return false !== $affected && 1 === (int) $affected;
	}

	/** Retain an uncertain create side effect for manual recovery, never stale reclaim. */
	public function mark_indeterminate( string $mutation_id, int $remote_id, int $response_status ): bool {
		global $wpdb;
		$affected = $wpdb->update(
			$this->table_name(),
			array(
				'remote_id' => $remote_id,
				'status' => 'blocked',
				'response_status' => $response_status,
			),
			array(
				'mutation_id' => $mutation_id,
				'status' => 'pending',
			)
		);
		return false !== $affected && 1 === (int) $affected;
	}

	/** Complete an identity stamp without reopening the non-idempotent create. */
	public function finalize_poison( string $mutation_id, int $remote_id ): bool {
		global $wpdb;
		$affected = $wpdb->update(
			$this->table_name(),
			array( 'status' => 'done' ),
			array(
				'mutation_id' => $mutation_id,
				'remote_id' => $remote_id,
				'status' => 'poison',
			)
		);
		if ( false === $affected || 0 !== (int) $affected ) {
			return 1 === (int) $affected;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT remote_id, status FROM {$this->table_name()} WHERE mutation_id = %s",
				$mutation_id
			),
			ARRAY_A
		);
		return is_array( $row )
			&& 'done' === ( $row['status'] ?? null )
			&& (int) ( $row['remote_id'] ?? -1 ) === $remote_id;
	}

	/** Mark a checkpointed mutation done. False means the acknowledgement is unsafe. */
	public function finalize( string $mutation_id, int $remote_id ): bool {
		global $wpdb;
		$affected = $wpdb->update(
			$this->table_name(),
			array( 'status' => 'done' ),
			array(
				'mutation_id' => $mutation_id,
				'remote_id' => $remote_id,
				'status' => 'applied',
			)
		);
		if ( false === $affected || 0 !== (int) $affected ) {
			return 1 === (int) $affected;
		}

		// A replay may have finalized the same checkpoint after this caller read
		// `applied` but before its UPDATE ran. That is already the desired durable
		// result, provided the winning finalize recorded the same remote identity.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT remote_id, status FROM {$this->table_name()} WHERE mutation_id = %s",
				$mutation_id
			),
			ARRAY_A
		);
		return is_array( $row )
			&& 'done' === ( $row['status'] ?? null )
			&& (int) ( $row['remote_id'] ?? -1 ) === $remote_id;
	}

	/** Undo a reservation whose apply FAILED, so an immediate retry can re-claim it. */
	public function release( string $mutation_id ): void {
		global $wpdb;
		$wpdb->delete(
			$this->table_name(),
			array(
				'mutation_id' => $mutation_id,
				'status' => 'pending',
			)
		);
	}

	/**
	 * Delete one batch of SETTLED rows (done/applied) past their retention.
	 *
	 * Settled rows exist to answer idempotent replays, and a client's retry
	 * horizon is hours — far inside any sane retention window. Pruning is safe
	 * because this store is a fast-path, not the only guard: a replayed create
	 * is caught by uuid identity resolution (the born-twice guard), a replayed
	 * update by the baseRevision compare (it answers 409 instead of replaying
	 * the stored ack — a different response, not a double-apply), and a
	 * replayed delete of an already-gone record is an idempotent success. An
	 * `applied` row past the window is a checkpoint whose finalize never ran
	 * (crash before the ack was sent); its create stamped the uuid before the
	 * checkpoint, so the same guards cover it.
	 *
	 * CREATE rows get their own (longer) cutoff: if a settled create's record
	 * is later deleted server-side, the uuid guard resolves nothing and a
	 * sufficiently late replay would resurrect the record as a new insert.
	 * The mutation row is the only guard for that corner, so creates are kept
	 * well past any plausible client queue age.
	 *
	 * `pending` rows are NEVER retention-pruned: they are the reservation lane
	 * and have their own TTL reclaim (see reclaim_stale()).
	 *
	 * Select-then-delete-by-key (the journal purge's pattern) rather than
	 * DELETE..ORDER BY..LIMIT: no filesort, deterministic under replication,
	 * and correct with or without the (status, created_at) index.
	 *
	 * @param string $cutoff_gmt        UTC datetime; non-create rows created before it are pruned.
	 * @param string $create_cutoff_gmt UTC datetime; create rows created before it are pruned.
	 * @param int    $limit             Maximum rows to delete.
	 *
	 * @return int Rows deleted.
	 */
	public function prune_settled( string $cutoff_gmt, string $create_cutoff_gmt, int $limit ): int {
		global $wpdb;
		if ( $limit < 1 ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT mutation_id FROM {$this->table_name()} WHERE status IN ('done','applied')"
				. " AND ( ( operation <> 'create' AND created_at < %s ) OR ( operation = 'create' AND created_at < %s ) ) LIMIT %d",
				$cutoff_gmt,
				$create_cutoff_gmt,
				$limit
			)
		);

		return $this->delete_by_mutation_ids( $ids );
	}

	/**
	 * Delete one batch of FAILURE rows (poison/blocked) older than the cutoff.
	 *
	 * Poison/blocked rows are manual-recovery records — a create side effect
	 * whose client identity was never stamped, so the uuid guard cannot catch a
	 * replay. They are rare (bounded by failures, not traffic) and are kept
	 * forever unless a site opts into a window via
	 * `woocommerce_pos_sync_mutation_failure_retention_days`.
	 *
	 * @param string $cutoff_gmt UTC datetime; only rows created before it are pruned.
	 * @param int    $limit      Maximum rows to delete.
	 *
	 * @return int Rows deleted.
	 */
	public function prune_failed( string $cutoff_gmt, int $limit ): int {
		global $wpdb;
		if ( $limit < 1 ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT mutation_id FROM {$this->table_name()} WHERE status IN ('poison','blocked') AND created_at < %s LIMIT %d",
				$cutoff_gmt,
				$limit
			)
		);

		return $this->delete_by_mutation_ids( $ids );
	}

	/**
	 * Delete rows by primary key, returning the count actually removed.
	 *
	 * @param string[] $ids Mutation ids.
	 *
	 * @return int Rows deleted.
	 */
	private function delete_by_mutation_ids( array $ids ): int {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, \count( $ids ), '%s' ) );
		$affected     = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table_name()} WHERE mutation_id IN ({$placeholders})", $ids )
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * Reclaim a STALE pending non-create reservation (a crashed in-flight push) so
	 * a retry can proceed. A pending create may already have reached WooCommerce;
	 * retain it for manual recovery rather than risk forwarding a duplicate.
	 * Returns true if one was reclaimed.
	 */
	public function reclaim_stale( string $mutation_id, int $ttl_seconds ): bool {
		global $wpdb;
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $ttl_seconds );
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE mutation_id = %s AND status = 'pending' AND operation <> 'create' AND created_at < %s",
				$mutation_id,
				$cutoff
			)
		);
		return (int) $affected > 0;
	}

	/**
	 * A stable, short, connection-scoped MySQL advisory-lock name for one record.
	 * GET_LOCK names are capped at 64 chars, so hash the collection+uuid pair.
	 */
	private function record_lock_name( string $collection, string $uuid ): string {
		return 'wcpos_rec_' . md5( $collection . '|' . strtolower( $uuid ) );
	}

	/**
	 * Acquire a per-RECORD advisory lock (collection + uuid) for the duration of an
	 * apply. Without it the optimistic-concurrency check is check-then-write: two
	 * DISTINCT mutations on the same record can both read the same current revision,
	 * both pass the baseRevision compare, and both forward — a silent lost update.
	 * Holding this lock serialises them, so the second writer re-reads the now-updated
	 * revision and gets a real 409 instead of clobbering the first.
	 *
	 * Blocks up to a short timeout (a normal write finishes well within it); returns
	 * false only if the holder is stuck past the timeout, in which case the caller
	 * surfaces a retryable busy response. The lock auto-releases if the holding
	 * connection dies, so a crashed writer never wedges a record.
	 *
	 * NOTE: GET_LOCK is connection-scoped — correct on a single node or a writer-pinned
	 * sync namespace; replica-split safety is the deferred F14 concern.
	 */
	public function acquire_record_lock( string $collection, string $uuid ): bool {
		global $wpdb;
		$timeout = max( 0, (int) apply_filters( 'woocommerce_pos_sync_record_lock_timeout', 5 ) );
		$got     = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->record_lock_name( $collection, $uuid ), $timeout )
		);
		return '1' === (string) $got; // GET_LOCK → 1 acquired, 0 timeout, NULL error
	}

	/** Release the per-record advisory lock acquired by acquire_record_lock(). */
	public function release_record_lock( string $collection, string $uuid ): void {
		global $wpdb;
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->record_lock_name( $collection, $uuid ) )
		);
	}

	/**
	 * Persist the client's uuid as the record's `_woocommerce_pos_uuid`, DIRECTLY
	 * via the meta API. This is the server half of "reuse the client's uuid, never
	 * re-key": `_woocommerce_pos_uuid` is PROTECTED meta (leading underscore), so
	 * wc/v3's REST meta handler drops it from a create/update payload — the only
	 * reliable way to make the client's recordId the server identity is to write it
	 * ourselves after the record exists. Uniform across user/post/term meta.
	 */
	public function persist_uuid( string $id_type, int $id, string $uuid ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$key = Api::UUID_META_KEY;
		switch ( $id_type ) {
			case 'user':
				update_user_meta( $id, $key, $uuid );
				return (string) get_user_meta( $id, $key, true ) === $uuid;
			case 'post':
				update_post_meta( $id, $key, $uuid );
				return (string) get_post_meta( $id, $key, true ) === $uuid;
			case 'term':
				update_term_meta( $id, $key, $uuid );
				return (string) get_term_meta( $id, $key, true ) === $uuid;
			case 'order':
				// HPOS: the uuid lives on the order object's meta, not post meta.
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
				if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
					$order->update_meta_data( $key, $uuid );
					if ( method_exists( $order, 'save' ) ) {
						$order->save();
					}
					return method_exists( $order, 'get_meta' ) && (string) $order->get_meta( $key, true ) === $uuid;
				}
				return false;
		}
		return false;
	}

	/**
	 * Persist an order's POS audit fields DIRECTLY on the order object (HPOS-safe), exactly as
	 * {@see persist_uuid} does for the uuid: these are PROTECTED (`_`-prefixed) meta that wc/v3
	 * drops from a create payload, so the only reliable place to write them is here, after the
	 * order exists. `$created_via` (an order property, not meta) is set when non-empty; `$meta`
	 * is a key→value map (e.g. `_pos_user`, `_pos_store`, cash-tender). One load + one save.
	 * A missing order (or one that can't carry meta) is a safe no-op.
	 */
	public function persist_order_audit_meta( int $id, array $meta, string $created_via = '' ): void {
		if ( $id <= 0 ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$changed = false;
		// created_via is a CONSTANT channel marker — always assert it (also corrects WC's 'rest-api'
		// default if the create payload's created_via didn't take). Safe to re-set on a replay.
		if ( '' !== $created_via && method_exists( $order, 'set_created_via' ) ) {
			$order->set_created_via( $created_via );
			$changed = true;
		}
		// The audit `_pos_*` meta is WRITE-ONCE (captured at the sale). Only fill a MISSING field —
		// never overwrite an existing one: the born-twice/existing-order path is reachable by ANY
		// known-uuid create replay, and a retry under a different cashier/store (or a buggy duplicate)
		// must not silently corrupt the original record's audit trail (codex).
		foreach ( $meta as $key => $value ) {
			if ( method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( (string) $key ) ) {
				continue;
			}
			$order->update_meta_data( (string) $key, (string) $value );
			$changed = true;
		}
		if ( $changed && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * The existing server numeric id for a client record uuid, or 0 if none. The
	 * `id_type` selects the meta store the uuid is mirrored into, so this is
	 * uniform per record kind. `tax_rate` has no native meta and is not supported.
	 *
	 * COLLISION-AWARE: a uuid is meant to identify exactly one record, but an importer,
	 * a product duplicator, or a staging->prod DB clone can copy the `_woocommerce_pos_uuid`
	 * meta onto a second record. Resolving such a uuid to an arbitrary first match would
	 * route a write/delete to the WRONG record, so we fetch up to two and **fail closed**
	 * (`WP_Error` 409 `woo_rxdb_sync_identity_ambiguous`) when more than one record carries
	 * the uuid — the caller aborts the mutation (and releases its reservation) rather than
	 * corrupt a record. A unique match is returned by lowest id (deterministic) so retries
	 * are stable.
	 *
	 * @return int|WP_Error 0 if none, the id if unique, or a 409 WP_Error if ambiguous.
	 */
	public function resolve_id_by_uuid( string $id_type, string $uuid, array $opts = array() ) {
		$key = Api::UUID_META_KEY;
		global $wpdb;
		switch ( $id_type ) {
			case 'user':
				$found = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT u.ID FROM {$wpdb->users} u"
						. " JOIN {$wpdb->usermeta} m ON m.user_id = u.ID"
						. ' WHERE m.meta_key = %s AND m.meta_value = %s'
						. ' ORDER BY u.ID ASC LIMIT 2',
						$key,
						$uuid
					)
				);
				break;
			case 'post':
				// Only a LIVE post counts as a collision owner: a trashed/auto-draft copy that
				// shares the uuid (left behind by a duplicator/importer/clone) will never be
				// served, so it must not make resolution ambiguous. This mirrors the plugin's
				// live-owner convention (class-pos-uuid.php:208, class-uuid-backfill-controller.php).
				$post_type = $opts['post_type'] ?? 'any';
				$sql  = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p"
					. " JOIN {$wpdb->postmeta} m ON m.post_id = p.ID"
					. ' WHERE m.meta_key = %s AND m.meta_value = %s'
					. " AND p.post_status NOT IN ('trash','auto-draft')";
				$args = array( $key, $uuid );
				if ( 'any' !== $post_type ) {
					$sql   .= ' AND p.post_type = %s';
					$args[] = $post_type;
				}
				$sql  .= ' ORDER BY p.ID ASC LIMIT 2';
				$found = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
				break;
			case 'term':
				$found = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT term_id FROM {$wpdb->termmeta}"
						. ' WHERE meta_key = %s AND meta_value = %s'
						. ' ORDER BY term_id ASC LIMIT 2',
						$key,
						$uuid
					)
				);
				$resolved = $this->unique_id_or_ambiguous( is_array( $found ) ? $found : array(), $id_type, $uuid );
				if ( is_wp_error( $resolved ) || 0 === $resolved ) {
					return $resolved;
				}
				$taxonomy = $opts['taxonomy'] ?? '';
				if ( '' === $taxonomy ) {
					return $resolved;
				}
				$found = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt"
						. ' WHERE tt.term_id = %d AND tt.taxonomy = %s LIMIT 1',
						$resolved,
						$taxonomy
					)
				);
				return empty( $found ) ? 0 : $resolved;
			case 'order':
				$found = Pos_Uuid::get_order_ids_by_uuid( $uuid );
				break;
			default:
				return 0;
		}

		return $this->unique_id_or_ambiguous( is_array( $found ) ? $found : array(), $id_type, $uuid );
	}

	/**
	 * Reduce a (<=2) candidate id list to a single id (0 if none), or a 409 ambiguity
	 * error when more than one distinct record carries the uuid. See resolve_id_by_uuid.
	 *
	 * @return int|WP_Error
	 */
	private function unique_id_or_ambiguous( array $found, string $id_type, string $uuid ) {
		$ids = array_values( array_unique( array_map( 'intval', $found ) ) );
		if ( count( $ids ) > 1 ) {
			return new WP_Error(
				'woo_rxdb_sync_identity_ambiguous',
				sprintf( 'uuid %s resolves to more than one %s record; refusing to write to an arbitrary match.', $uuid, $id_type ),
				array( 'status' => 409 )
			);
		}
		return empty( $ids ) ? 0 : $ids[0];
	}
}
