<?php
/**
 * The active order store's tables, for direct reads.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Every "HPOS or posts?" decision, made in one place.
 *
 * WooCommerce keeps orders in `wc_orders` + `wc_orders_meta` under HPOS and as `shop_order`
 * posts + `postmeta` otherwise; an install uses one at a time. `wc_get_orders()` with a
 * `meta_query` is not supported on the posts datastore (it fires `doing_it_wrong` and returns
 * unfiltered results), so indexed meta lookups read the meta table directly, through here.
 *
 * Reads only: writes go through the order object. Identifiers come from this class, never from a
 * caller; every value goes through `$wpdb->prepare()`.
 */
final class Order_Meta_Store {
	/** Column identifiers per storage; schema() adds the table names. */
	private const SCHEMA = array(
		'hpos'  => array(
			'id'       => 'id',
			'type'     => 'type',
			'status'   => 'status',
			'created'  => 'date_created_gmt',
			'modified' => 'date_updated_gmt',
			'owner'    => 'order_id',
			'meta_id'  => 'id',
		),
		'posts' => array(
			'id'       => 'ID',
			'type'     => 'post_type',
			'status'   => 'post_status',
			'created'  => 'post_date_gmt',
			'modified' => 'post_modified_gmt',
			'owner'    => 'post_id',
			'meta_id'  => 'meta_id',
		),
	);

	/** Whether orders live in the HPOS tables. */
	public static function hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The active order store's identifiers, for a join order_ids() does not cover: `orders` and `meta`
	 * (full table names), and the columns `id`, `type`, `status`, `created`, `modified` (GMT) of the
	 * orders table and `owner`, `meta_id` of the meta table.
	 *
	 * @return array<string,string>
	 */
	public static function schema(): array {
		global $wpdb;
		$hpos = self::hpos();
		return array(
			'orders' => $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts,
			'meta'   => $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta,
		) + self::SCHEMA[ $hpos ? 'hpos' : 'posts' ];
	}

	/**
	 * The ids of the records holding $key, filtered as $match says:
	 *
	 * - `value` (string): the value equals it; `value_ci`: the case-folded value equals it, case-folded;
	 *   `contains`: the value contains it, escaped for LIKE.
	 * - `exact_key` (bool): the key matches byte-exactly too; `meta_key` compares under a `_ci` PAD
	 *   SPACE collation, so `_KEY` and `_key ` match `_key` otherwise.
	 * - `type` (string): only records of this order type; `exclude` (string[]): nor in these statuses
	 *   (needs `type`).
	 * - `order`: `modified` (least recently modified first; needs `type`) or `newest` (highest id first);
	 *   unordered otherwise.
	 * - `limit` (int): at most this many; below one, none.
	 *
	 * @param string $key   Meta key.
	 * @param array  $match Filters, above.
	 * @return int[]|null Ids, or null when the read failed.
	 * @throws \InvalidArgumentException When a filter needs `type` and has none.
	 */
	public static function order_ids( string $key, array $match = array() ): ?array {
		global $wpdb;
		if ( isset( $match['limit'] ) && $match['limit'] < 1 ) {
			return array();
		}
		$type = $match['type'] ?? null;
		if ( null === $type && ( isset( $match['exclude'] ) || 'modified' === ( $match['order'] ?? null ) ) ) {
			throw new \InvalidArgumentException( 'Order meta lookup: a status filter or modified ordering needs a type.' );
		}
		$s = self::schema();

		$meta = array( 'm.meta_key = %s' );
		$args = array( $key );
		if ( ! empty( $match['exact_key'] ) ) {
			$meta[] = 'CAST(m.meta_key AS BINARY) = %s';
			$args[] = $key;
		}
		foreach ( array(
			'value'    => 'm.meta_value = %s',
			'value_ci' => 'LOWER(m.meta_value) = %s',
			'contains' => 'm.meta_value LIKE %s',
		) as $option => $clause ) {
			if ( isset( $match[ $option ] ) ) {
				$meta[] = $clause;
				$value  = (string) $match[ $option ];
				$args[] = 'value_ci' === $option ? strtolower( $value ) : ( 'contains' === $option ? '%' . $wpdb->esc_like( $value ) . '%' : $value );
			}
		}
		$meta = implode( ' AND ', $meta );

		$excluded = array_values( array_map( 'strval', (array) ( $match['exclude'] ?? array() ) ) );
		$statuses = array() === $excluded ? '' : " AND o.{$s['status']} NOT IN (" . implode( ', ', array_fill( 0, \count( $excluded ), '%s' ) ) . ')';
		$limit    = isset( $match['limit'] ) ? ' LIMIT %d' : '';
		$tail     = isset( $match['limit'] ) ? array( (int) $match['limit'] ) : array();

		if ( 'modified' === ( $match['order'] ?? null ) ) {
			// Driven from the orders table: DISTINCT cannot sort on a column it does not select.
			$sql  = "SELECT o.{$s['id']} FROM {$s['orders']} o WHERE o.{$s['type']} = %s{$statuses}"
				. " AND EXISTS ( SELECT 1 FROM {$s['meta']} m WHERE m.{$s['owner']} = o.{$s['id']} AND {$meta} )"
				. " ORDER BY o.{$s['modified']} ASC{$limit}";
			$args = array_merge( array( $type ), $excluded, $args, $tail );
		} else {
			$join = null === $type ? '' : " JOIN {$s['orders']} o ON o.{$s['id']} = m.{$s['owner']} AND o.{$s['type']} = %s";
			$sort = 'newest' === ( $match['order'] ?? null ) ? " ORDER BY m.{$s['owner']} DESC" : '';
			$sql  = "SELECT DISTINCT m.{$s['owner']} FROM {$s['meta']} m{$join} WHERE {$meta}{$statuses}{$sort}{$limit}";
			$args = array_merge( null === $type ? array() : array( $type ), $args, $excluded, $tail );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are this class's own; every value is a placeholder. An index lookup, never cached.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		return array_map( 'intval', (array) $ids );
	}
}
