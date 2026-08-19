<?php
/**
 * Term catalog proxy behavior (categories, tags, brands).
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

/**
 * Pins the stable name-sort tiebreak during the forward.
 *
 * The POS client's term lanes default to name ascending (mono#1371) and walk
 * multi-page windows; see {@see Stable_Sort} for why ties need a secondary key.
 */
final class Terms_Proxy_Behavior extends Scoped_Proxy_Behavior {
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
