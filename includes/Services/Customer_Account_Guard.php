<?php
/**
 * Backward-compatible staff guard API.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Backward-compatible staff guard.
 *
 * @deprecated Use Permission_Rules::verdict() for complete permission decisions.
 */
class Customer_Account_Guard {
	/**
	 * Get protected staff capabilities.
	 *
	 * @deprecated Use Permission_Rules::protected_capabilities().
	 * @return array
	 */
	public static function protected_capabilities(): array {
		return Permission_Rules::protected_capabilities();
	}

	/**
	 * Check whether an actor may modify a target.
	 *
	 * @deprecated Use Permission_Rules::can_modify().
	 * @param int $actor_id  Acting user ID.
	 * @param int $target_id Target user ID.
	 * @return bool
	 */
	public static function can_modify( int $actor_id, int $target_id ): bool {
		return Permission_Rules::can_modify( $actor_id, $target_id );
	}

	/**
	 * Temporarily allow a cleared target role.
	 *
	 * @deprecated Use Permission_Rules::allow_target_roles().
	 * @param int $target_id Cleared target user ID.
	 * @return callable
	 */
	public static function allow_target_roles( int $target_id ): callable {
		return Permission_Rules::allow_target_roles( $target_id );
	}

	/**
	 * Build the staff account permission error.
	 *
	 * @deprecated Use Permission_Rules::denial().
	 * @return \WP_Error
	 */
	public static function denial(): \WP_Error {
		return Permission_Rules::denial();
	}
}
