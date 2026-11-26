<?php
/**
 * The few order facts the orders change stream carries per live row.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use WCPOS\WooCommercePOS\Services\Order_Meta_Store;

/**
 * Reads status, store, user, origin and creation date for a page of order ids.
 *
 * Direct reads of the active order store ({@see Order_Meta_Store}), two queries whatever the
 * number of ids: no order object is loaded, so a page of changes costs the same as one.
 */
final class Order_Change_Facts {
	/** Stored statuses no till is told about: drafts and trash. */
	private const UNSERVED_STATUSES = array( 'trash', 'auto-draft', 'draft', 'wc-checkout-draft', 'checkout-draft' );

	/** Order meta served raw, in this order. */
	private const META_KEYS = array( '_pos_store', '_pos_user' );

	/**
	 * The facts of each listed order, keyed by order id.
	 *
	 * Only `shop_order` rows outside the draft and trash statuses get an entry; a missing order,
	 * a refund or another order type has none.
	 *
	 * @param int[] $ids Order ids.
	 * @return array<int, array{status: string, _pos_store: ?string, _pos_user: ?string, created_via: string, date_created_gmt: ?string}>|null
	 *         The facts, or null when a database read failed.
	 */
	public static function for_ids( array $ids ): ?array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$s    = Order_Meta_Store::schema();
		$hpos = Order_Meta_Store::hpos();

		$in       = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		$statuses = implode( ',', array_fill( 0, \count( self::UNSERVED_STATUSES ), '%s' ) );
		// created_via is a column of the operational-data table on HPOS.
		$via  = $hpos ? ', op.created_via' : '';
		// @phpstan-ignore class.notFound (WooCommerce internals are not in the phpstan stubs)
		$join = $hpos ? ' LEFT JOIN ' . OrdersTableDataStore::get_operational_data_table_name() . " op ON op.order_id = o.{$s['id']}" : '';
		$sql = "SELECT o.{$s['id']} AS id, o.{$s['status']} AS status, o.{$s['created']} AS created{$via}"
			. " FROM {$s['orders']} o{$join} WHERE o.{$s['id']} IN ({$in}) AND o.{$s['type']} = %s AND o.{$s['status']} NOT IN ({$statuses})";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers from Order_Meta_Store; every value is a placeholder. Read at serve time, never cached.
		$orders = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, array( 'shop_order' ), self::UNSERVED_STATUSES ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$facts = array();
		foreach ( (array) $orders as $order ) {
			$status  = (string) $order['status'];
			$created = (string) $order['created'];
			$facts[ (int) $order['id'] ] = array(
				'status'           => 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status,
				'_pos_store'       => null,
				'_pos_user'        => null,
				'created_via'      => (string) ( $order['created_via'] ?? '' ),
				'date_created_gmt' => '' === $created || '0000-00-00 00:00:00' === $created ? null : mysql_to_rfc3339( $created ),
			);
		}
		if ( array() === $facts ) {
			return $facts;
		}

		// On posts storage created_via is the `_created_via` meta, read with the served meta.
		$keys = $hpos ? self::META_KEYS : array_merge( self::META_KEYS, array( '_created_via' ) );
		$in   = implode( ',', array_fill( 0, \count( $facts ), '%d' ) );
		$sql  = "SELECT m.{$s['owner']} AS owner, m.meta_key, m.meta_value FROM {$s['meta']} m"
			. " WHERE m.{$s['owner']} IN ({$in}) AND m.meta_key IN (" . implode( ',', array_fill( 0, \count( $keys ), '%s' ) ) . ") ORDER BY m.{$s['meta_id']} ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers from Order_Meta_Store; every value is a placeholder. Read at serve time, never cached.
		$meta = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array_keys( $facts ), $keys ) ), ARRAY_A );
		// @phpstan-ignore notIdentical.alwaysFalse (each query resets last_error; phpstan keeps the first check's narrowing)
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$seen = array();
		foreach ( (array) $meta as $row ) {
			$id  = (int) $row['owner'];
			$key = (string) $row['meta_key'];
			// `meta_key` compares case-insensitively, so the key is matched exactly here;
			// rows come lowest meta id first and the first one wins.
			if ( ! \in_array( $key, $keys, true ) || isset( $seen[ $id ][ $key ] ) ) {
				continue;
			}
			$seen[ $id ][ $key ] = true;
			$facts[ $id ][ '_created_via' === $key ? 'created_via' : $key ] = (string) $row['meta_value'];
		}

		return $facts;
	}
}
