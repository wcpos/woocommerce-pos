<?php
/**
 * Tests for Logger duplicate suppression.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Logger;
use WC_Unit_Test_Case;

/**
 * Logger test case.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Logger
 */
class Test_Logger extends WC_Unit_Test_Case {

	/**
	 * Captured log messages.
	 *
	 * @var array
	 */
	private array $logged_messages = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->logged_messages = array();
		Logger::reset_dedup_state();

		// Capture all log writes via the filter.
		add_filter(
			'woocommerce_pos_logging',
			function ( $should_log, $message ) {
				$this->logged_messages[] = $message;

				// Return false to prevent actual WC_Logger writes during tests.
				return false;
			},
			10,
			2
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_logging' );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/**
	 * Test that duplicate messages are suppressed.
	 *
	 * @covers ::log
	 */
	public function test_duplicate_messages_are_suppressed(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			Logger::log( 'Same message repeated' );
		}

		// Only the first call should pass through to the filter.
		$this->assertCount( 1, $this->logged_messages );
		$this->assertEquals( 'Same message repeated', $this->logged_messages[0] );
	}

	/**
	 * Test that different messages are not suppressed.
	 *
	 * @covers ::log
	 */
	public function test_different_messages_are_not_suppressed(): void {
		Logger::log( 'Message A' );
		Logger::log( 'Message B' );
		Logger::log( 'Message C' );

		$this->assertCount( 3, $this->logged_messages );
	}

	/**
	 * Test that flush writes repeat count when a new message arrives.
	 *
	 * @covers ::log
	 * @covers ::flush_repeat_count
	 */
	public function test_flush_writes_repeat_count(): void {
		Logger::log( 'Repeated message' );
		Logger::log( 'Repeated message' );
		Logger::log( 'Repeated message' );
		Logger::log( 'Different message' );

		// Should see: "Repeated message", "Previous message repeated 2 more times", "Different message".
		$this->assertCount( 3, $this->logged_messages );
		$this->assertEquals( 'Repeated message', $this->logged_messages[0] );
		$this->assertStringContainsString( 'repeated 2 more', $this->logged_messages[1] );
		$this->assertEquals( 'Different message', $this->logged_messages[2] );
	}

	/**
	 * Test that a single duplicate does not generate a repeat message.
	 *
	 * @covers ::log
	 */
	public function test_single_repeat_flushed_correctly(): void {
		Logger::log( 'Once' );
		Logger::log( 'Once' );
		Logger::log( 'Twice' );

		$this->assertCount( 3, $this->logged_messages );
		$this->assertStringContainsString( 'repeated 1 more', $this->logged_messages[1] );
	}

	/**
	 * Test that manual flush at end of request works.
	 *
	 * @covers ::flush_repeat_count
	 */
	public function test_manual_flush(): void {
		Logger::log( 'Flush me' );
		Logger::log( 'Flush me' );
		Logger::log( 'Flush me' );
		Logger::flush_repeat_count();

		$this->assertCount( 2, $this->logged_messages );
		$this->assertStringContainsString( 'repeated 2 more', $this->logged_messages[1] );
	}

	/**
	 * Test that context differences make messages distinct.
	 *
	 * @covers ::log
	 */
	public function test_context_makes_messages_distinct(): void {
		Logger::log( 'Same message', 'context-a' );
		Logger::log( 'Same message', 'context-b' );

		$this->assertCount( 2, $this->logged_messages );
	}

	/**
	 * Test that different log levels make messages distinct.
	 *
	 * @covers ::log
	 * @covers ::warning
	 */
	public function test_level_makes_messages_distinct(): void {
		Logger::log( 'Same message' );
		Logger::warning( 'Same message' );

		$this->assertCount( 2, $this->logged_messages );
	}
}
