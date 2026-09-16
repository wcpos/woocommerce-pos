<?php
/**
 * Shared order search SQL filters.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Builds the POS order search for both WooCommerce storage engines.
 *
 * Malformed UTF-8 deliberately matches zero rows (1=0) on both storage dialects;
 * unlike the former search body, it never becomes an unconstrained order query.
 */
final class Order_Search {
	/**
	 * Split a search string into at most ten whitespace-separated terms.
	 *
	 * Unicode-aware: a pasted non-breaking space (U+00A0) separates terms like a
	 * space does, and an all-whitespace string yields no terms. Malformed UTF-8
	 * makes preg_split() return false, which also yields no terms.
	 *
	 * @param string $search Search text.
	 * @param array  $rule   Declared search carriers and cap.
	 * @return string[]
	 */
	public static function terms( string $search, array $rule ): array {
		return array_slice( Collection_Rules::search_terms( $search ), 0, $rule['term_cap'] );
	}
	/**
	 * Build an HPOS where fragment with AND-across-terms semantics.
	 *
	 * @param string $search Search text.
	 * @param array  $tables Orders and address table names.
	 * @param array  $rule   Declared search carriers and cap.
	 * @return string
	 */
	public static function hpos_where( string $search, array $tables, array $rule ): string {
		global $wpdb;
		$conditions = array();
		$orders     = $tables['orders'];
		$addresses  = $tables['addresses'];
		$fields = implode( ' LIKE %s OR ', $rule['hpos']['addresses'] ) . ' LIKE %s';
		foreach ( self::terms( $search, $rule ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "`{$orders}`.id = %d OR " : '';
			$args = array_fill( 0, 1 + count( $rule['hpos']['addresses'] ), $like );
			if ( '' !== $id ) {
				array_unshift( $args, (int) $term );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WooCommerce.
			$conditions[] = $wpdb->prepare(
				"( {$id}`{$orders}`.billing_email LIKE %s OR `{$orders}`.id IN (
					SELECT order_id FROM `{$addresses}` WHERE address_type = 'billing'
					AND ( {$fields} )
				) )",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false === preg_match( '//u', $search ) ? '1=0' : implode( ' AND ', $conditions );
	}
	/**
	 * Build a legacy posts where fragment with AND-across-terms semantics.
	 *
	 * @param string $search Search text.
	 * @param array  $rule   Declared search carriers and cap.
	 * @return string
	 */
	public static function posts_where( string $search, array $rule ): string {
		global $wpdb;
		$conditions = array();
		$keys = "'" . implode( "', '", $rule['posts']['meta'] ) . "'";
		foreach ( self::terms( $search, $rule ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "{$wpdb->posts}.ID = %d OR " : '';
			$args = '' === $id ? array( $like ) : array( (int) $term, $like );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb.
			$conditions[] = $wpdb->prepare(
				"( {$id}EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS wcpos_order_search_meta WHERE wcpos_order_search_meta.post_id = {$wpdb->posts}.ID AND wcpos_order_search_meta.meta_key IN ( {$keys} ) AND wcpos_order_search_meta.meta_value LIKE %s
				) )",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false === preg_match( '//u', $search ) ? '1=0' : implode( ' AND ', $conditions );
	}
}
