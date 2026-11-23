<?php
/**
 * WP-CLI account identity reset for copied stores.
 *
 * @package WCPOS\WooCommercePOS\CLI
 */

namespace WCPOS\WooCommercePOS\CLI;

use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

/** Manages WCPOS account identities. */
class User_Identity_Command {
	/**
	 * Clears user UUIDs so they are minted again on next use.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos user-identity reset
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function reset( array $args, array $assoc_args ): void {
		if ( is_multisite() ) {
			$this->error( 'Accounts are shared across a multisite network, so this store cannot be given its own.' );
			return;
		}
		$this->success( \sprintf( 'WCPOS account identities reset. Users affected: %d.', Pos_Uuid::reset_user_uuids() ) );
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

	/**
	 * Error seam matching the success output seam.
	 *
	 * @param string $message Error message.
	 */
	protected function error( string $message ): void {
		// @phpstan-ignore-next-line -- WP_CLI exists only under the wp-cli runtime; unit tests override this seam.
		\WP_CLI::error( $message );
	}
}
