<?php
/**
 * Legacy WCPOS search API; retained for Pro for one release.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Product_Search as Search_Rule;
use WP_Query;

/**
 * Legacy search entry points.
 *
 * @deprecated Search is owned by Sync\Collection_Rules.
 */
final class Product_Search {
	/**
	 * Build product search SQL.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string   $search   Search SQL.
	 * @param WP_Query $wp_query Query instance.
	 * @return string
	 */
	public static function posts_search( string $search, WP_Query $wp_query ): string {
		return Search_Rule::posts_search( $search, $wp_query->query_vars, Collection_Rules::rules( 'products' )['search'] );
	}
	/**
	 * Join product search metadata.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string   $join  JOIN SQL.
	 * @param WP_Query $query Query instance.
	 * @return string
	 */
	public static function posts_join( string $join, WP_Query $query ): string {
		return Search_Rule::posts_join( $join, $query->query_vars );
	}
	/**
	 * Group product search results.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string   $groupby GROUP BY SQL.
	 * @param WP_Query $query   Query instance.
	 * @return string
	 */
	public static function posts_groupby( string $groupby, WP_Query $query ): string {
		return Search_Rule::posts_groupby( $groupby, $query->query_vars );
	}
	/**
	 * Rank exact product matches.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string   $orderby ORDER BY SQL.
	 * @param WP_Query $query   Query instance.
	 * @return string
	 */
	public static function posts_orderby( string $orderby, WP_Query $query ): string {
		return Search_Rule::posts_orderby( $orderby, $query->query_vars, Collection_Rules::rules( 'products' )['search'] );
	}
}
