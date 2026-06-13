<?php
/**
 * Tests for the WP-CLI Anon_ID_Command.
 *
 * @package WCPOS\WooCommercePOS\Tests\CLI
 */

namespace WCPOS\WooCommercePOS\Tests\CLI;

use WCPOS\WooCommercePOS\CLI\Anon_ID_Command;
use WCPOS\WooCommercePOS\Services\Anon_ID;
use WP_UnitTestCase;

/**
 * Tests the WP-CLI rotate/delete commands for the anonymous analytics identity.
 *
 * @covers \WCPOS\WooCommercePOS\CLI\Anon_ID_Command
 */
class Test_Anon_ID_Command extends WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Anon_ID::OPTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( Anon_ID::OPTION );
		parent::tearDown();
	}

	/**
	 * Returns a test double that captures success() output instead of calling WP_CLI::success().
	 *
	 * @return Anon_ID_Command
	 */
	private function command_double(): Anon_ID_Command {
		return new class() extends Anon_ID_Command {
			/**
			 * Captured success messages.
			 *
			 * @var string[]
			 */
			public $messages = array();

			/**
			 * Captures the message instead of delegating to WP_CLI::success().
			 *
			 * @param string $message Success message.
			 */
			protected function success( string $message ): void {
				$this->messages[] = $message;
			}
		};
	}

	/**
	 * Asserts that rotate() generates a new id, persists it, and reports it.
	 */
	public function test_rotate_replaces_stored_id_and_reports_new_value(): void {
		$old     = ( new Anon_ID() )->get();
		$command = $this->command_double();

		$command->rotate( array(), array() );

		$new = get_option( Anon_ID::OPTION );
		$this->assertNotSame( $old, $new );
		$this->assertStringContainsString( $new, $command->messages[0] );
	}

	/**
	 * Asserts that delete() removes the option and emits a success message.
	 */
	public function test_delete_removes_stored_id_and_reports(): void {
		( new Anon_ID() )->get();
		$command = $this->command_double();

		$command->delete( array(), array() );

		$this->assertFalse( get_option( Anon_ID::OPTION ) );
		$this->assertNotEmpty( $command->messages );
	}
}
