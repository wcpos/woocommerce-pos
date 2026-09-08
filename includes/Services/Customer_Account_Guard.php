<?php
/**
 * Staff account protection for customer writes.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Decides which accounts a POS user may edit or delete.
 *
 * WooCommerce refuses credential changes on non-customer roles, but the old
 * WCPOS edit_users fallback bypassed that refusal, and WooCommerce's own test
 * reads only the target's FIRST role, so an administrator who also holds the
 * customer role passes it. This guard tests capabilities instead: a POS user
 * who is not an administrator may not edit or delete an account holding staff
 * capabilities, whatever its roles say. Reads are deliberately untouched — the
 * POS customer space is every user on the site (#1379).
 */
class Customer_Account_Guard {
	/**
	 * Get the capabilities that identify protected staff accounts.
	 *
	 * @return array
	 */
	public static function protected_capabilities(): array {
		/*
		 * Filters the capabilities that mark an account as staff.
		 *
		 * A POS user who cannot manage_options may not edit or delete an account
		 * holding any of these. Narrowing the list only relaxes this check; it
		 * never widens WooCommerce's own permission checks, which still run.
		 *
		 * @param {array} $capabilities
		 * @returns {array} $capabilities
		 * @since 1.10.10
		 * @hook woocommerce_pos_protected_account_capabilities
		 */
		$caps = apply_filters( 'woocommerce_pos_protected_account_capabilities', array( 'manage_options', 'manage_woocommerce', 'edit_users' ) );

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $caps ) ) ) );
	}

	/**
	 * Check staff protection before WooCommerce's own permission checks.
	 *
	 * Deny is the default for anything this method cannot resolve: it only ever
	 * removes permission, so a caller that reaches it with a bad id is refused
	 * rather than waved through.
	 *
	 * @param int $actor_id  Acting user ID.
	 * @param int $target_id Target user ID.
	 *
	 * @return bool
	 */
	public static function can_modify( int $actor_id, int $target_id ): bool {
		if ( $actor_id < 1 || $target_id < 1 ) {
			return false;
		}
		if ( user_can( $actor_id, 'manage_options' ) || $actor_id === $target_id ) {
			return true;
		}
		$target = get_user_by( 'id', $target_id );
		if ( ! $target ) {
			return false;
		}
		foreach ( self::protected_capabilities() as $cap ) {
			if ( user_can( $target, $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Let WooCommerce judge a cleared target by capability rather than role name.
	 *
	 * WooCommerce restricts a shop_manager to editing users whose role is in
	 * `woocommerce_shop_manager_editable_roles` (default: customer only), so a
	 * subscriber or a membership plugin's own customer role is refused outright
	 * even though the POS lists it. Once can_modify() has cleared the target of
	 * every staff capability, that role-name test adds nothing, so allow the
	 * target's own roles for the duration of the check.
	 *
	 * Call only after can_modify() returned true, and always invoke the returned
	 * closure to remove the filter.
	 *
	 * @param int $target_id Target user ID, already cleared by can_modify().
	 *
	 * @return callable Restores the unfiltered behaviour.
	 */
	public static function allow_target_roles( int $target_id ): callable {
		$target = get_user_by( 'id', $target_id );
		$roles  = $target ? array_values( (array) $target->roles ) : array();

		if ( empty( $roles ) ) {
			return static function (): void {};
		}

		$filter = static function ( $allowed ) use ( $roles ) {
			return array_values( array_unique( array_merge( (array) $allowed, $roles ) ) );
		};

		add_filter( 'woocommerce_shop_manager_editable_roles', $filter );

		return static function () use ( $filter ): void {
			remove_filter( 'woocommerce_shop_manager_editable_roles', $filter );
		};
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
