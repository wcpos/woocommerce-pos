<?php
/**
 * Shared product search SQL filters.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Barcode_Field;
use WP_Query;

/**
 * Keeps v1 and v2 product search fields identical.
 */
final class Product_Search {
	/**
	 * Search product titles, SKUs, and the configured barcode field.
	 *
	 * @param string   $search   Search SQL.
	 * @param WP_Query $wp_query Query instance.
	 * @return string
	 */
	public static function posts_search( string $search, WP_Query $wp_query ): string {
		global $wpdb;
		if ( empty( $search ) ) {
			return $search;
		}
		$q                 = $wp_query->query_vars;
		$n                 = ! empty( $q['exact'] ) ? '' : '%';
		$meta_fields       = Barcode_Field::search_keys();
		$search_conditions = array();
		foreach ( (array) $q['search_terms'] as $term ) {
			$term                = $n . $wpdb->esc_like( $term ) . $n;
			$search_conditions[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s)", $term ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
			foreach ( $meta_fields as $field ) {
				$search_conditions[] = $wpdb->prepare( '(pm1.meta_value LIKE %s AND pm1.meta_key = %s)', $term, $field );
			}
		}

		if ( ! empty( $search_conditions ) ) {
			$search = ' AND (' . implode( ' OR ', $search_conditions ) . ') ';
			if ( ! is_user_logged_in() ) {
				$search .= " AND ($wpdb->posts.post_password = '') ";
			}
		}
		return $search;
	}

	/**
	 * Join product meta while searching.
	 *
	 * @param string   $join  JOIN SQL.
	 * @param WP_Query $query Query instance.
	 * @return string
	 */
	public static function posts_join( string $join, WP_Query $query ): string {
		global $wpdb;
		if ( self::is_searching( $query ) && false === strpos( $join, 'pm1' ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} pm1 ON {$wpdb->posts}.ID = pm1.post_id ";
		}
		return $join;
	}

	/**
	 * Group product search results after joining meta.
	 *
	 * @param string   $groupby GROUP BY SQL.
	 * @param WP_Query $query   Query instance.
	 * @return string
	 */
	public static function posts_groupby( string $groupby, WP_Query $query ): string {
		global $wpdb;
		if ( self::is_searching( $query ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}
		return $groupby;
	}

	/**
	 * Rank exact SKU or barcode matches ahead of substring matches.
	 *
	 * @param string   $orderby ORDER BY SQL.
	 * @param WP_Query $query   Query instance.
	 */
	public static function posts_orderby( string $orderby, WP_Query $query ): string {
		global $wpdb;
		if ( ! self::is_searching( $query ) ) {
			return $orderby;
		}
		$keys = Barcode_Field::search_keys();
		return $wpdb->prepare(
			'MIN(CASE WHEN pm1.meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') AND pm1.meta_value = %s THEN 0 ELSE 1 END) ASC',
			array_merge( $keys, array( trim( (string) $query->query_vars['s'] ) ) )
		) . ', ' . ( '' === trim( $orderby ) ? "{$wpdb->posts}.ID DESC" : $orderby );
	}

	/**
	 * Whether the query carries a search term. Not empty(): the literal term "0" is a search.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool
	 */
	private static function is_searching( WP_Query $query ): bool {
		return isset( $query->query_vars['s'] ) && '' !== (string) $query->query_vars['s'];
	}
}
