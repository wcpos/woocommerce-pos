<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use internal table names and generated SQL fragments.

/**
 * The READ half of the integrity-digest store — the sync engine's query object.
 *
 * {@see Integrity_Digest} owns the WRITE half (hook-time upserts, deletes and the
 * rebuild); this class owns every question the read surface asks of the digest
 * store, and the canonical SQL both halves share. Callers name a COLLECTION, a
 * bucket RANGE and (optionally) FILTERS — never a table, a column or a JOIN.
 *
 * Three questions, one per REST consumer:
 *
 *   - {@see bucket_aggregates} — stored-vs-current BIT_XOR per id-range bucket (the scan).
 *   - {@see bucket_drift}      — the per-id mismatches inside ONE bucket (the drill-down).
 *   - {@see bucket_listing}    — the authoritative live {id, digest, object_type} for one bucket.
 *
 * Plus the two scoping questions the same id-space owns:
 * {@see published_product_ids} (the readable-catalog filter, one home for a rule
 * that used to be re-spelled in every consumer) and {@see needs_product_rebuild}.
 *
 * Servable scoping — publish state and POS visibility — lives HERE rather than in
 * the controllers, so "what the POS may see" is answered identically wherever the
 * digest store is read. Visibility comes from {@see Pos_Visibility}, unchanged.
 */
final class Digest_Index {

	/**
	 * Postmeta keys covered by the product/variation digest. Must include every key the
	 * sql-bypass fixture mutates (_price and _regular_price today — see
	 * class-fixtures-controller.php sql_bypass()) plus the keys a
	 * hook-bypassing import/inventory tool plausibly touches.
	 */
	public const DIGESTED_META_KEYS = array( '_global_unique_id', '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status' );

	/**
	 * Customer usermeta folded into the digest (ADR 0015, Leg-3 phase 7). Kept small + stable —
	 * identity/existence fields the POS keys on, NOT every usermeta row (a churny meta bloats the digest
	 * with irrelevant drift). The customer's core columns (email, display name, registered) come from
	 * wp_users directly; these four are the identifying usermeta. The site-prefixed `capabilities`
	 * meta joins them at runtime in {@see customer_digest_select_sql} (the prefix is per-site, so it
	 * cannot live in a const): roles are part of the served record under #1379, and a hookless
	 * capabilities write (direct update_user_meta/SQL/import) must drift the digest so the
	 * integrity scan can repair the stale role — no role/profile hook fires for those writes.
	 */
	public const CUSTOMER_DIGESTED_META_KEYS = array( 'first_name', 'last_name', 'billing_email', 'billing_phone' );

	/**
	 * Order postmeta folded into the digest under the CPT (legacy) storage path (ADR 0015, Leg-3 phase 7).
	 * Under HPOS these live as wc_orders COLUMNS (total_amount, customer_id) so no meta join is needed;
	 * this allowlist only applies to the wp_posts fallback. Kept minimal — existence/identity signal.
	 */
	public const ORDER_DIGESTED_META_KEYS = array( '_order_total', '_customer_user' );

	/**
	 * The digest object_types sharing the wp_posts (product-space) id range.
	 */
	public const OBJECT_TYPES_SQL = "('product','variation')";

	private const PRODUCT_POST_TYPES_SQL = "('product','product_variation')";
	private const EXCLUDED_POST_STATUSES_SQL = "('trash','auto-draft')";

	/**
	 * The POS servable-set contract (ADR 0014 WP-M5). Injectable for tests; the
	 * default instance reads the live visibility option.
	 */
	private Pos_Visibility $visibility;

	public function __construct( ?Pos_Visibility $visibility = null ) {
		$this->visibility = $visibility ?? new Pos_Visibility();
	}

	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Health::STORED_DIGEST_TABLE;
	}

	/**
	 * Raise the session `group_concat_max_len` before ANY query built on {@see row_digest_select_sql}.
	 * That expression GROUP_CONCATs the digested meta; MySQL's default (1024 bytes) SILENTLY TRUNCATES
	 * for a row with many/large meta. And because the consumers (hook upsert, rebuild, scan,
	 * drill-down, bucket listing) MUST produce byte-identical digests, a truncation on one path but not
	 * another is a PERMANENT false-drift bug — the bucket never converges. So raise it identically
	 * everywhere the expression runs.
	 */
	public function raise_group_concat_max_len(): void {
		global $wpdb;
		$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' );
	}

	/**
	 * Canonical per-row digest SELECT, shared verbatim by the hook upsert,
	 * the scan's current-side aggregate, the drill-down and the rebuild —
	 * the stored-vs-current comparison is only sound when every consumer
	 * computes the digest with the byte-identical expression.
	 *
	 * 64-bit digest — CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|', ...)),1,16),16,10) AS UNSIGNED) — over the wp_posts content columns plus
	 * the DIGESTED_META_KEYS rows (key=value pairs ordered by meta_key then
	 * meta_id, so duplicate meta rows digest deterministically). Every
	 * nullable operand is COALESCE'd because CONCAT_WS silently SKIPS NULL
	 * arguments — ('a', NULL, 'b') would collide with ('a', 'b', '') —
	 * while COALESCE keeps every position present and deterministic.
	 *
	 * $where_sql may reference alias p and contain placeholders; callers
	 * run the final statement through $wpdb->prepare.
	 *
	 * @internal Engine-internal: the digest expression, not a read-surface contract.
	 */
	public function row_digest_select_sql( string $where_sql = '' ): string {
		global $wpdb;
		$meta_keys_sql = "('" . implode( "','", self::DIGESTED_META_KEYS ) . "')";

		return 'SELECT p.ID AS id,'
			. " CASE WHEN p.post_type = 'product_variation' THEN 'variation' ELSE 'product' END AS object_type,"
			. " CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|',"
			. ' p.ID,'
			. " COALESCE(p.post_title,''),"
			. " COALESCE(p.post_excerpt,''),"
			. " COALESCE(p.post_content,''),"
			. " COALESCE(p.post_status,''),"
			. ' COALESCE(p.post_parent,0),'
			. ' COALESCE(p.menu_order,0),'
			. " COALESCE(p.post_modified_gmt,''),"
			. " COALESCE(GROUP_CONCAT(CONCAT(pm.meta_key,'=',COALESCE(pm.meta_value,'')) ORDER BY pm.meta_key ASC, pm.meta_id ASC SEPARATOR '|'),'')"
			// 64-bit digest (ADR 0014 M1): the top 16 hex of MD5 → an integer that still folds under
			// BIT_XOR and fits the BIGINT UNSIGNED column, dropping the CRC32 collision floor from
			// 2^-32 to 2^-64 (a stable per-bucket false "in sync" is unacceptable in a convergence
			// backstop, and CRC32 is linear so structured bulk edits correlate collisions).
			. ')),1,16),16,10) AS UNSIGNED) AS crc'
			. " FROM {$wpdb->posts} p"
			. " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN {$meta_keys_sql}"
			. ' WHERE p.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
			. ' AND p.post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL
			. ( '' === $where_sql ? '' : ' AND ' . $where_sql )
			. ' GROUP BY p.ID';
	}

	/**
	 * Canonical per-CUSTOMER digest SELECT (ADR 0015, Leg-3 phase 7) — the wp_users analogue of
	 * {@see row_digest_select_sql}. Same 64-bit MD5-derived formula (BIT_XOR-foldable), over a customer's
	 * identity columns + the allowlisted usermeta. ALL wp_users rows are POS customers under #1379
	 * (1.9 parity). `$where_sql` narrows to a single user for the hook upsert (`u.ID = %d`);
	 * empty selects every user.
	 *
	 * @internal Engine-internal: the digest expression, not a read-surface contract.
	 */
	public function customer_digest_select_sql( string $where_sql = '' ): string {
		global $wpdb;
		$meta_keys_sql = "('" . implode( "','", array_merge( self::CUSTOMER_DIGESTED_META_KEYS, array( $wpdb->prefix . 'capabilities' ) ) ) . "')";

		return 'SELECT u.ID AS id,'
			. " 'customer' AS object_type,"
			. " CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|',"
			. ' u.ID,'
			. " COALESCE(u.user_email,''),"
			. " COALESCE(u.display_name,''),"
			. " COALESCE(u.user_registered,''),"
			. " COALESCE(GROUP_CONCAT(CONCAT(um.meta_key,'=',COALESCE(um.meta_value,'')) ORDER BY um.meta_key ASC, um.umeta_id ASC SEPARATOR '|'),'')"
			// 64-bit digest (ADR 0014 M1): top 16 hex of MD5 → BIGINT UNSIGNED, folds under BIT_XOR.
			. ')),1,16),16,10) AS UNSIGNED) AS crc'
			. " FROM {$wpdb->users} u"
			. " LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key IN {$meta_keys_sql}"
			. ( '' === $where_sql ? '' : ' WHERE ' . $where_sql )
			. ' GROUP BY u.ID';
	}

	/**
	 * Canonical per-ORDER digest SELECT (ADR 0015, Leg-3 phase 7) — HPOS/CPT-aware. Under HPOS orders live
	 * in WooCommerce's own {prefix}wc_orders table (status/total/customer are COLUMNS); under legacy CPT
	 * they're wp_posts + postmeta. Per install exactly ONE path runs (an install is HPOS or CPT, never both),
	 * so stored-vs-current always uses the same path — self-consistent. Same 64-bit formula.
	 *
	 * `$id_condition` narrows to a single order / a bucket range using the neutral `{id}` placeholder,
	 * substituted with the path's real id column (o.id HPOS, p.ID CPT) so callers stay path-agnostic.
	 *
	 * LIVE-VERIFY: the HPOS column set is shape-asserted here (fake wpdb); verify against a real HPOS store.
	 *
	 * @internal Engine-internal: the digest expression, not a read-surface contract.
	 */
	public function order_digest_select_sql( string $id_condition = '' ): string {
		global $wpdb;
		$hpos = $this->orders_are_hpos();
		$id_col = $hpos ? 'o.id' : 'p.ID';
		$condition = '' === $id_condition ? '' : ' AND ' . str_replace( '{id}', $id_col, $id_condition );

		if ( $hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			return 'SELECT o.id AS id,'
				. " 'order' AS object_type,"
				. " CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|',"
				. ' o.id,'
				. " COALESCE(o.status,''),"
				. " COALESCE(o.type,''),"
				. " COALESCE(o.total_amount,''),"
				. ' COALESCE(o.customer_id,0),'
				. " COALESCE(o.date_updated_gmt,''))),1,16),16,10) AS UNSIGNED) AS crc"
				. " FROM {$orders_table} o"
				. " WHERE o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft')"
				. $condition;
		}

		$meta_keys_sql = "('" . implode( "','", self::ORDER_DIGESTED_META_KEYS ) . "')";
		return 'SELECT p.ID AS id,'
			. " 'order' AS object_type,"
			. " CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|',"
			. ' p.ID,'
			. " COALESCE(p.post_status,''),"
			. " COALESCE(p.post_modified_gmt,''),"
			. " COALESCE(GROUP_CONCAT(CONCAT(pm.meta_key,'=',COALESCE(pm.meta_value,'')) ORDER BY pm.meta_key ASC, pm.meta_id ASC SEPARATOR '|'),''))),1,16),16,10) AS UNSIGNED) AS crc"
			. " FROM {$wpdb->posts} p"
			. " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN {$meta_keys_sql}"
			. " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN " . self::EXCLUDED_POST_STATUSES_SQL
			. $condition
			. ' GROUP BY p.ID';
	}

	/**
	 * Live-row predicate reused by the drill-down's deleted branch and the rebuild's orphan prune.
	 *
	 * @internal Engine-internal SQL fragment.
	 */
	public function live_row_exists_sql( string $id_expr ): string {
		global $wpdb;

		return "EXISTS (SELECT 1 FROM {$wpdb->posts} lp WHERE lp.ID = {$id_expr}"
			. ' AND lp.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
			. ' AND lp.post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL . ')';
	}

	/**
	 * Customer analogue of {@see live_row_exists_sql}: the id still names any WordPress user (ADR 0015).
	 *
	 * @internal Engine-internal SQL fragment.
	 */
	public function customer_live_row_exists_sql( string $id_expr ): string {
		global $wpdb;

		return "EXISTS (SELECT 1 FROM {$wpdb->users} lu WHERE lu.ID = {$id_expr})";
	}

	/**
	 * Order analogue of {@see live_row_exists_sql} — HPOS/CPT-aware (ADR 0015, Leg-3 phase 7).
	 *
	 * @internal Engine-internal SQL fragment.
	 */
	public function order_live_row_exists_sql( string $id_expr ): string {
		global $wpdb;
		if ( $this->orders_are_hpos() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			return "EXISTS (SELECT 1 FROM {$orders_table} lo WHERE lo.id = {$id_expr}"
				. " AND lo.type = 'shop_order' AND lo.status NOT IN ('trash','auto-draft'))";
		}
		return "EXISTS (SELECT 1 FROM {$wpdb->posts} lp WHERE lp.ID = {$id_expr}"
			. " AND lp.post_type = 'shop_order' AND lp.post_status NOT IN " . self::EXCLUDED_POST_STATUSES_SQL . ')';
	}

	/**
	 * Stored-vs-current bucket aggregate over one id window (the integrity scan).
	 *
	 * Bucket aggregate: BIT_XOR over per-row 64-bit digests, deliberately instead of
	 * MD5(GROUP_CONCAT(... ORDER BY id)). XOR is commutative and associative, so the
	 * aggregate needs no ORDER BY and carries no group_concat_max_len truncation
	 * hazard; its state is a constant-size integer regardless of bucket population.
	 * Record counts travel alongside so add/delete imbalances that could cancel in
	 * XOR still flag.
	 *
	 * Digests are unsigned 64-bit (ADR 0014 M1) — above PHP_INT_MAX, so an (int) cast
	 * would SATURATE two distinct high-bit values to the same number and report a
	 * drifted bucket as `match`, hiding drift. They stay strings end to end.
	 *
	 * `max_id` is the larger of both sides' max id, so orphaned stored digests past
	 * the last live post still get scanned before the walk is called complete.
	 *
	 * @param array $range { Bucket window. @type int $bucket_size, @type int $start, @type int $end }
	 * @return array{buckets: array<int, array<string, mixed>>, max_id: int}
	 */
	public function bucket_aggregates( array $range, string $collection = 'products', array $filters = array() ): array {
		global $wpdb;
		$bucket_size  = max( 1, (int) ( $range['bucket_size'] ?? 1 ) );
		$window_start = max( 0, (int) ( $range['start'] ?? 0 ) );
		$window_end   = max( 0, (int) ( $range['end'] ?? 0 ) );
		$publish      = 'products' === $collection && 'publish' === ( $filters['status'] ?? '' );
		$object_types = self::OBJECT_TYPES_SQL;
		$current_sql  = $this->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' );
		$max_sql      = $this->row_digest_select_sql();
		if ( 'customers' === $collection ) {
			$object_types = "('customer')";
			$current_sql  = $this->customer_digest_select_sql( 'u.ID >= %d AND u.ID < %d' );
			$max_sql      = $this->customer_digest_select_sql();
		} elseif ( 'orders' === $collection ) {
			$object_types = "('order')";
			$current_sql  = $this->order_digest_select_sql( '{id} >= %d AND {id} < %d' );
			$max_sql      = $this->order_digest_select_sql();
		}
		$current_scope = $publish ? $this->product_servable_predicate_sql( 't.id', true ) : array(
			'sql' => '',
			'args' => array(),
		);
		$stored_scope  = $publish ? $this->product_servable_predicate_sql( 'd.object_id', true ) : array(
			'sql' => '',
			'args' => array(),
		);
		$current_join  = $publish ? " INNER JOIN {$wpdb->posts} catalog_post ON catalog_post.ID = t.id LEFT JOIN {$wpdb->posts} parent_product ON parent_product.ID = catalog_post.post_parent AND catalog_post.post_type = 'product_variation'" : '';
		$stored_join   = $publish ? " INNER JOIN {$wpdb->posts} catalog_post ON catalog_post.ID = d.object_id LEFT JOIN {$wpdb->posts} parent_product ON parent_product.ID = catalog_post.post_parent AND catalog_post.post_type = 'product_variation'" : '';

		// Current side: one SQL pass — per-row canonical digests aggregated
		// per bucket inside the DB engine. Raw rows are digested for
		// DETECTION only; hydration goes through filtered REST (ADR 0003).
		// Raise group_concat_max_len so the current-side digest matches the
		// stored-side (written by the hook) byte-for-byte (ADR 0014 / no truncation drift).
		$this->raise_group_concat_max_len();
		$current_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT FLOOR(t.id / %d) AS bucket, COUNT(*) AS record_count, BIT_XOR(t.crc) AS digest'
				. ' FROM (' . $current_sql . ') t' . $current_join . ( '' === $current_scope['sql'] ? '' : ' WHERE ' . $current_scope['sql'] )
				. ' GROUP BY bucket ORDER BY bucket',
				$bucket_size,
				$window_start,
				$window_end,
				...$current_scope['args']
			),
			ARRAY_A
		);

		// Stored side: one SQL pass over the hook-maintained digest table.
		$stored_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT FLOOR(d.object_id / %d) AS bucket, COUNT(*) AS record_count, BIT_XOR(d.digest) AS digest'
				. ' FROM ' . $this->table_name() . ' d' . $stored_join
				. ' WHERE d.object_type IN ' . $object_types
				. ' AND d.object_id >= %d AND d.object_id < %d' . ( '' === $stored_scope['sql'] ? '' : ' AND ' . $stored_scope['sql'] )
				. ' GROUP BY bucket ORDER BY bucket',
				$bucket_size,
				$window_start,
				$window_end,
				...$stored_scope['args']
			),
			ARRAY_A
		);

		$sides = array();
		foreach ( \is_array( $stored_rows ) ? $stored_rows : array() as $row ) {
			$sides[ (int) $row['bucket'] ]['stored'] = $row;
		}
		foreach ( \is_array( $current_rows ) ? $current_rows : array() as $row ) {
			$sides[ (int) $row['bucket'] ]['current'] = $row;
		}
		ksort( $sides );

		$buckets = array();
		foreach ( $sides as $bucket => $side ) {
			$stored_count   = isset( $side['stored'] ) ? (int) $side['stored']['record_count'] : 0;
			$current_count  = isset( $side['current'] ) ? (int) $side['current']['record_count'] : 0;
			$stored_digest  = isset( $side['stored'] ) ? (string) $side['stored']['digest'] : '';
			$current_digest = isset( $side['current'] ) ? (string) $side['current']['digest'] : '';
			$buckets[]      = array(
				'bucket'         => $bucket,
				'range'          => array(
					'start' => $bucket * $bucket_size,
					'end' => ( $bucket + 1 ) * $bucket_size,
				),
				'stored_count'   => $stored_count,
				'current_count'  => $current_count,
				'stored_digest'  => $stored_digest,
				'current_digest' => $current_digest,
				'match'          => $stored_count === $current_count && $stored_digest === $current_digest,
			);
		}

		$max_query =
			'SELECT GREATEST('
			. "COALESCE((SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN " . self::PRODUCT_POST_TYPES_SQL
			. ' AND post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL . '), 0),'
			. ' COALESCE((SELECT MAX(object_id) FROM ' . $this->table_name()
			. ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL . '), 0))';
		$max_args = array();
		if ( 'products' !== $collection || $publish ) {
			$live_scope = $publish ? $this->product_servable_predicate_sql( 'live.id', true ) : array(
				'sql' => '',
				'args' => array(),
			);
			$live_join  = $publish ? " INNER JOIN {$wpdb->posts} catalog_post ON catalog_post.ID = live.id LEFT JOIN {$wpdb->posts} parent_product ON parent_product.ID = catalog_post.post_parent AND catalog_post.post_type = 'product_variation'" : '';
			$max_query  = 'SELECT GREATEST(COALESCE((SELECT MAX(live.id) FROM (' . $max_sql . ') live' . $live_join . ( '' === $live_scope['sql'] ? '' : ' WHERE ' . $live_scope['sql'] ) . '), 0),'
				. ' COALESCE((SELECT MAX(d.object_id) FROM ' . $this->table_name() . ' d'
				. ' WHERE d.object_type IN ' . $object_types . '), 0))';
			$max_args   = $live_scope['args'];
		}
		$max_id = (int) $wpdb->get_var( empty( $max_args ) ? $max_query : $wpdb->prepare( $max_query, ...$max_args ) );

		return array(
			'buckets' => $buckets,
			'max_id' => $max_id,
		);
	}

	/**
	 * Per-id stored-vs-current comparison inside ONE bucket (the scan drill-down).
	 *
	 * Three mismatch shapes: changed (both sides present, digests differ),
	 * missing_stored (live row never digested — created without hooks or
	 * pre-backfill), deleted (stored digest whose row is gone — hook-bypassing
	 * delete). Digests stay strings (ADR 0014 M1) and are null where the side is
	 * absent.
	 *
	 * @param array $range { Bucket window. @type int $start, @type int $end }
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function bucket_drift( array $range ): array {
		global $wpdb;
		$range_start = max( 0, (int) ( $range['start'] ?? 0 ) );
		$range_end   = max( 0, (int) ( $range['end'] ?? 0 ) );
		$table       = $this->table_name();

		// Same-formula invariant: the current side must digest identically to the stored side.
		$this->raise_group_concat_max_len();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cur.id AS id,'
				. " CASE WHEN d.digest IS NULL THEN 'missing_stored' ELSE 'changed' END AS status,"
				. ' d.digest AS stored_digest, cur.crc AS current_digest, cur.object_type AS object_type'
				. ' FROM (' . $this->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' ) . ') cur'
				. " LEFT JOIN {$table} d ON d.object_id = cur.id AND d.object_type = cur.object_type"
				. ' WHERE d.digest IS NULL OR d.digest <> cur.crc'
				. ' UNION ALL'
				. " SELECT d.object_id AS id, 'deleted' AS status, d.digest AS stored_digest, NULL AS current_digest, d.object_type AS object_type"
				. " FROM {$table} d"
				. ' WHERE d.object_type IN ' . self::OBJECT_TYPES_SQL
				. ' AND d.object_id >= %d AND d.object_id < %d'
				. ' AND NOT ' . $this->live_row_exists_sql( 'd.object_id' )
				. ' ORDER BY id ASC',
				$range_start,
				$range_end,
				$range_start,
				$range_end
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id' => (int) $row['id'],
					'status' => (string) $row['status'],
					'object_type' => (string) ( $row['object_type'] ?? '' ),
					// Unsigned 64-bit (ADR 0014 M1): keep as strings — a (int) cast (and JS Number) can't
					// hold values above PHP_INT_MAX / 2^53 without precision loss.
					'stored_digest' => null === $row['stored_digest'] ? null : (string) $row['stored_digest'],
					'current_digest' => null === $row['current_digest'] ? null : (string) $row['current_digest'],
				);
			},
			\is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * The authoritative current {id, digest, object_type} for every live SERVABLE record whose id falls
	 * in the given range of the collection's own id-space (products/variations over wp_posts, customers
	 * over wp_users, orders over HPOS or CPT). Digests come from the SAME 64-bit formula the client's
	 * manifest stores, so the two compare apples-to-apples.
	 *
	 * Products carry the servable scoping the pull filter applies, so the reconcile prunes anything the
	 * POS may no longer see: the optional `status => publish` readable-catalog filter, and ALWAYS the
	 * POS-hidden (`online_only`) ids. READ-SIDE ONLY — a visibility toggle changes no product row (no
	 * hook fires), so stored per-record digests are never touched; omitting the ids from this read is
	 * enough because the client folds THIS list. Products and variations share the wp_posts id-space, so
	 * their two hidden lists union safely on cur.id.
	 *
	 * @param string $collection Digest id-space owner: products | customers | orders.
	 * @param array  $range      { @type int $start, @type int $end }
	 * @param array  $filters    { @type string $status 'publish' scopes products to the readable catalog. }
	 *
	 * @return array<int, array{id: int, digest: string, object_type: string}>
	 */
	public function bucket_listing( string $collection, array $range, array $filters = array() ): array {
		global $wpdb;
		$range_start = max( 0, (int) ( $range['start'] ?? 0 ) );
		$range_end   = max( 0, (int) ( $range['end'] ?? 0 ) );

		$servable_join   = '';
		$servable_filter = '';
		$servable_args   = array();
		if ( 'customers' === $collection ) {
			$inner_sql = $this->customer_digest_select_sql( 'u.ID >= %d AND u.ID < %d' );
		} elseif ( 'orders' === $collection ) {
			// Orders bucket over their own id-space (HPOS o.id / CPT p.ID) via the {id} placeholder.
			$inner_sql = $this->order_digest_select_sql( '{id} >= %d AND {id} < %d' );
		} else {
			$inner_sql = $this->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' );
			if ( 'publish' === ( $filters['status'] ?? '' ) ) {
				$servable_join = " INNER JOIN {$wpdb->posts} catalog_post ON catalog_post.ID = cur.id"
					. " LEFT JOIN {$wpdb->posts} parent_product ON parent_product.ID = catalog_post.post_parent"
					. " AND catalog_post.post_type = 'product_variation'";
			}
			$servable        = $this->product_servable_predicate_sql( 'cur.id', 'publish' === ( $filters['status'] ?? '' ) );
			$servable_filter = '' === $servable['sql'] ? '' : ' WHERE ' . $servable['sql'];
			$servable_args   = $servable['args'];
		}

		// Same-formula invariant + GROUP_CONCAT stability, exactly as the scan's current side.
		$this->raise_group_concat_max_len();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cur.id AS id, cur.crc AS digest, cur.object_type AS object_type FROM ('
				. $inner_sql
				. ') cur' . $servable_join . $servable_filter . ' ORDER BY cur.id ASC',
				$range_start,
				$range_end,
				...$servable_args
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id' => (int) $row['id'],
					// Unsigned 64-bit (ADR 0014 M1): keep as a string — (int)/JS Number lose precision above 2^53.
					'digest' => (string) $row['digest'],
					'object_type' => (string) $row['object_type'],
				);
			},
			\is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Narrow product-space ids to the readable catalog: published products, and variations whose parent
	 * product is published. The SAME rule {@see bucket_listing} applies with `status => publish`, so the
	 * prime-pass digest read and the reconcile listing can never disagree about what "publish" means.
	 *
	 * @param int[] $ids Requested product-space ids.
	 *
	 * @return int[] The subset that is readable, in the caller's order.
	 */
	public function published_product_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_map( 'intval', $ids ) );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders  = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		$published_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p"
				. " LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent AND p.post_type = 'product_variation'"
				. ' WHERE p.ID IN (' . $placeholders . ')'
				. ' AND ' . $this->published_product_predicate_sql( 'p', 'parent' ),
				...$ids
			)
		);

		return array_values( array_intersect( $ids, array_map( 'intval', (array) $published_ids ) ) );
	}

	/**
	 * True when the product space holds live rows but carries NO stored digests at all — the
	 * "stored side was never backfilled (or was wiped)" signal the scan answers with a guarded rebuild
	 * instead of reporting the whole catalog as drift.
	 */
	public function needs_product_rebuild(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			'SELECT EXISTS (SELECT 1 FROM ' . $wpdb->posts
			. ' WHERE post_type IN ' . self::PRODUCT_POST_TYPES_SQL
			. ' AND post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL . ' LIMIT 1)'
			. ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table_name()
			. ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL . ' LIMIT 1)'
		);
	}

	/**
	 * The readable-catalog predicate over a post alias and its parent alias — a published product,
	 * or a variation whose parent product is published. One home for the rule.
	 */
	private function published_product_predicate_sql( string $post_alias, string $parent_alias ): string {
		return "(({$post_alias}.post_type = 'product' AND {$post_alias}.post_status = 'publish')"
			. " OR ({$post_alias}.post_type = 'product_variation' AND {$parent_alias}.post_type = 'product'"
			. " AND {$parent_alias}.post_status = 'publish'))";
	}

	private function product_servable_predicate_sql( string $id_expr, bool $publish ): array {
		$predicates = $publish ? array( $this->published_product_predicate_sql( 'catalog_post', 'parent_product' ) ) : array();
		$hidden     = $this->pos_hidden_product_ids();
		if ( array() !== $hidden ) {
			$predicates[] = $id_expr . ' NOT IN (' . implode( ',', array_fill( 0, \count( $hidden ), '%d' ) ) . ')';
		}
		return array(
			'sql' => implode( ' AND ', $predicates ),
			'args' => $hidden,
		);
	}

	/**
	 * Product-space ids hidden from the POS (`online_only`), products and variations unioned — they share
	 * the wp_posts id-space. Read through the {@see Pos_Visibility} contract, never from the option.
	 *
	 * @return int[]
	 */
	private function pos_hidden_product_ids(): array {
		return array_values(
			array_unique(
				array_map(
					'intval',
					array_merge(
						$this->visibility->online_only_product_ids(),
						$this->visibility->online_only_variation_ids()
					)
				)
			)
		);
	}

	/** True when orders use HPOS (WooCommerce's own tables); false → legacy CPT (wp_posts). */
	private function orders_are_hpos(): bool {
		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );
		}
		return false; // no WC / older WC → CPT
	}
}
