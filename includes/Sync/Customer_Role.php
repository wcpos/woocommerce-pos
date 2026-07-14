<?php
/**
 * Shared customer-role predicates for the sync surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Keep per-user and set-based customer membership checks aligned.
 */
final class Customer_Role {
	/**
	 * Return whether a user belongs to the customer collection.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function is_customer( int $user_id ): bool {
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		return $user && in_array( 'customer', (array) $user->roles, true );
	}

	/**
	 * Return the SQL join that restricts a user expression to customer-role users.
	 *
	 * The arguments are internal SQL identifiers, never request input.
	 *
	 * @param string $user_id_expression User ID column expression.
	 * @param string $capabilities_alias Alias for the capabilities usermeta row.
	 */
	public static function sql_join( string $user_id_expression, string $capabilities_alias ): string {
		global $wpdb;
		$capabilities_key = $wpdb->prefix . 'capabilities';

		return " INNER JOIN {$wpdb->usermeta} {$capabilities_alias}"
			. " ON {$capabilities_alias}.user_id = {$user_id_expression}"
			. " AND {$capabilities_alias}.meta_key = '{$capabilities_key}'"
			. " AND INSTR({$capabilities_alias}.meta_value, '\"customer\"') > 0";
	}
}
