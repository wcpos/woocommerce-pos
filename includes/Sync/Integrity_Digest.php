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
 */
final class Integrity_Digest {

	/**
	 * Wall-clock ms spent inside the digest write hooks during the CURRENT
	 * request. Read (and reset) by the product-edit fixture for the
	 * hook-overhead bench's per-component breakdown. Two microtime() calls
	 * per hook fire — negligible against the INSERT…SELECT it wraps.
	 */
	public static float $request_write_ms = 0.0;

	/**
	 * Postmeta keys covered by the digest. Must include every key the
	 * sql-bypass fixture mutates (_price and _regular_price today — see
	 * class-fixtures-controller.php sql_bypass()) plus the keys a
	 * hook-bypassing import/inventory tool plausibly touches.
	 */
	public const DIGESTED_META_KEYS = array( '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status' );

	/**
	 * Customer usermeta folded into the digest (ADR 0015, Leg-3 phase 7). Kept small + stable —
	 * identity/existence fields the POS keys on, NOT every usermeta row (a churny meta bloats the digest
	 * with irrelevant drift). The customer's core columns (email, display name, registered) come from
	 * wp_users directly; these four are the identifying usermeta.
	 */
	public const CUSTOMER_DIGESTED_META_KEYS = array( 'first_name', 'last_name', 'billing_email', 'billing_phone' );

	/**
	 * Order postmeta folded into the digest under the CPT (legacy) storage path (ADR 0015, Leg-3 phase 7).
	 * Under HPOS these live as wc_orders COLUMNS (total_amount, customer_id) so no meta join is needed;
	 * this allowlist only applies to the wp_posts fallback. Kept minimal — existence/identity signal.
	 */
	public const ORDER_DIGESTED_META_KEYS = array( '_order_total', '_customer_user' );

	private const PRODUCT_POST_TYPES_SQL = "('product','product_variation')";
	private const EXCLUDED_POST_STATUSES_SQL = "('trash','auto-draft')";
	public const OBJECT_TYPES_SQL = "('product','variation')";

	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Health::STORED_DIGEST_TABLE;
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

		// Leg-3 phase 7 (ADR 0015): customer digest maintenance. record_customer_saved is role-aware AND
		// idempotent (INSERT…SELECT is a no-op for a non-customer; the upsert/delete carry no per-fire
		// state), so hooking several overlapping create/update/role events is safe — no dedup needed,
		// unlike the change-log. set_user_role covers a customer↔other role flip; delete_user the removal.
		add_action( 'user_register', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_new_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'woocommerce_update_customer', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		// add_role()/remove_role() fire ONLY add_user_role/remove_user_role (no
		// set_user_role, no profile_update) — the role-aware handler is idempotent,
		// so registering it on both keeps multi-role transitions digested.
		add_action( 'add_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'remove_user_role', array( $this, 'record_customer_saved' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'record_customer_deleted' ), 10, 1 );

		// Leg-3 phase 7 (ADR 0015): order digest maintenance. Storage-agnostic WC order hooks (fire under
		// HPOS AND CPT), matching the sync-index's order hooks. upsert/delete are idempotent (no dedup).
		add_action( 'woocommerce_new_order', array( $this, 'record_order_saved' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'record_order_saved' ), 10, 1 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'record_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'record_order_deleted' ), 10, 1 );
	}

	/**
	 * Canonical per-row digest SELECT, shared verbatim by the hook upsert,
	 * the scan's current-side aggregate, the drill-down and the rebuild —
	 * the stored-vs-current comparison is only sound when every consumer
	 * computes the digest with the byte-identical expression.
	 *
	 * 64-bit digest — CONV(SUBSTRING(MD5(CONCAT_WS('|', ...)),1,16),16,10) — over the wp_posts content columns plus
	 * the DIGESTED_META_KEYS rows (key=value pairs ordered by meta_key then
	 * meta_id, so duplicate meta rows digest deterministically). Every
	 * nullable operand is COALESCE'd because CONCAT_WS silently SKIPS NULL
	 * arguments — ('a', NULL, 'b') would collide with ('a', 'b', '') —
	 * while COALESCE keeps every position present and deterministic.
	 *
	 * $where_sql may reference alias p and contain placeholders; callers
	 * run the final statement through $wpdb->prepare.
	 */
	/**
	 * Raise the session `group_concat_max_len` before ANY query built on {@see row_digest_select_sql}.
	 * That expression GROUP_CONCATs the digested meta; MySQL's default (1024 bytes) SILENTLY TRUNCATES
	 * for a row with many/large meta. And because the four consumers (hook upsert, rebuild, scan,
	 * drill-down) MUST produce byte-identical digests (the docblock invariant below), a truncation on
	 * one path but not another is a PERMANENT false-drift bug — the bucket never converges. So raise it
	 * identically everywhere the expression runs. (The sibling range-checksum path already does this at
	 * class-changes-controller.php; the digest path historically did NOT — this closes that gap.)
	 */
	public function raise_group_concat_max_len(): void {
		global $wpdb;
		$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' );
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
	 * `woocommerce_pos_sync_proxy_response` filter (orders) — the order analogue of {@see stamp_proxy_customer_digests}
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

	public function row_digest_select_sql( string $where_sql = '' ): string {
		global $wpdb;
		$meta_keys_sql = "('" . implode( "','", self::DIGESTED_META_KEYS ) . "')";

		return 'SELECT p.ID AS id,'
			. " CASE WHEN p.post_type = 'product_variation' THEN 'variation' ELSE 'product' END AS object_type,"
			. " CONV(SUBSTRING(MD5(CONCAT_WS('|',"
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
			. ')),1,16),16,10) AS crc'
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
	 * identity columns + the allowlisted usermeta. Filtered to CUSTOMER-ROLE users (the serialized
	 * `{prefix}capabilities` meta contains `"customer"`, matching {@see Change_Log::is_customer}'s
	 * `in_array('customer', roles)`), so admin/editor user edits never enter the customer digest.
	 * `$where_sql` narrows to a single user for the hook upsert (`u.ID = %d`); empty = all customers.
	 */
	public function customer_digest_select_sql( string $where_sql = '' ): string {
		global $wpdb;
		$meta_keys_sql = "('" . implode( "','", self::CUSTOMER_DIGESTED_META_KEYS ) . "')";
		$capabilities_key = $wpdb->prefix . 'capabilities';

		return 'SELECT u.ID AS id,'
			. " 'customer' AS object_type,"
			. " CONV(SUBSTRING(MD5(CONCAT_WS('|',"
			. ' u.ID,'
			. " COALESCE(u.user_email,''),"
			. " COALESCE(u.display_name,''),"
			. " COALESCE(u.user_registered,''),"
			. " COALESCE(GROUP_CONCAT(CONCAT(um.meta_key,'=',COALESCE(um.meta_value,'')) ORDER BY um.meta_key ASC, um.umeta_id ASC SEPARATOR '|'),'')"
			// 64-bit digest (ADR 0014 M1): top 16 hex of MD5 → BIGINT UNSIGNED, folds under BIT_XOR.
			. ')),1,16),16,10) AS crc'
			. " FROM {$wpdb->users} u"
			// Customer-role filter via INSTR (a substring match on the serialized capabilities meta),
			// NOT LIKE '%"customer"%' — a literal `%` would be mangled by $wpdb->prepare (every consumer
			// of this SQL runs it through prepare for the id placeholder). INSTR is wildcard-free.
			. " INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID"
			. " AND cap.meta_key = '{$capabilities_key}' AND INSTR(cap.meta_value, '\"customer\"') > 0"
			. " LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key IN {$meta_keys_sql}"
			. ( '' === $where_sql ? '' : ' WHERE ' . $where_sql )
			. ' GROUP BY u.ID';
	}

	/** True when orders use HPOS (WooCommerce's own tables); false → legacy CPT (wp_posts). */
	private function orders_are_hpos(): bool {
		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );
		}
		return false; // no WC / older WC → CPT
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
				. " CONV(SUBSTRING(MD5(CONCAT_WS('|',"
				. ' o.id,'
				. " COALESCE(o.status,''),"
				. " COALESCE(o.type,''),"
				. " COALESCE(o.total_amount,''),"
				. ' COALESCE(o.customer_id,0),'
				. " COALESCE(o.date_updated_gmt,''))),1,16),16,10) AS crc"
				. " FROM {$orders_table} o"
				. " WHERE o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft')"
				. $condition;
		}

		$meta_keys_sql = "('" . implode( "','", self::ORDER_DIGESTED_META_KEYS ) . "')";
		return 'SELECT p.ID AS id,'
			. " 'order' AS object_type,"
			. " CONV(SUBSTRING(MD5(CONCAT_WS('|',"
			. ' p.ID,'
			. " COALESCE(p.post_status,''),"
			. " COALESCE(p.post_modified_gmt,''),"
			. " COALESCE(GROUP_CONCAT(CONCAT(pm.meta_key,'=',COALESCE(pm.meta_value,'')) ORDER BY pm.meta_key ASC, pm.meta_id ASC SEPARATOR '|'),''))),1,16),16,10) AS crc"
			. " FROM {$wpdb->posts} p"
			. " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN {$meta_keys_sql}"
			. " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN " . self::EXCLUDED_POST_STATUSES_SQL
			. $condition
			. ' GROUP BY p.ID';
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

	/** Live-row predicate reused by the drill-down's deleted branch and the rebuild's orphan prune. */
	public function live_row_exists_sql( string $id_expr ): string {
		global $wpdb;

		return "EXISTS (SELECT 1 FROM {$wpdb->posts} lp WHERE lp.ID = {$id_expr}"
			. ' AND lp.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
			. ' AND lp.post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL . ')';
	}

	/** Customer analogue of {@see live_row_exists_sql}: the id still names a customer-role user (ADR 0015). */
	public function customer_live_row_exists_sql( string $id_expr ): string {
		global $wpdb;
		$capabilities_key = $wpdb->prefix . 'capabilities';

		return "EXISTS (SELECT 1 FROM {$wpdb->users} lu"
			. " INNER JOIN {$wpdb->usermeta} lc ON lc.user_id = lu.ID"
			. " AND lc.meta_key = '{$capabilities_key}' AND INSTR(lc.meta_value, '\"customer\"') > 0"
			. " WHERE lu.ID = {$id_expr})";
	}

	/**
	 * Customer digest maintenance (ADR 0015, Leg-3 phase 7) — role-aware, mirroring
	 * {@see Change_Log::is_customer}: a customer-role user is (re)digested; a user who is
	 * NOT (or no longer) a customer has any stale digest removed. A role removal is a customer "delete"
	 * for Leg 3, so the same handler covers save AND un-customer without a separate role hook.
	 */
	public function record_customer_saved( int $user_id ): void {
		$this->observe(
			function () use ( $user_id ): void {
				if ( $this->is_customer( $user_id ) ) {
					$this->upsert_customer_digest( $user_id );
				} else {
					$this->delete_customer_digest( $user_id );
				}
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

	/** Role check identical to the change-log's — only customer-role users carry a digest. */
	private function is_customer( int $user_id ): bool {
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		return $user && in_array( 'customer', (array) $user->roles, true );
	}

	/** Order analogue of {@see live_row_exists_sql} — HPOS/CPT-aware (ADR 0015, Leg-3 phase 7). */
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
		$wpdb->delete(
			$this->table_name(),
			array(
				'object_type' => 'order',
				'object_id' => $order_id,
			),
			array( '%s', '%d' )
		);
	}

	/** Order analogue of {@see upsert_customer_digest}: compute + store one order's digest (HPOS or CPT). */
	public function upsert_order_digest( int $order_id ): void {
		global $wpdb;
		$started = microtime( true );
		$this->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->order_digest_select_sql( '{id} = %d' ) . ') t'
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
		$this->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->row_digest_select_sql( 'p.ID = %d' ) . ') t'
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
	 * Customer analogue of {@see upsert_digest} (ADR 0015, Leg-3 phase 7): compute + store one customer's
	 * digest in a single INSERT…SELECT. No-op for a user outside the customer-role predicate (the delete
	 * hook owns removals), so hooking it to every user save is free.
	 */
	public function upsert_customer_digest( int $user_id ): void {
		global $wpdb;
		$started = microtime( true );
		$this->raise_group_concat_max_len();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
				. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
				. ' FROM (' . $this->customer_digest_select_sql( 'u.ID = %d' ) . ') t'
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
	 */
	public function rebuild(): array {
		global $wpdb;
		$this->raise_group_concat_max_len();
		$started = microtime( true );

		$orphans_deleted = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. ' WHERE object_type IN ' . self::OBJECT_TYPES_SQL
			. ' AND NOT ' . $this->live_row_exists_sql( 'object_id' )
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
			. ' FROM (' . $this->row_digest_select_sql() . ') t'
			. ' ON DUPLICATE KEY UPDATE'
			. ' updated_gmt = IF(digest <=> VALUES(digest), updated_gmt, VALUES(updated_gmt)),'
			. ' digest = VALUES(digest)'
		);
		if ( false === $writes ) {
			throw new RuntimeException( 'rebuild stored digests failed: ' . $wpdb->last_error );
		}

		// Leg-3 phase 7 (ADR 0015): customers share the digest table via their own 'customer' rows —
		// the same prune-orphans + INSERT…SELECT pass, over the customer predicate + id-space. A stored
		// customer whose user vanished or lost the customer role is an orphan (a role removal never fires
		// before_delete_post, so the rebuild is the backstop that reconciles it).
		$customer_orphans = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. " WHERE object_type = 'customer'"
			. ' AND NOT ' . $this->customer_live_row_exists_sql( 'object_id' )
		);
		if ( false === $customer_orphans ) {
			throw new RuntimeException( 'prune orphan customer digests failed: ' . $wpdb->last_error );
		}
		$customer_writes = $wpdb->query(
			'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
			. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
			. ' FROM (' . $this->customer_digest_select_sql() . ') t'
			. ' ON DUPLICATE KEY UPDATE'
			. ' updated_gmt = IF(digest <=> VALUES(digest), updated_gmt, VALUES(updated_gmt)),'
			. ' digest = VALUES(digest)'
		);
		if ( false === $customer_writes ) {
			throw new RuntimeException( 'rebuild customer digests failed: ' . $wpdb->last_error );
		}

		// Leg-3 phase 7 (ADR 0015): orders share the digest table via their own 'order' rows (HPOS or CPT).
		// Same prune-orphans + INSERT…SELECT pass; order_digest_select_sql() emits the storage-correct SQL
		// (the CPT path GROUP BYs, the HPOS path does not — both valid as an INSERT…SELECT source).
		$order_orphans = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. " WHERE object_type = 'order'"
			. ' AND NOT ' . $this->order_live_row_exists_sql( 'object_id' )
		);
		if ( false === $order_orphans ) {
			throw new RuntimeException( 'prune orphan order digests failed: ' . $wpdb->last_error );
		}
		$order_writes = $wpdb->query(
			'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, digest, updated_gmt)'
			. ' SELECT t.object_type, t.id, t.crc, UTC_TIMESTAMP()'
			. ' FROM (' . $this->order_digest_select_sql() . ') t'
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
