<?php
/**
 * Shared product search SQL filters.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Keeps v1 and v2 product search fields identical.
 */
final class Product_Search {

	/**
	 * Search product titles, SKUs, and the configured barcode field.
	 *
	 * @param string $search   Search SQL.
	 * @param array  $q        Query variables.
	 * @param array  $rule     Declared search carriers and cap.
	 * @return string
	 */
	public static function posts_search( string $search, array $q, array $rule ): string {
		global $wpdb;
		$phrase = $q['wcpos_search_phrase'] ?? null;
		if ( empty( $search ) && null === $phrase ) {
			return $search;
		}
		$terms = null !== $phrase
			? Collection_Rules::search_terms( $phrase )
			: (array) $q['search_terms'];
		if ( null !== $phrase && array() === $terms ) {
			return ' AND 1=0 ';
		}
		// Like WP_Query::parse_search() and WooCommerce's search_products(), collapse over-long lists to the phrase.
		if ( null !== $phrase && $rule['term_cap'] < \count( $terms ) ) {
			$terms = array( $phrase );
		}
		$n                 = ! empty( $q['exact'] ) ? '' : '%';
		$meta_fields       = $rule['posts']['meta'];
		$meta_placeholders = implode( ', ', array_fill( 0, \count( $meta_fields ), '%s' ) );
		$search_conditions = array();
		foreach ( $terms as $term ) {
			$term                = $n . $wpdb->esc_like( $term ) . $n;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $meta_placeholders is a generated list of %s placeholders, and the keys themselves are passed to prepare() as arguments.
			$search_conditions[] = $wpdb->prepare(
				"( {$wpdb->posts}.post_title LIKE %s OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS wcpos_search_meta WHERE wcpos_search_meta.post_id = {$wpdb->posts}.ID AND wcpos_search_meta.meta_key IN ($meta_placeholders) AND wcpos_search_meta.meta_value LIKE %s
				) )",
				array_merge( array( $term ), $meta_fields, array( $term ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! empty( $search_conditions ) ) {
			$search = ' AND (' . implode( ' AND ', $search_conditions ) . ') ';
			if ( ! is_user_logged_in() ) {
				$search .= " AND ($wpdb->posts.post_password = '') ";
			}
		}
		return $search;
	}

	/**
	 * Join product meta while searching.
	 *
	 * @param string $join JOIN SQL.
	 * @param array  $q    Query variables.
	 * @return string
	 */
	public static function posts_join( string $join, array $q ): string {
		global $wpdb;
		if ( self::is_searching( $q ) && false === strpos( $join, 'pm1' ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} pm1 ON {$wpdb->posts}.ID = pm1.post_id ";
		}
		return $join;
	}

	/**
	 * Group product search results after joining meta.
	 *
	 * @param string $groupby GROUP BY SQL.
	 * @param array  $q       Query variables.
	 * @return string
	 */
	public static function posts_groupby( string $groupby, array $q ): string {
		global $wpdb;
		if ( self::is_searching( $q ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}
		return $groupby;
	}

	/**
	 * Rank exact SKU or barcode matches ahead of substring matches.
	 *
	 * @param string $orderby ORDER BY SQL.
	 * @param array  $q       Query variables.
	 * @param array  $rule    Declared search carriers and cap.
	 * @return string
	 */
	public static function posts_orderby( string $orderby, array $q, array $rule ): string {
		global $wpdb;
		if ( ! self::is_searching( $q ) ) {
			return $orderby;
		}
		$keys = $rule['posts']['meta'];
		return $wpdb->prepare(
			'MIN(CASE WHEN pm1.meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') AND pm1.meta_value = %s THEN 0 ELSE 1 END) ASC',
			array_merge( $keys, array( trim( (string) ( $q['wcpos_search_phrase'] ?? $q['s'] ) ) ) )
		) . ', ' . ( '' === trim( $orderby ) ? "{$wpdb->posts}.ID DESC" : $orderby );
	}

	/**
	 * Direct variations use literal terms with over-cap collapse and the existing EXISTS SQL.
	 *
	 * @param string $search Search SQL.
	 * @param array  $q      Query variables.
	 * @param array  $rule   Declared search carriers and cap.
	 * @return string
	 */
	public static function variation_posts_search( string $search, array $q, array $rule ) {
		global $wpdb;

		$phrase = $q['wcpos_search_phrase'] ?? null;
		if ( empty( $search ) && null === $phrase ) {
			return $search; // skip processing - no search term in query.
		}
		$search_terms = null !== $phrase
			? Collection_Rules::search_terms( $phrase )
			: (array) $q['search_terms'];
		if ( null !== $phrase && array() === $search_terms ) {
			return ' AND 1=0 ';
		}
		if ( null !== $phrase && $rule['term_cap'] < \count( $search_terms ) ) {
			$search_terms = array( $phrase );
		}

		$n = ! empty( $q['exact'] ) ? '' : '%';

		// Meta fields to search.
		$meta_fields = $rule['posts']['meta'];

		$meta_placeholders = implode( ', ', array_fill( 0, \count( $meta_fields ), '%s' ) );
		$search_conditions = array();

		foreach ( $search_terms as $term ) {
			$term = $n . $wpdb->esc_like( $term ) . $n;

			// Search in meta fields.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $meta_placeholders is a generated list of %s placeholders, and the keys themselves are passed to prepare() as arguments.
			$search_conditions[] = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS wcpos_search_meta WHERE wcpos_search_meta.post_id = {$wpdb->posts}.ID AND wcpos_search_meta.meta_key IN ($meta_placeholders) AND wcpos_search_meta.meta_value LIKE %s
				)",
				array_merge( $meta_fields, array( $term ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! empty( $search_conditions ) ) {
			$search = ' AND (' . implode( ' AND ', $search_conditions ) . ') ';
			if ( ! is_user_logged_in() ) {
				$search .= " AND ($wpdb->posts.post_password = '') ";
			}
		}

		return $search;
	}

	/**
	 * Variation search uses only meta carriers; an exact SKU lookup takes precedence.
	 *
	 * @param array  $args   Query arguments.
	 * @param string $search Search text.
	 * @param string $sku    Exact SKU lookup.
	 * @param array  $rule   Declared search carriers and cap.
	 * @return array
	 */
	public static function variation_args( array $args, string $search, string $sku, array $rule ): array {
		if ( '' !== $sku ) {
			unset( $args['s'] );
		}
		if ( '' !== $search && '' === $sku ) {
			unset( $args['s'] );
			$args['wcpos_variation_search'] = true;
			$carriers = array( 'relation' => 'AND' );
			foreach ( Collection_Rules::search_terms( trim( $search ) ) as $term ) {
				$term_carriers = array( 'relation' => 'OR' );
				foreach ( $rule['posts']['meta'] as $key ) {
					$term_carriers[] = array(
						'key'     => $key,
						'value'   => $term,
						'compare' => 'LIKE',
					);
				}
				$carriers[] = $term_carriers;
			}
			if ( 1 < \count( $carriers ) ) {
				$args['meta_query'] = empty( $args['meta_query'] ) ? array() : $args['meta_query'];
				$args['meta_query'][] = $carriers;
			}
		}
		return $args;
	}

	/**
	 * De-duplicate matching variation meta rows.
	 *
	 * @param string $groupby GROUP BY SQL.
	 * @param array  $q       Query variables.
	 * @return string
	 */
	public static function variation_groupby( string $groupby, array $q ): string {
		global $wpdb;
		return ! empty( $q['wcpos_variation_search'] ) ? "{$wpdb->posts}.ID" : $groupby;
	}

	/**
	 * Whether the query carries a search term. Not empty(): the literal term "0" is a search.
	 *
	 * @param array $q Query variables.
	 * @return bool
	 */
	private static function is_searching( array $q ): bool {
		return '' !== (string) ( $q['wcpos_search_phrase'] ?? $q['s'] ?? '' );
	}
}
