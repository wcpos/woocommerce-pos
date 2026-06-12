<?php
/**
 * Anonymous analytics identity for the wp-admin landing page.
 *
 * Owns the `wcpos_anon_id` option: a random v4 UUID that keys experiment
 * assignment for sites that have NOT consented to profile tracking. It is
 * never derived from the URL or site data (landing-experiments spec §5.1).
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Anon_ID service.
 */
class Anon_ID {
	/**
	 * Option name. Program-wide identifier — the wcpos.com reconciler and the
	 * landing bootstrap both refer to it by this name; do not rename.
	 */
	const OPTION = 'wcpos_anon_id';

	/**
	 * Returns the stored anon id, creating one on first use.
	 *
	 * @return string v4 UUID.
	 */
	public function get(): string {
		$id = get_option( self::OPTION );

		if ( ! \is_string( $id ) || '' === $id ) {
			$id = wp_generate_uuid4();
			// Autoload off: only the landing page (and WP-CLI) ever reads it.
			if ( ! add_option( self::OPTION, $id, '', false ) ) {
				// Lost a first-load race — return the persisted winner, never a stray id.
				$id = (string) get_option( self::OPTION );
			}
		}

		return $id;
	}

	/**
	 * Replaces the stored id with a fresh UUID (privacy affordance).
	 *
	 * @return string The new v4 UUID.
	 */
	public function rotate(): string {
		$id = wp_generate_uuid4();
		update_option( self::OPTION, $id, false );

		return $id;
	}

	/**
	 * Deletes the stored id (privacy affordance; also run on uninstall).
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}
}
