<?php
/**
 * Fingerprint of the current user's effective permissions and store settings.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WP_User;

/** Computes the permissions stamp without loading receipt translations. */
final class Permissions_Stamp {
	/** Response header carrying the stamp. */
	public const HEADER = 'X-WCPOS-Permissions';

	/**
	 * Hash effective capabilities and accessible stores' settings.
	 * Later slices add the store's locks and the cashier's PIN state to the hashed parts.
	 *
	 * @param WP_User $user User to fingerprint.
	 */
	public static function for_user( WP_User $user ): string {
		$stores = array();
		foreach ( Cashier::instance()->get_accessible_stores( $user ) as $store ) {
			$stores[] = $store->get_settings_fingerprint_data();
		}
		$parts = array(
			'capabilities' => Access_Section::effective_capabilities( $user ),
			'stores'       => $stores,
		);
		return substr( hash( 'sha256', wp_json_encode( $parts ) ), 0, 16 );
	}
}
