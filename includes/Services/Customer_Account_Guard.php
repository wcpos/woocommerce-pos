<?php
/**
 * Staff account protection for customer writes.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * WooCommerce refuses credential changes on non-customer roles, but the old
 * WCPOS edit_users fallback bypassed that refusal. Other staff profile fields
 * remain editable in WooCommerce. This guard keeps non-admin POS users off
 * other staff accounts without widening WooCommerce's own checks.
 */
class Customer_Account_Guard {
	/**
	 * Get the capabilities that identify protected staff accounts.
	 *
	 * @return array
	 */
	public static function protected_capabilities(): array {
		$caps = apply_filters( 'woocommerce_pos_protected_account_capabilities', array( 'manage_options', 'manage_woocommerce', 'edit_users' ) );

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $caps ) ) ) );
	}

	/**
	 * Check staff protection before WooCommerce's own permission checks.
	 *
	 * @param int $actor_id  Acting user ID.
	 * @param int $target_id Target user ID.
	 * @return bool
	 */
	public static function can_modify( int $actor_id, int $target_id ): bool {
		if ( user_can( $actor_id, 'manage_options' ) || $actor_id === $target_id ) {
			return true;
		}
		$target = get_user_by( 'id', $target_id );
		if ( ! $target ) {
			return true;
		}
		foreach ( self::protected_capabilities() as $cap ) {
			if ( user_can( $target, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build the staff account permission error.
	 *
	 * @return \WP_Error
	 */
	public static function denial(): \WP_Error {
		return new \WP_Error(
			'woocommerce_pos_rest_cannot_edit_staff_account',
			__( 'Sorry, POS users cannot edit staff accounts.', 'woocommerce-pos' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
