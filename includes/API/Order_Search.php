<?php
/**
 * Shared order search SQL filters.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

/** Builds the POS order search for both WooCommerce storage engines. */
final class Order_Search {
	/**
	 * Split a search string into at most ten whitespace-separated terms.
	 *
	 * Unicode-aware: a pasted non-breaking space (U+00A0) separates terms like a
	 * space does, and an all-whitespace string yields no terms. Malformed UTF-8
	 * makes preg_split() return false, which also yields no terms.
	 *
	 * @param string $search Search text.
	 * @return string[]
	 */
	public static function terms( string $search ): array {
		return array_slice( (array) preg_split( '/[\s\p{Z}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY ), 0, 10 );
	}
	/**
	 * Build an HPOS where fragment with AND-across-terms semantics.
	 *
	 * @param string $search Search text.
	 * @param mixed  $query  Orders table query.
	 * @return string
	 */
	public static function hpos_where( string $search, $query ): string {
		global $wpdb;
		$conditions = array();
		$orders     = $query->get_table_name( 'orders' );
		$addresses  = $query->get_table_name( 'addresses' );
		foreach ( self::terms( $search ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "`{$orders}`.id = %d OR " : '';
			$args = array_fill( 0, 6, $like );
			if ( '' !== $id ) {
				array_unshift( $args, (int) $term );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WooCommerce.
			$conditions[] = $wpdb->prepare(
				"( {$id}`{$orders}`.billing_email LIKE %s OR `{$orders}`.id IN (
					SELECT order_id FROM `{$addresses}` WHERE address_type = 'billing'
					AND ( first_name LIKE %s OR last_name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s )
				) )",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return implode( ' AND ', $conditions );
	}
	/**
	 * Build a legacy posts where fragment with AND-across-terms semantics.
	 *
	 * @param string $search Search text.
	 * @return string
	 */
	public static function posts_where( string $search ): string {
		global $wpdb;
		$conditions = array();
		foreach ( self::terms( $search ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "{$wpdb->posts}.ID = %d OR " : '';
			$args = '' === $id ? array( $like ) : array( (int) $term, $like );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb.
			$conditions[] = $wpdb->prepare(
				"( {$id}EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS wcpos_order_search_meta WHERE wcpos_order_search_meta.post_id = {$wpdb->posts}.ID AND wcpos_order_search_meta.meta_key IN ( '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_email', '_billing_phone' ) AND wcpos_order_search_meta.meta_value LIKE %s
				) )",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return implode( ' AND ', $conditions );
	}
}
