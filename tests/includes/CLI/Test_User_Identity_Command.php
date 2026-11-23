<?php
/**
 * Tests for the user identity reset command.
 *
 * @package WCPOS\WooCommercePOS\Tests\CLI
 */

namespace WCPOS\WooCommercePOS\Tests\CLI;

use WCPOS\WooCommercePOS\CLI\User_Identity_Command;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_UnitTestCase;

/**
 * Exercises the reset command through its output seams.
 *
 * @covers \WCPOS\WooCommercePOS\CLI\User_Identity_Command
 */
class Test_User_Identity_Command extends WP_UnitTestCase {

	/** Capture output without a WP-CLI runtime. */
	private function command_double(): User_Identity_Command {
		return new class() extends User_Identity_Command {
			/**
			 * Captured success messages.
			 *
			 * @var string[]
			 */
			public $messages = array();
			/**
			 * Captured error messages.
			 *
			 * @var string[]
			 */
			public $errors = array();

			/**
			 * Capture success output.
			 *
			 * @param string $message Success message.
			 */
			protected function success( string $message ): void {
				$this->messages[] = $message;
			}

			/**
			 * Capture error output.
			 *
			 * @param string $message Error message.
			 */
			protected function error( string $message ): void {
				$this->errors[] = $message;
			}
		};
	}

	/** Reset removes persisted identities and reports the affected user count. */
	public function test_reset_single_site_removes_identities_and_reports_count(): void {
		delete_metadata( 'user', 0, Pos_Uuid::META_KEY, '', true );
		$user_id = $this->factory->user->create();
		Pos_Uuid::ensure_user_uuid( $user_id );
		$command = $this->command_double();

		$command->reset( array(), array() );

		$this->assertFalse( metadata_exists( 'user', $user_id, Pos_Uuid::META_KEY ) );
		$this->assertSame( array( 'WCPOS account identities reset. Users affected: 1.' ), $command->messages );
		$this->assertSame( array(), $command->errors );
	}

	/** A failed reset reports an error instead of a success message. */
	public function test_reset_failed_delete_reports_error(): void {
		$user_id = $this->factory->user->create();
		Pos_Uuid::ensure_user_uuid( $user_id );
		$command = $this->command_double();
		add_filter( 'delete_user_metadata', '__return_false' );

		try {
			$command->reset( array(), array() );

			$this->assertSame( array( 'The reset failed. No account identities were changed.' ), $command->errors );
			$this->assertSame( array(), $command->messages );
		} finally {
			remove_filter( 'delete_user_metadata', '__return_false' );
		}
	}

	/**
	 * Network accounts cannot be reset from one store.
	 *
	 * @group multisite
	 */
	public function test_reset_multisite_reports_error_without_removing_identity(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs in the multisite suite only.' );
		}
		$user_id = $this->factory->user->create();
		$uuid    = Pos_Uuid::ensure_user_uuid( $user_id );
		$command = $this->command_double();

		$command->reset( array(), array() );

		$this->assertSame( $uuid, get_user_meta( $user_id, Pos_Uuid::META_KEY, true ) );
		$this->assertSame( array(), $command->messages );
		$this->assertSame( array( 'Accounts are shared across a multisite network, so this store cannot be given its own.' ), $command->errors );
	}
}
