<?php
/**
 * Stable secondary sort keys for the catalog proxy.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

/**
 * Pins a deterministic id tiebreak under mutable, tie-prone sort keys.
 *
 * ORDER BY a tied column alone gives MySQL no total order ACROSS separate
 * offset queries, so a row tied at a page boundary can appear on two pages or
 * on neither while the POS client walks a multi-page window (mono#1372). The
 * client renders ties in ascending id order (its declared tiebreak), so the
 * secondary key here is always `id ASC` — wire pages and rendered rows agree
 * exactly, whatever the primary direction.
 */
final class Stable_Sort {
	/**
	 * Post orderbys whose values routinely tie (duplicate titles, shared
	 * import timestamps). `id`/`include` are total orders already.
	 */
	private const TIED_POST_ORDERBYS = array( 'title', 'date', 'modified' );

	/**
	 * Rewrite a tied post-query orderby to its array form carrying the id tiebreak.
	 *
	 * @param array $args WP_Query args prepared by the wc/v3 controller.
	 *
	 * @return array
	 */
	public static function with_post_id_tiebreak( array $args ): array {
		$orderby = $args['orderby'] ?? null;
		// WC's CRUD controller already appends ' ID' for date/modified ("for
		// consistency with pagination") — but in the space-string form, where the
		// GLOBAL direction applies to every field, so a desc sort ties by ID DESC
		// while the client renders ties by id ASC. Normalize that form back to the
		// bare key so the rewrite below pins ID ASC either way.
		if ( \is_string( $orderby ) && str_ends_with( $orderby, ' ID' ) ) {
			$orderby = substr( $orderby, 0, -3 );
		}
		if ( ! \is_string( $orderby ) || ! \in_array( $orderby, self::TIED_POST_ORDERBYS, true ) ) {
			return $args;
		}
		$order           = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$args['orderby'] = array(
			$orderby => $order,
			'ID'     => 'ASC',
		);

		return $args;
	}

	/**
	 * Append the term_id tiebreak to a tie-prone term-query ORDER BY clause.
	 *
	 * The get_terms API has no array-orderby form, so the tiebreak lands at the clause
	 * level. Only `name` can tie (same-named children under different parents);
	 * the direction moves INTO the orderby clause because WP appends the free
	 * `order` to the LAST expression only.
	 *
	 * @param array $clauses WP_Term_Query SQL clauses.
	 *
	 * @return array
	 */
	public static function with_term_id_tiebreak( array $clauses ): array {
		$orderby = (string) ( $clauses['orderby'] ?? '' );
		if ( 'ORDER BY t.name' !== $orderby ) {
			return $clauses;
		}
		$order              = 'DESC' === strtoupper( (string) ( $clauses['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
		$clauses['orderby'] = $orderby . ' ' . $order . ', t.term_id';
		$clauses['order']   = 'ASC';

		return $clauses;
	}
}
