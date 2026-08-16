<?php
/**
 * WCPOS sync meta entry adapter.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Reads array and object forms of WooCommerce meta entries.
 */
final class Meta_Entry {
	/**
	 * Read a meta entry key.
	 *
	 * Returns the raw key without coercion. A malformed entry can carry a
	 * non-scalar key (an array, from a client that sent the wrong shape), and
	 * callers are responsible for their own tolerance — Pos_Order_Audit gates
	 * on is_scalar(), the comparison sites use strict === against a string.
	 * Declaring ?string here would fatal before those guards could run.
	 *
	 * @param mixed $entry WooCommerce meta entry.
	 * @return mixed
	 */
	public static function key( $entry ) {
		return \is_array( $entry ) ? ( $entry['key'] ?? null ) : ( \is_object( $entry ) ? ( $entry->key ?? null ) : null );
	}

	/**
	 * Read a meta entry value.
	 *
	 * @param mixed $entry WooCommerce meta entry.
	 * @return mixed
	 */
	public static function value( $entry ) {
		return \is_array( $entry ) ? ( $entry['value'] ?? null ) : ( \is_object( $entry ) ? ( $entry->value ?? null ) : null );
	}
}
