<?php
/**
 * WP-CLI affordances for the anonymous analytics identity.
 *
 * @package WCPOS\WooCommercePOS\CLI
 */

namespace WCPOS\WooCommercePOS\CLI;

use WCPOS\WooCommercePOS\Services\Anon_ID;

/**
 * Manages the wcpos_anon_id analytics identifier.
 */
class Anon_ID_Command {
	/**
	 * Replaces the stored anonymous id with a fresh UUID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos anon-id rotate
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function rotate( array $args, array $assoc_args ): void {
		$new = ( new Anon_ID() )->rotate();
		$this->success( \sprintf( 'wcpos_anon_id rotated. New id: %s', $new ) );
	}

	/**
	 * Deletes the stored anonymous id. A new one is generated on the next
	 * landing-page load.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos anon-id delete
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function delete( array $args, array $assoc_args ): void {
		( new Anon_ID() )->delete();
		$this->success( 'wcpos_anon_id deleted.' );
	}

	/**
	 * Output seam — WP_CLI is absent in the unit-test container.
	 *
	 * @param string $message Success message.
	 */
	protected function success( string $message ): void {
		// @phpstan-ignore-next-line -- WP_CLI exists only under the wp-cli runtime; unit tests override this seam.
		\WP_CLI::success( $message );
	}
}
