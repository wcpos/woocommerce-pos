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
		$number_keys = "'" . implode( "', '", $rule['number_meta'] ) . "'";
		$items = self::line_items_where( "`{$orders}`.id", $rule );
		foreach ( self::terms( $search, $rule ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "`{$orders}`.id = %d OR " : '';
			$args = array_fill( 0, 1 + count( $rule['hpos']['addresses'] ), $like );
			if ( '' !== $id ) {
				array_unshift( $args, (int) $term );
			}
			$phone = '';
			$digits = preg_replace( '/\D+/', '', $term );
			if ( '' !== $digits && 0 === preg_match( '/\p{L}/u', $term ) ) {
				$phone = ' OR ' . self::phone_digits_expression( 'phone' ) . ' LIKE %s';
				$args[] = '%' . $digits . '%';
			}
			array_push( $args, $like, $like, $like );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WooCommerce.
			$conditions[] = $wpdb->prepare(
				"( {$id}`{$orders}`.billing_email LIKE %s OR `{$orders}`.id IN (
					SELECT order_id FROM `{$addresses}` WHERE address_type IN ('billing','shipping')
					AND ( {$fields}{$phone} )
				) OR EXISTS (
					SELECT 1 FROM {$wpdb->prefix}wc_orders_meta AS wcpos_number
					WHERE wcpos_number.order_id = `{$orders}`.id AND wcpos_number.meta_key IN ( {$number_keys} ) AND wcpos_number.meta_value LIKE %s
				) OR {$items} )",
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
		$keys = "'" . implode( "', '", array_merge( $rule['posts']['meta'], $rule['number_meta'] ) ) . "'";
		$items = self::line_items_where( "{$wpdb->posts}.ID", $rule );
		foreach ( self::terms( $search, $rule ) as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? "{$wpdb->posts}.ID = %d OR " : '';
			$args = '' === $id ? array( $like ) : array( (int) $term, $like );
			$phone = '';
			$digits = preg_replace( '/\D+/', '', $term );
			if ( '' !== $digits && 0 === preg_match( '/\p{L}/u', $term ) ) {
				$phone = " OR ( wcpos_order_search_meta.meta_key IN ('_billing_phone','_shipping_phone') AND " . self::phone_digits_expression( 'wcpos_order_search_meta.meta_value' ) . ' LIKE %s )';
				$args[] = '%' . $digits . '%';
			}
			array_push( $args, $like, $like );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb.
			$conditions[] = $wpdb->prepare(
				"( {$id}EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS wcpos_order_search_meta WHERE wcpos_order_search_meta.post_id = {$wpdb->posts}.ID AND wcpos_order_search_meta.meta_key IN ( {$keys} ) AND ( wcpos_order_search_meta.meta_value LIKE %s{$phone} )
				) OR {$items} )",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false === preg_match( '//u', $search ) ? '1=0' : implode( ' AND ', $conditions );
	}

	/**
	 * Build shared line-item arms, each with one LIKE placeholder.
	 *
	 * @param string $order_id Trusted outer order ID column.
	 * @param array  $rule     Declared search fields.
	 * @return string
	 */
	private static function line_items_where( string $order_id, array $rule ): string {
		global $wpdb;
		$name = $rule['line_items']['name'];
		$sku = $rule['line_items']['sku'];
		return "EXISTS (
			SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items AS wcpos_item
			WHERE wcpos_item.order_id = {$order_id} AND wcpos_item.order_item_type = 'line_item' AND wcpos_item.{$name} LIKE %s
		) OR EXISTS (
			SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items AS wcpos_item
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcpos_variation ON wcpos_variation.order_item_id = wcpos_item.order_item_id AND wcpos_variation.meta_key = '_variation_id'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcpos_product ON wcpos_product.order_item_id = wcpos_item.order_item_id AND wcpos_product.meta_key = '_product_id'
			INNER JOIN {$wpdb->postmeta} AS wcpos_sku ON wcpos_sku.post_id = COALESCE( NULLIF( wcpos_variation.meta_value, 0 ), wcpos_product.meta_value ) AND wcpos_sku.meta_key = '{$sku}'
			WHERE wcpos_item.order_id = {$order_id} AND wcpos_item.order_item_type = 'line_item' AND wcpos_sku.meta_value LIKE %s
		)";
	}

	/**
	 * Strip the same phone punctuation in both storage dialects.
	 *
	 * @param string $column Trusted phone value column.
	 * @return string
	 */
	public static function phone_digits_expression( string $column ): string {
		foreach ( array( ' ', '-', '(', ')', '+', '.' ) as $character ) {
			$column = "REPLACE({$column}, '{$character}', '')";
		}
		return $column;
	}
}
