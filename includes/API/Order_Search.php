<?php
/**
 * Legacy WCPOS search API; retained for Pro for one release.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Order_Search as Search_Rule;

/**
 * Legacy search entry points.
 *
 * @deprecated Search is owned by Sync\Collection_Rules.
 */
final class Order_Search {
	/**
	 * Split order search terms.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string $search Search text.
	 * @return string[]
	 */
	public static function terms( string $search ): array {
		return Search_Rule::terms( $search, Collection_Rules::rules( 'orders' )['search'] );
	}
	/**
	 * Build HPOS order search SQL.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string $search Search text.
	 * @param object $query  Orders table query.
	 * @return string
	 */
	public static function hpos_where( string $search, $query ): string {
		return Search_Rule::hpos_where(
			$search,
			array(
				'orders'    => $query->get_table_name( 'orders' ),
				'addresses' => $query->get_table_name( 'addresses' ),
			),
			Collection_Rules::rules( 'orders' )['search']
		);
	}
	/**
	 * Build legacy order search SQL.
	 *
	 * @deprecated Use the Collection Rules plan.
	 *
	 * @param string $search Search text.
	 * @return string
	 */
	public static function posts_where( string $search ): string {
		return Search_Rule::posts_where( $search, Collection_Rules::rules( 'orders' )['search'] );
	}
}
