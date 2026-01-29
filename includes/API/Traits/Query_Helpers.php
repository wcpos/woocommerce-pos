<?php
/**
 * Query_Helpers.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\Traits;

use WCPOS\WooCommercePOS\Logger;

trait Query_Helpers {
	/**
	 * Combine two meta_query arrays.
	 *
	 * @param array $meta_query1 First meta query array.
	 * @param array $meta_query2 Second meta query array.
	 *
	 * @return array Combined meta query array.
	 */
	public function wcpos_combine_meta_queries( $meta_query1, $meta_query2 ) {
		// If either meta_query is empty, return the other.
		if ( empty( $meta_query1 ) ) {
			return $meta_query2;
		}
		if ( empty( $meta_query2 ) ) {
			return $meta_query1;
		}

		// Check if both meta_queries have 'AND' as their top-level relation.
		if ( isset( $meta_query1['relation'] ) && 'AND' === $meta_query1['relation'] &&
		 isset( $meta_query2['relation'] ) && 'AND' === $meta_query2['relation'] ) {
			// Remove the 'relation' element and combine the arrays.
			unset( $meta_query1['relation'], $meta_query2['relation'] );
			$combined = array_merge( $meta_query1, $meta_query2 );
			array_unshift( $combined, array( 'relation' => 'AND' ) );
			return $combined;
		}

		// If both meta_queries are not empty and do not both have 'AND', combine them with 'AND' relation.
		return array(
			'relation' => 'AND',
			$meta_query1,
			$meta_query2,
		);
	}
}
