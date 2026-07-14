<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database failures are passed to exceptions, not rendered.

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_REST_Request;

final class Sync_Index {
	public const BACKFILL_OPTION = 'woocommerce_pos_sync_index_backfill';

	// The journal epoch identifies this generation of the AUTO_INCREMENT `sequence` space (F8).
	public const EPOCH_OPTION = 'woocommerce_pos_sync_journal_epoch';

	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Health::ORDER_INDEX_TABLE;
	}

	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (\n"
			. "  sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
			. "  order_id BIGINT UNSIGNED NOT NULL,\n"
			. "  modified_gmt DATETIME NOT NULL,\n"
			. "  revision VARCHAR(80) NOT NULL,\n"
			. "  deleted TINYINT(1) NOT NULL DEFAULT 0,\n"
			. "  origin VARCHAR(40) NOT NULL DEFAULT 'hook:update',\n"
			. "  created_gmt DATETIME NOT NULL,\n"
			. "  PRIMARY KEY  (sequence),\n"
			. "  KEY order_id (order_id),\n"
			. "  KEY modified_gmt (modified_gmt),\n"
			. "  KEY order_modified (order_id, modified_gmt)\n"
			. ") {$charset_collate};";
	}

	public function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
		if ( ! Health::table_exists( $this->table_name() ) ) {
			return;
		}
		// Regenerate the journal epoch on every (re)install (F8) — a reactivation or a schema-version
		// rebuild starts a NEW sequence generation, so clients that stored the old epoch see a
		// mismatch and resync from zero. This is the reliable rebuild signal: install() runs on the
		// activation hook and on a version bump. (A bare external truncate/restore that does NOT
		// re-run install() relies on the head backstop + the epoch differing after a restore; the
		// narrow residual — a bare truncate whose head re-climbs past a client's cursor before that
		// client next polls, with no reactivation — is tracked as a follow-up.)
		$this->regenerate_epoch();
		// If the table was rebuilt EMPTY but the backfill option still carries progress from the prior
		// generation, the new epoch would force clients to purge + re-pull from zero against an empty
		// journal — losing every order. Reset the backfill so run_backfill_chunk repopulates the index
		// from page 1. This covers BOTH a 'complete' backfill (would no-op) AND an in-progress one
		// (nextPage/processed > 0 → would resume mid-scan and skip the early orders). Guarded on
		// emptiness: an INTACT table keeps its backfill state (re-running would duplicate the
		// append-only index).
		$backfill = $this->backfill_status();
		$backfill_has_progress = 'idle' !== $backfill['status'] || $backfill['nextPage'] > 1 || $backfill['processed'] > 0;
		if ( 0 === $this->head_sequence() && $backfill_has_progress ) {
			delete_option( self::BACKFILL_OPTION );
		}
	}


	/**
	 * The journal epoch for this install — a stable id for the current `sequence` generation (F8).
	 * Minted once (a uuid) when absent and autoloaded thereafter. A client stores it beside its pull
	 * checkpoint; when the epoch it sees differs from the one it stored, the sequence space is a new
	 * generation (fresh install / cleared option) and the client must resync from zero. The `head`
	 * backstop (head_sequence) covers a same-epoch AUTO_INCREMENT reset (restore/truncate), since
	 * there is no uninstall.php to clear this option on a table drop.
	 */
	public function ensure_epoch(): string {
		$epoch = (string) get_option( self::EPOCH_OPTION, '' );
		if ( '' !== $epoch ) {
			return $epoch;
		}
		$epoch = $this->mint_epoch();
		// Autoload the epoch: it is read on every pull (hot path), so it must come from the
		// alloptions cache rather than a separate DB query per read. It is a tiny uuid string.
		add_option( self::EPOCH_OPTION, $epoch, '', true );
		// add_option is a no-op if a concurrent request already created it — read back the winner.
		return (string) get_option( self::EPOCH_OPTION, $epoch );
	}

	/** Force a new journal epoch — the current sequence generation is being (re)installed (F8). */
	public function regenerate_epoch(): string {
		$epoch = $this->mint_epoch();
		update_option( self::EPOCH_OPTION, $epoch, true ); // keep it autoloaded (hot-path read)
		return $epoch;
	}

	private function mint_epoch(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'wcpos-epoch', true ) );
	}

	/**
	 * The highest emitted sequence (the journal head), or 0 when the index is empty/absent (F8).
	 * A client whose checkpoint sequence exceeds this has a cursor past the server's head — the
	 * AUTO_INCREMENT space reset beneath it — and must resync from zero.
	 */
	public function head_sequence(): int {
		global $wpdb;
		if ( ! $this->table_available() ) {
			return 0;
		}
		$max = $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->table_name() );
		return null === $max ? 0 : (int) $max;
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_new_order', array( $this, 'record_order_created' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'record_order_updated' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'record_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'record_order_deleted' ), 10, 1 );
		// C5: UNTRASH must re-emit the order as PRESENT so a client that tombstoned it (F6) re-pulls it.
		// Deliberately NOT hooking woocommerce_untrash_order — it fires BEFORE the restore
		// (OrdersTableDataStore::untrash_order does_action THEN set_status/save), so the order still
		// reads as trashed there; recording then would store a trash-state revision → a false 409 on
		// the client's next edit, and would emit the order as present even if the restore save failed.
		// HPOS untrash is already covered correctly: the restore's $order->save() runs update(), which
		// fires woocommerce_update_order AFTER the status is restored (→ record_order_updated). Only
		// the CPT/legacy path needs an explicit hook — untrashed_post fires AFTER wp_untrash_post
		// restores the status (the integrity-digest hooks it for the same reason).
		add_action( 'untrashed_post', array( $this, 'record_post_untrashed' ), 10, 1 );
	}

	public function record_order_created( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:create', false );
	}

	public function record_order_updated( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:update', false );
	}

	public function record_post_deleted( int $post_id ): void {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}
		$this->record_order_deleted( $post_id );
	}

	public function record_order_deleted( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:delete', true );
	}

	/** An untrashed (restored) order — re-emit it as PRESENT (deleted=0) so tombstoned clients re-pull it (C5). */
	public function record_order_untrashed( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:untrash', false );
	}

	public function record_post_untrashed( int $post_id ): void {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}
		$this->record_order_untrashed( $post_id );
	}

	public function record_order_change( int $order_id, string $origin, bool $deleted ): bool {
		global $wpdb;
		$order = wc_get_order( $order_id );
		$modified_date = $order ? $order->get_date_modified() : null;
		$modified = $modified_date ? gmdate( 'Y-m-d H:i:s', $modified_date->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
		$revision = 'deleted';

		if ( $order && ! $deleted ) {
			$payload = ( new Order_Serializer() )->serialize_order( $order_id, new WP_REST_Request() );
			$sync_meta = ( new Order_Serializer() )->sync_metadata( $payload, $order_id, 'custom-pull', false, 0 );
			$revision = (string) $sync_meta['revision'];
		}

		$row = $this->index_row(
			array(
				'order_id' => $order_id,
				'modified_gmt' => $modified,
				'revision' => $revision,
				'deleted' => $deleted,
				'origin' => $origin,
			)
		);
		unset( $row['sequence'] );

		return false !== $wpdb->insert( $this->table_name(), $row, array( '%d', '%s', '%s', '%d', '%s', '%s' ) );
	}



	public function backfill_status(): array {
		$status = get_option( self::BACKFILL_OPTION, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		return array(
			'status' => isset( $status['status'] ) ? (string) $status['status'] : 'idle',
			'nextPage' => isset( $status['nextPage'] ) ? max( 1, (int) $status['nextPage'] ) : 1,
			'pageSize' => isset( $status['pageSize'] ) ? max( 1, min( 250, (int) $status['pageSize'] ) ) : null,
			'processed' => isset( $status['processed'] ) ? max( 0, (int) $status['processed'] ) : 0,
			'lastOrderId' => isset( $status['lastOrderId'] ) ? max( 0, (int) $status['lastOrderId'] ) : 0,
			'lastRunGmt' => isset( $status['lastRunGmt'] ) ? (string) $status['lastRunGmt'] : null,
		);
	}

	/**
	 * Clear the persisted backfill state — the FULL cursor (status, nextPage,
	 * pageSize, processed, lastOrderId), never just the completion marker
	 * (#423 step 3): a completed store must be able to re-run the append-only
	 * backfill so every row's revision recomputes under the CURRENT hasher
	 * (the recompute-on-rebuild cutover). Deliberately does NOT touch the F8
	 * journal epoch — the epoch versions the sequence space; the appended
	 * new-sequence rows are what make clients re-pull.
	 */
	public function reset_backfill_state(): void {
		delete_option( self::BACKFILL_OPTION );
	}

	public function run_backfill_chunk( int $limit ): array {
		$requested_limit = max( 1, min( 250, $limit ) );
		$status = $this->backfill_status();
		if ( 'complete' === $status['status'] ) {
			return array_merge( $status, array( 'processedThisRun' => 0 ) );
		}

		$page_size = null === $status['pageSize'] ? $requested_limit : (int) $status['pageSize'];
		$last_order_id = $status['lastOrderId'];
		$query_args = array(
			'type' => 'shop_order',
			'limit' => $page_size,
			'orderby' => 'ID',
			'order' => 'ASC',
			'return' => 'ids',
		);
		$posts_where = null;
		if ( $last_order_id > 0 ) {
			if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$query_args['field_query'] = array(
					array(
						'field' => 'id',
						'value' => $last_order_id,
						'compare' => '>',
					),
				);
			} else {
				$posts_where = static function ( string $where ) use ( $last_order_id ): string {
					global $wpdb;
					return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_order_id );
				};
				add_filter( 'posts_where', $posts_where );
			}
		}
		try {
			$queried_ids = wc_get_orders( $query_args );
		} finally {
			if ( null !== $posts_where ) {
				remove_filter( 'posts_where', $posts_where );
			}
		}
		/** @var array<int, int|string> $ids The `return => ids` query returns scalar ids. */
		$ids = is_array( $queried_ids ) ? $queried_ids : array();
		$ids = array_map(
			static function ( $id ): int {
				return (int) $id;
			},
			$ids
		);
		$processed_this_run = 0;
		$failed_this_run = 0;
		foreach ( $ids as $id ) {
			if ( $this->record_order_change( $id, 'backfill', false ) ) {
				$processed_this_run++;
				$last_order_id = $id;
			} else {
				$failed_this_run++;
			}
		}

		$all_writes_succeeded = 0 === $failed_this_run;
		$complete = $all_writes_succeeded && count( $ids ) < $page_size;
		$advance_page = $all_writes_succeeded && count( $ids ) === $page_size;
		$page = $status['nextPage'];
		$next_status = array(
			'status' => $complete ? 'complete' : 'running',
			'nextPage' => $advance_page ? $page + 1 : $page,
			'pageSize' => $page_size,
			'processed' => $status['processed'] + $processed_this_run,
			'lastOrderId' => $last_order_id,
			'lastRunGmt' => gmdate( 'c' ),
		);
		update_option( self::BACKFILL_OPTION, $next_status, false );

		return array_merge( $next_status, array( 'processedThisRun' => $processed_this_run ) );
	}

	public function table_available(): bool {
		global $wpdb;
		$table = $this->table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function rows_after_sequence( int $sequence, int $limit ): array {
		global $wpdb;
		if ( ! $this->table_available() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sequence, order_id, modified_gmt, revision, deleted, origin FROM ' . $this->table_name() . ' WHERE sequence > %d ORDER BY sequence ASC LIMIT %d',
				max( 0, $sequence ),
				max( 1, min( 251, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'index_row' ), $rows ) : array();
	}

	public function index_row( array $input ): array {
		return array(
			'sequence' => isset( $input['sequence'] ) ? (int) $input['sequence'] : 0,
			'order_id' => isset( $input['order_id'] ) ? (int) $input['order_id'] : 0,
			'modified_gmt' => isset( $input['modified_gmt'] ) ? (string) $input['modified_gmt'] : gmdate( 'Y-m-d H:i:s' ),
			'revision' => isset( $input['revision'] ) ? (string) $input['revision'] : '',
			'deleted' => ! empty( $input['deleted'] ) ? 1 : 0,
			'origin' => isset( $input['origin'] ) ? (string) $input['origin'] : 'hook:update',
			'created_gmt' => isset( $input['created_gmt'] ) ? (string) $input['created_gmt'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
