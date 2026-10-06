<?php
/**
 * Customer search SQL, built from the Collection_Rules declaration.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;

/**
 * The ONE builder of the customer search WHERE fragment (monorepo#2411).
 *
 * Both lanes — the wcpos/v1 controller and the wcpos/v2 proxy — append this to their
 * WP_User_Query, so what a cashier can find never depends on which lane the till used.
 * The field list is `Collection_Rules::rules('customers')['search']` (filterable through
 * `woocommerce_pos_search_fields`); the per-site tax-ID meta keys join it at query time.
 */
final class Customer_Search {
	/**
	 * Build the per-term AND-ed WHERE fragment for a search string.
	 *
	 * Returns `1 = 0` when the string yields no terms (malformed UTF-8, whitespace only):
	 * the caller has already decided a search was asked for, and handing back every
	 * customer would be worse than none.
	 *
	 * @param string $search Raw search text.
	 * @return string SQL fragment without a leading AND, wrapped in parentheses.
	 */
	public static function where( string $search ): string {
		global $wpdb;

		$terms = preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $terms || empty( $terms ) ) {
			return '1 = 0';
		}

		$rule  = Collection_Rules::rules( 'customers' )['search'];
		$terms = array_slice( $terms, 0, $rule['term_cap'] );

		$meta_keys              = array_merge( $rule['meta'], Tax_Id_Reader::fallback_user_meta_keys() );
		$user_fields            = "{$wpdb->users}." . implode( " LIKE %s OR {$wpdb->users}.", $rule['users'] ) . ' LIKE %s';
		$meta_key_placeholders  = implode( ', ', array_fill( 0, \count( $meta_keys ), '%s' ) );
		$phone_key_placeholders = implode( ', ', array_fill( 0, \count( $rule['phone_meta'] ), '%s' ) );
		$groups                 = array();

		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$args = array_merge( array_fill( 0, \count( $rule['users'] ), $like ), $meta_keys, array( $like ) );

			// A term with digits and no letters is a phone fragment: compare the stored phone
			// with its punctuation stripped, so `0412` finds `(04) 1234 5678`.
			$phone  = '';
			$digits = preg_replace( '/\D+/', '', $term );
			if ( '' !== $digits && 0 === preg_match( '/\p{L}/u', $term ) ) {
				$phone = " OR ( wcpos_search_meta.meta_key IN ($phone_key_placeholders) AND " . Order_Search::phone_digits_expression( 'wcpos_search_meta.meta_value' ) . ' LIKE %s )';
				$args  = array_merge( $args, $rule['phone_meta'], array( '%' . $digits . '%' ) );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb, columns from the declaration; meta keys and LIKE values are passed to prepare().
			$groups[] = $wpdb->prepare(
				"( {$user_fields}
					OR EXISTS (
						SELECT 1
						FROM {$wpdb->usermeta} AS wcpos_search_meta
						WHERE wcpos_search_meta.user_id = {$wpdb->users}.ID
							AND ( ( wcpos_search_meta.meta_key IN ($meta_key_placeholders)
							AND wcpos_search_meta.meta_value LIKE %s ){$phone} )
					)
				)",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return '( ' . implode( ' AND ', $groups ) . ' )';
	}
}
