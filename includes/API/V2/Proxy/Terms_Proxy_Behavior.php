<?php
/**
 * Term catalog proxy behavior (categories, tags, brands).
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_Term;

/**
 * Pins the stable name-sort tiebreak during the forward.
 *
 * The POS client's term lanes default to name ascending (mono#1371) and walk
 * multi-page windows; see {@see Stable_Sort} for why ties need a secondary key.
 */
final class Terms_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Add POS-visible product counts, including descendants, without changing Woo's count.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return mixed
	 */
	public function post_process( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$single   = isset( $data['id'] );
		$rows     = $single ? array( $data ) : $data;
		$owners   = array();
		$products = array();
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'], $row['slug'] ) ) {
				continue;
			}
			$term = get_term( (int) $row['id'] );
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, array( 'product_cat', 'product_tag', 'product_brand' ), true ) ) {
				continue;
			}

			$products[ $key ] = array();
			$owners[ $term->term_taxonomy_id ][ $key ] = true;
			if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
				$children = get_term_children( $term->term_id, $term->taxonomy );
				if ( ! is_wp_error( $children ) ) {
					foreach ( $children as $child_id ) {
						$child = get_term( $child_id, $term->taxonomy );
						if ( $child instanceof WP_Term ) {
							$owners[ $child->term_taxonomy_id ][ $key ] = true;
						}
					}
				}
			}
		}
		if ( array() === $owners ) {
			return $data;
		}

		global $wpdb;
		$ids          = wp_parse_id_list( array_keys( $owners ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are interpolated; table names come from wpdb.
			"SELECT tr.term_taxonomy_id, tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id IN ($placeholders) AND p.post_type = 'product' AND p.post_status = 'publish'",
			$ids
		);
		$sql = ( new Pos_Visibility() )->apply_to_sql_where( $sql, 'p.ID', Pos_Visibility::CATALOG );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Prepared above; visibility appends its own prepared fragment. One uncached read per response.
		$relationships = $wpdb->get_results( $sql );
		foreach ( (array) $relationships as $relationship ) {
			foreach ( $owners[ $relationship->term_taxonomy_id ] as $key => $unused ) {
				// A product assigned to both a term and its descendant counts only once.
				$products[ $key ][ $relationship->object_id ] = true;
			}
		}
		foreach ( $products as $key => $ids ) {
			$rows[ $key ]['pos_count'] = count( $ids );
		}

		return $single ? $rows[0] : $rows;
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		$filter = static function ( $clauses ) {
			return Stable_Sort::with_term_id_tiebreak( (array) $clauses );
		};
		add_filter( 'terms_clauses', $filter );

		return array( array( 'terms_clauses', $filter, 10 ) );
	}
}
