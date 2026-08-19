<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Logger;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database failures are passed to exceptions, not rendered.

use RuntimeException;

/**
 * Hash-backed range-checksum support: stored per-record content digests.
 *
 * STORES a digest of each product/variation's raw DB row at hook time (the
 * same save/delete hooks class-change-log.php uses), so the integrity scan
 * can compare — entirely in SQL — the aggregate of CURRENT raw-row digests
 * against the aggregate of STORED digests per id-range bucket. If hooks
 * fired for every write, stored == current (and sequence-log already
 * reported the change); a bucket mismatch therefore means exactly "content
 * changed without hooks firing" — the sql-bypass signature — at GROUP BY
 * prices instead of revision-hash's full-hydration prices.
 *
 * The digest basis is deliberately the RAW DB ROW, NOT the filtered REST
 * payload: this signal is detection-only (discovery of WHERE drift
 * happened, ADR 0003 "discovery, never values"); hydration of anything the
 * POS trusts still goes through the filtered REST path. The flip side is
 * documented too: a raw-row digest cannot see a plugin changing the served
 * representation without touching the row — that staleness case remains
 * revision-hash territory.
 *
 * This class is the WRITE half. The READ half — every question the REST read
 * surface asks of the store, plus the canonical digest SQL both halves share —
 * lives in {@see Digest_Index}. The SQL-fragment accessors that used to hang off
 * this class are kept as deprecated delegates so existing callers keep working.
 */
final class Integrity_Digest {

	/**
	 * Wall-clock ms spent inside the digest write hooks during the CURRENT
	 * request. Read (and reset) by the product-edit fixture for the
	 * hook-overhead bench's per-component breakdown. Two microtime() calls
	 * per hook fire — negligible against the INSERT…SELECT it wraps.
	 */
	public static float $request_write_ms = 0.0;

	/** @see Digest_Index::DIGESTED_META_KEYS The digest formula's home. */
	public const DIGESTED_META_KEYS = Digest_Index::DIGESTED_META_KEYS;

	/** @see Digest_Index::CUSTOMER_DIGESTED_META_KEYS The digest formula's home. */
	public const CUSTOMER_DIGESTED_META_KEYS = Digest_Index::CUSTOMER_DIGESTED_META_KEYS;

	/** @see Digest_Index::ORDER_DIGESTED_META_KEYS The digest formula's home. */
	public const ORDER_DIGESTED_META_KEYS = Digest_Index::ORDER_DIGESTED_META_KEYS;

	/** @see Digest_Index::OBJECT_TYPES_SQL The product-space object types. */
	public const OBJECT_TYPES_SQL = Digest_Index::OBJECT_TYPES_SQL;

	public const REBUILD_HOOK     = 'wcpos_integrity_digest_rebuild';
	public const REBUILD_LOCK     = 'wcpos_integrity_digest_rebuild_lock';
	public const REBUILD_LOCK_TTL = 300;

	/**
	 * The read half + the canonical digest SQL. The write statements below compose
	 * their INSERT…SELECT sources from it, so stored and current digests are
	 * computed by ONE expression — the invariant the whole scan rests on.
	 */
	private Digest_Index $index;

	public function __construct( ?Digest_Index $index = null ) {
		$this->index = $index ?? new Digest_Index();
	}

	public function table_name(): string {
		return $this->index->table_name();
	}

	/**
	 * Separate current-state table rather than a column on the change-log:
	 * the change-log is an append-only event journal (many rows per object,
	 * tombstones included) while the stored digest is exactly one row per
	 * live object — different cardinality and lifecycle. Folding the digest
	 * into the log would force a latest-row-per-object subquery on every
	 * scan, destroying the GROUP BY price this design exists for.
	 *
	 * digest is BIGINT UNSIGNED holding a 64-bit value (top 16 hex of MD5): integer
	 * storage keeps the BIT_XOR bucket aggregate a pure integer fold with
	 * constant-size state, where a CHAR hash would need GROUP_CONCAT (and
	 * its max_len truncation hazard) to aggregate.
	 */
	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (\n"
			. "  object_type VARCHAR(20) NOT NULL,\n"
			. "  object_id BIGINT UNSIGNED NOT NULL,\n"
			. "  digest BIGINT UNSIGNED NOT NULL,\n"
			. "  updated_gmt DATETIME NOT NULL,\n"
			. "  PRIMARY KEY  (object_type, object_id),\n"
			. "  KEY object_id (object_id)\n"
			. ") {$charset_collate};";
	}

	public function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
	}


	/**
	 * Same save/delete hooks the change-log listens to (products and
	 * variations only — tax rates live in their own table outside the
	 * wp_posts id space this scan buckets; they stay covered by the plain
	 * range-checksum candidate, whose checksum covers the full rate row).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_new_product', array( $this, 'record_post_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'record_post_saved' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'record_post_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'record_post_saved' ), 10, 1 );
		// Untrash does not reliably re-fire woocommerce_update_product; the
		// upsert is a no-op for non-live rows, so hooking it is free.
		add_action( 'untrashed_post', array( $this, 'record_post_untrashed' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'record_post_deleted' ), 10, 1 );

		// Leg-3 phase 7 (ADR 0015): ALL WordPress users are POS customers under
		// #1379 (1.9 parity). Saves and role changes idempotently upsert their
		// digest; only delete_user removes it.
		add_action( 'user_register', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_new_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_update_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		// add_role()/remove_role() fire ONLY add_user_role/remove_user_role, so
		// register both to capture membership changes in the served record.
		add_action( 'add_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'remove_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'record_customer_deleted' ), 10, 1 );

		// Leg-3 phase 7 (ADR 0015): order digest maintenance. Storage-agnostic WC order hooks (fire under
		// HPOS AND CPT), matching the sync-index's order hooks. upsert/delete are idempotent (no dedup).
		add_action( 'woocommerce_new_order', array( $this, 'record_order_saved' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'record_order_saved' ), 10, 1 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'record_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'record_order_deleted' ), 10, 1 );
		// Untrash recreation: `untrashed_post` (handled by record_post_untrashed)
		// never fires for COT orders — without the HPOS twin hook a restored
		// order's digest is never recreated and integrity scans treat it as
		// deleted forever.
		add_action( 'woocommerce_untrash_order', array( $this, 'record_order_untrashed' ), 10, 1 );
	}

	/**
	 * Recreate a COT order's digest once its restore completes.
	 *
	 * `woocommerce_untrash_order` fires BEFORE the data store restores the
	 * status, and the restore's internal save fires no observer hook we bind
	 * (verified: `woocommerce_update_order` does not fire there) — so an
	 * immediate upsert would read a still-trashed row and write nothing. Arm a
	 * one-shot on the order's first object save after it leaves the trash and
	 * upsert then.
	 *
	 * @param int $order_id Order being restored.
	 */
	public function record_order_untrashed( int $order_id ): void {
		$handler = function ( $order ) use ( $order_id, &$handler ): void {
			if ( ! \is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_status' ) || (int) $order->get_id() !== $order_id || 'trash' === $order->get_status() ) {
				return;
			}
			remove_action( 'woocommerce_after_order_object_save', $handler );
			$this->record_order_saved( $order_id );
		};
		add_action( 'woocommerce_after_order_object_save', $handler );
	}

	/**
	 * Cron entry point for rebuilding unexpectedly empty or stale product digests.
	 */
	public static function run_scheduled_rebuild(): void {
		$lease = get_transient( self::REBUILD_LOCK );
		try {
			( new self() )->rebuild( true );
		} catch ( \Throwable $exception ) {
			Logger::error( 'WCPOS sync: scheduled integrity digest rebuild failed: ' . $exception->getMessage() );
		} finally {
			self::release_rebuild_lock( $lease );
		}
	}

	/**
	 * Release the rebuild lease only if this run still owns it — a rebuild that
	 * outlived the lock TTL must not delete a successor's fresh lease.
	 *
	 * @param mixed $lease The lease value captured when this run started.
	 */
	public static function release_rebuild_lock( $lease ): void {
		if ( get_transient( self::REBUILD_LOCK ) === $lease ) {
			delete_transient( self::REBUILD_LOCK );
		}
	}

	/**
	 * Bulk-read the STORED 64-bit digests for the given product/variation ids → `[id => digest string]`.
	 * The digest is BIGINT UNSIGNED (above PHP_INT_MAX), so it is returned as a STRING (ADR 0014 M1).
	 * Ids with no stored digest yet (never hooked/rebuilt) are simply absent from the result.
	 */
	public function read_digests( array $ids ): array {
		global $wpdb;
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, digest FROM ' . $this->table_name()
				. ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL
				. ' AND object_id IN (' . $placeholders . ')',
				...$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ] = (string) $row['digest'];
		}
		return $out;
	}

	/**
	 * Bulk-read the STORED customer digests for the given user ids → `[id => digest string]` (ADR 0015,
	 * Leg-3 phase 7). Customers live in their OWN `'customer'` object-type rows; keeping the read separate
	 * from {@see read_digests} (products/variations) means neither id-space can bleed into the other's
	 * reconcile. Ids with no stored digest yet are simply absent.
	 */
	public function read_customer_digests( array $ids ): array {
		global $wpdb;
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, digest FROM ' . $this->table_name()
				. " WHERE object_type = 'customer'"
				. ' AND object_id IN (' . $placeholders . ')',
				...$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ] = (string) $row['digest'];
		}
		return $out;
	}

	/**
	 * Register one digest stamper per digest-and-proxy registry row (#421
	 * increment 3): the id-space owner's singular object_type names the
	 * stamper (product/customer/order → stamp_proxy_{type}_digests). Returns
	 * the registered collections (the wiring golden pins them).
	 */
	public static function register_proxy_digest_stampers(): array {
		$registered = array();
		foreach ( Collections::with( 'digest' ) as $collection => $row ) {
			if ( ! isset( $row['proxy'] ) ) {
				continue;
			}
			add_filter( 'woocommerce_pos_sync_proxy_response', array( __CLASS__, 'stamp_proxy_' . $row['object_type'] . '_digests' ), 10, 3 );
			$registered[] = $collection;
		}
		return $registered;
	}

	/**
	 * `woocommerce_pos_sync_proxy_response` filter (products): attach each served product's stored 64-bit
	 * digest as a top-level `_rxdb_digest` string, so the client can seed its existence-reconcile
	 * manifest (ADR 0014 Leg 3) as products flow through the NORMAL pull — no separate fetch. The
	 * client reads it into the sidecar manifest, it is NOT persisted into the product document. A
	 * product with no stored digest yet simply carries no `_rxdb_digest`.
	 */
	public static function stamp_proxy_product_digests( $data, $resource = '', $request = null ) {
		if ( 'products' !== $resource || ! is_array( $data ) ) {
			return $data;
		}
		$ids = array();
		foreach ( $data as $product ) {
			if ( is_array( $product ) && isset( $product['id'] ) ) {
				$ids[] = (int) $product['id'];
			}
		}
		if ( empty( $ids ) ) {
			return $data;
		}
		$digests = ( new self() )->read_digests( $ids );
		foreach ( $data as $index => $product ) {
			if ( is_array( $product ) && isset( $product['id'] ) && isset( $digests[ (int) $product['id'] ] ) ) {
				$data[ $index ]['_rxdb_digest'] = $digests[ (int) $product['id'] ];
			}
		}
		return $data;
	}

	/**
	 * `woocommerce_pos_sync_proxy_response` filter (customers) — the customer analogue of
	 * {@see stamp_proxy_product_digests} (ADR 0015, Leg-3 phase 7). Attach each served customer's stored
	 * 64-bit digest as `_rxdb_digest` so the client seeds its existence manifest for the customer id-space
	 * from the normal /customers pull. A customer with no stored digest yet simply carries none.
	 */
	public static function stamp_proxy_customer_digests( $data, $resource = '', $request = null ) {
		if ( 'customers' !== $resource || ! is_array( $data ) ) {
			return $data;
		}
		$ids = array();
		foreach ( $data as $customer ) {
			if ( is_array( $customer ) && isset( $customer['id'] ) ) {
				$ids[] = (int) $customer['id'];
			}
		}
		if ( empty( $ids ) ) {
			return $data;
		}
		$digests = ( new self() )->read_customer_digests( $ids );
		foreach ( $data as $index => $customer ) {
			if ( is_array( $customer ) && isset( $customer['id'] ) && isset( $digests[ (int) $customer['id'] ] ) ) {
				$data[ $index ]['_rxdb_digest'] = $digests[ (int) $customer['id'] ];
			}
		}
		return $data;
	}

	/**
	 * `woocommerce_pos_sync_order_pull_payloads` filter — the order analogue of {@see stamp_proxy_customer_digests}
	 * (ADR 0015, Leg-3 phase 7). Attach each served order's stored 64-bit digest as `_rxdb_digest` so the
	 * client seeds its existence manifest for the order id-space from the normal /orders pull.
	 */
	public static function stamp_proxy_order_digests( $data, $resource = '', $request = null ) {
		if ( 'orders' !== $resource || ! is_array( $data ) ) {
			return $data;
		}
		$ids = array();
		foreach ( $data as $order ) {
			if ( is_array( $order ) && isset( $order['id'] ) ) {
				$ids[] = (int) $order['id'];
			}
		}
		if ( empty( $ids ) ) {
			return $data;
		}
		$digests = ( new self() )->read_order_digests( $ids );
		foreach ( $data as $index => $order ) {
			if ( is_array( $order ) && isset( $order['id'] ) && isset( $digests[ (int) $order['id'] ] ) ) {
				$data[ $index ]['_rxdb_digest'] = $digests[ (int) $order['id'] ];
			}
		}
		return $data;
	}

	/**
	 * Canonical per-CUSTOMER digest SELECT (ADR 0015, Leg-3 phase 7).
	 *
	 * @deprecated Use {@see Digest_Index::customer_digest_select_sql()}.
	 */
	public function customer_digest_select_sql( string $where_sql = '' ): string {
		return $this->index->customer_digest_select_sql( $where_sql );
	}

	/**
	 * Bulk-read stored ORDER digests → `[id => digest string]` (ADR 0015). Orders are their OWN
	 * `'order'` object-type rows + id-space, kept separate from products/variations/customers.
	 */
	public function read_order_digests( array $ids ): array {
		global $wpdb;
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, digest FROM ' . $this->table_name()
				. " WHERE object_type = 'order'"
				. ' AND object_id IN (' . $placeholders . ')',
				...$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ] = (string) $row['digest'];
		}
		return $out;
	}

	/**
	 * Customer digest maintenance (ADR 0015, Leg-3 phase 7) — every WordPress
	 * user is a POS customer, so saves and role changes always upsert.
	 */
	public function record_customer_saved( int $user_id ): void {
		$this->observe(
			function () use ( $user_id ): void {
				$this->upsert_customer_digest( $user_id );
			}
		);
	}

	public function record_customer_deleted( int $user_id ): void {
		$this->observe(
			function () use ( $user_id ): void {
				$this->delete_customer_digest( $user_id );
			}
		);
	}

	/**
	 * Observation hooks must never break the host write that fired them: a
	 * broken or missing digest store is a sync problem (the integrity scan and
	 * the health gate surface it), not a reason to fatal a WooCommerce save.
	 * The ops paths (rebuild/prune) keep throwing — they run on demand and
	 * want the loudness.
	 *
	 * @param callable $observer The digest write to attempt.
	 */
	private function observe( callable $observer ): void {
		try {
			$observer();
		} catch ( \Throwable $e ) {
			Logger::error( 'Sync digest observer failed (sync will self-heal via scan/rebuild): ' . $e->getMessage() );
		}
	}

	private function delete_customer_digest( int $user_id ): void {
		global $wpdb;
		$deleted = $wpdb->delete(
			$this->table_name(),
			array(
				'object_type' => 'customer',
				'object_id' => $user_id,
			),
			array( '%s', '%d' )
		);
		if ( false === $deleted ) {
			throw new RuntimeException( 'delete stored customer digest failed: ' . $wpdb->last_error );
		}
	}

	/**
	 * Order digest maintenance (ADR 0015, Leg-3 phase 7). The WC order hooks are storage-agnostic (fire
	 * under HPOS AND CPT); the digest SQL's `type='shop_order'` filter makes the upsert a no-op for any
	 * non-order, so no type re-check is needed here.
	 */
	public function record_order_saved( int $order_id ): void {
		$this->observe(
			function () use ( $order_id ): void {
				$this->upsert_order_digest( $order_id );
			}
		);
	}

	public function record_order_deleted( int $order_id ): void {
		$this->observe(
			function () use ( $order_id ): void {
				$this->delete_order_digest( $order_id );
			}
		);
	}

	private function delete_order_digest( int $order_id ): void {
		global $wpdb;
		$deleted = $wpdb->delete(
			$this->table_name(),
			array(
				'object_type' => 'order',
				'object_id' => $order_id,
			),
			array( '%s', '%d' )
		);
		if ( false === $deleted ) {
			throw new RuntimeException( 'delete stored order digest failed: ' . $wpdb->last_error );
		}
	}

	/** Order analogue of {@see upsert_customer_digest}: compute + store one order's digest (HPOS or CPT). */
	public function upsert_order_digest( int $order_id ): void {
		global $wpdb;
		$started = microtime( true );
		$this->index->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->index->order_digest_select_sql( '{id} = %d' ) . ') t'
				. ' ON DUPLICATE KEY UPDATE digest = VALUES(digest), updated_gmt = VALUES(updated_gmt)',
				$order_id
			)
		);
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
		if ( false === $result ) {
			throw new RuntimeException( 'upsert stored order digest failed: ' . $wpdb->last_error );
		}
	}

	public function record_post_saved( int $post_id ): void {
		$this->observe(
			function () use ( $post_id ): void {
				$this->upsert_digest( $post_id );
			}
		);
	}

	public function record_post_untrashed( int $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$this->record_order_saved( $post_id );
			return;
		}
		$this->record_post_saved( $post_id );
	}

	public function record_post_deleted( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$this->observe(
			function () use ( $post_id, $post_type ): void {
				$this->delete_post_digest( $post_id, $post_type );
			}
		);
	}

	/**
	 * Remove a product/variation digest row after a hooked delete.
	 *
	 * A hooked delete removes the stored row so stored == current again.
	 * Only a hook-BYPASSING delete leaves an orphan digest behind, which
	 * the scan reports as a mismatch (stored side carries a row the
	 * current side lacks) and the drill-down labels status=deleted.
	 *
	 * @param int    $post_id   The deleted post id.
	 * @param string $post_type Its post type (product | product_variation).
	 */
	private function delete_post_digest( int $post_id, string $post_type ): void {
		global $wpdb;
		$started = microtime( true );
		$deleted = $wpdb->delete(
			$this->table_name(),
			array(
				'object_type' => 'product_variation' === $post_type ? 'variation' : 'product',
				'object_id' => $post_id,
			),
			array( '%s', '%d' )
		);
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
		if ( false === $deleted ) {
			throw new RuntimeException( 'delete stored digest failed: ' . $wpdb->last_error );
		}
	}

	/**
	 * One round trip: the digest is computed in SQL from the raw row and
	 * upserted in the same statement — PHP never materializes the value.
	 * No-op for rows outside the live predicate (the delete hook owns those).
	 */
	public function upsert_digest( int $post_id ): void {
		global $wpdb;
		// Time from BEFORE the session setup so timing.digest_ms covers ALL digest hook work
		// (the raise runs inside the save hook — codex P3).
		$started = microtime( true );
		$this->index->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->index->row_digest_select_sql( 'p.ID = %d' ) . ') t'
				. ' ON DUPLICATE KEY UPDATE digest = VALUES(digest), updated_gmt = VALUES(updated_gmt)',
				$post_id
			)
		);
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
		if ( false === $result ) {
			throw new RuntimeException( 'upsert stored digest failed: ' . $wpdb->last_error );
		}
	}

	/**
	 * Customer analogue of {@see upsert_digest} (ADR 0015, Leg-3 phase 7):
	 * compute and store one WordPress user's customer digest in a single
	 * INSERT…SELECT. Only the delete hook removes it.
	 */
	public function upsert_customer_digest( int $user_id ): void {
		global $wpdb;
		$started = microtime( true );
		$this->index->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->index->customer_digest_select_sql( 'u.ID = %d' ) . ') t'
				. ' ON DUPLICATE KEY UPDATE digest = VALUES(digest), updated_gmt = VALUES(updated_gmt)',
				$user_id
			)
		);
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
		if ( false === $result ) {
			throw new RuntimeException( 'upsert stored customer digest failed: ' . $wpdb->last_error );
		}
	}

	/**
	 * Backfill/repair: prune orphans, then digest every live row in one
	 * INSERT…SELECT pass. Pre-existing catalogs (the 10k seed) become fully
	 * digestable in one call; measured timing is returned so the lab can
	 * report the backfill price.
	 *
	 * @param bool $products_only Whether to stop after rebuilding product digests.
	 */
	public function rebuild( bool $products_only = false ): array {
		global $wpdb;
		$this->index->raise_group_concat_max_len();
		$started = microtime( true );

		$orphans_deleted = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL
			. ' AND NOT ' . $this->index->live_row_exists_sql( 'object_id' )
		);
		if ( false === $orphans_deleted ) {
			throw new RuntimeException( 'prune orphan stored digests failed: ' . $wpdb->last_error );
		}

		// Affected-rows semantics of ON DUPLICATE KEY: 1 per insert, 2 per
		// update, 0 per already-matching row — reported raw as "writes".
		// updated_gmt is assigned FIRST and only when the digest actually
		// changed (assignments evaluate left-to-right, so the IF must read
		// the pre-update digest before the digest assignment overwrites it).
		// Otherwise a repeated rebuild rewrites UTC_TIMESTAMP() into every
		// row, counts the whole table as writes, and inflates the
		// hash-checksum baseline cost (codex review).
		$writes = $wpdb->query(
			'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
			. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
			. ' FROM (' . $this->index->row_digest_select_sql() . ') t'
			. ' ON DUPLICATE KEY UPDATE'
			. ' updated_gmt = IF(digest <=> VALUES(digest), updated_gmt, VALUES(updated_gmt)),'
			. ' digest = VALUES(digest)'
		);
		if ( false === $writes ) {
			throw new RuntimeException( 'rebuild stored digests failed: ' . $wpdb->last_error );
		}

		if ( $products_only ) {
			$stored_total = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL
			);

			return array(
				'writes' => (int) $writes,
				'orphans_deleted' => (int) $orphans_deleted,
				'stored_total' => $stored_total,
				'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			);
		}

		// Leg-3 phase 7 (ADR 0015): customers share the digest table via their own 'customer' rows —
		// the same prune-orphans + INSERT…SELECT pass, over the customer predicate + id-space. A stored
		// customer whose user vanished or lost the customer role is an orphan (a role removal never fires
		// before_delete_post, so the rebuild is the backstop that reconciles it).
		$customer_orphans = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. " WHERE object_type = 'customer'"
			. ' AND NOT ' . $this->index->customer_live_row_exists_sql( 'object_id' )
		);
		if ( false === $customer_orphans ) {
			throw new RuntimeException( 'prune orphan customer digests failed: ' . $wpdb->last_error );
		}
		$customer_writes = $wpdb->query(
			'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
			. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
			. ' FROM (' . $this->index->customer_digest_select_sql() . ') t'
			. ' ON DUPLICATE KEY UPDATE'
			. ' updated_gmt = IF(digest <=> VALUES(digest), updated_gmt, VALUES(updated_gmt)),'
			. ' digest = VALUES(digest)'
		);
		if ( false === $customer_writes ) {
			throw new RuntimeException( 'rebuild customer digests failed: ' . $wpdb->last_error );
		}

		// Leg-3 phase 7 (ADR 0015): orders share the digest table via their own 'order' rows (HPOS or CPT).
		// Same prune-orphans + INSERT…SELECT pass; Digest_Index::order_digest_select_sql() emits the storage-correct SQL
		// (the CPT path GROUP BYs, the HPOS path does not — both valid as an INSERT…SELECT source).
		$order_orphans = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. " WHERE object_type = 'order'"
			. ' AND NOT ' . $this->index->order_live_row_exists_sql( 'object_id' )
		);
		if ( false === $order_orphans ) {
			throw new RuntimeException( 'prune orphan order digests failed: ' . $wpdb->last_error );
		}
		$order_writes = $wpdb->query(
			'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
			. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
			. ' FROM (' . $this->index->order_digest_select_sql() . ') t'
			. ' ON DUPLICATE KEY UPDATE'
			. ' updated_gmt = IF(digest <=> VALUES(digest), updated_gmt, VALUES(updated_gmt)),'
			. ' digest = VALUES(digest)'
		);
		if ( false === $order_writes ) {
			throw new RuntimeException( 'rebuild order digests failed: ' . $wpdb->last_error );
		}

		$stored_total = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL
		);
		$customer_stored_total = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $this->table_name() . " WHERE object_type = 'customer'"
		);
		$order_stored_total = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $this->table_name() . " WHERE object_type = 'order'"
		);

		return array(
			'writes' => (int) $writes + (int) $customer_writes + (int) $order_writes,
			'orphans_deleted' => (int) $orphans_deleted + (int) $customer_orphans + (int) $order_orphans,
			'stored_total' => $stored_total + $customer_stored_total + $order_stored_total,
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
		);
	}
}
