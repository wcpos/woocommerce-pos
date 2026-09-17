<?php
/**
 * Request-boundary write coalescing without a sync store.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Request_Write_Queue;
use WP_UnitTestCase;

/**
 * Queue state transitions through an injected writer.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Request_Write_Queue
 */
class Test_Request_Write_Queue extends WP_UnitTestCase {
	/**
	 * Record the queue's writes, including their blog context.
	 *
	 * @param array $writes   Captured writes.
	 * @param int   $capacity Maximum pending records.
	 */
	private function queue( array &$writes, int $capacity = 3 ): Request_Write_Queue {
		return new Request_Write_Queue(
			$capacity,
			static function ( $type, $id, $payload ) use ( &$writes ): void {
				$writes[] = array( $type, $id, $payload, get_current_blog_id() );
			}
		);
	}

	/** Repeated hooks retain only the last non-null payload. */
	public function test_same_key_coalesces_with_last_non_null_payload(): void {
		// Arrange.
		$writes = array();
		$queue  = $this->queue( $writes );
		// Act.
		$queue->owe( 'order', 1, 'first' );
		$queue->owe( 'order', 1, 'last' );
		$queue->owe( 'order', 1 );
		// Assert.
		$this->assertSame( array(), $writes );
		$queue->flush();
		$this->assertSame( array( array( 'order', 1, 'last', get_current_blog_id() ) ), $writes );
	}

	/** The next distinct record flushes a full queue in insertion order. */
	public function test_capacity_overflow_flushes_before_adding_next_key(): void {
		foreach ( array( array( 1 ), array( 1, 2, 3 ) ) as $expected ) {
			$capacity = count( $expected );
			// Arrange.
			$writes = array();
			$queue  = $this->queue( $writes, $capacity );
			foreach ( $expected as $id ) {
				$queue->owe( 'order', $id );
			}
			$queue->owe( 'order', 1 );
			$this->assertSame( array(), $writes );
			// Act.
			$queue->owe( 'order', $capacity + 1 );
			// Assert.
			$this->assertSame( $expected, array_column( $writes, 1 ) );
			$this->assertFalse( $queue->owes( 'order', 1 ) );
			$this->assertTrue( $queue->owes( 'order', $capacity + 1 ) );
			$queue->flush();
			$this->assertSame( array_merge( $expected, array( $capacity + 1 ) ), array_column( $writes, 1 ) );
		}
	}

	/** Dropping one type/id leaves the other type's matching id pending. */
	public function test_drop_forgets_only_the_pending_key(): void {
		// Arrange.
		$writes = array();
		$queue  = $this->queue( $writes );
		$queue->owe( 'order', 1 );
		$queue->owe( 'customer', 1 );
		$this->assertTrue( $queue->owes( 'order', 1 ) );
		// Act.
		$queue->drop( 'order', 1 );
		// Assert.
		$this->assertFalse( $queue->owes( 'order', 1 ) );
		$this->assertTrue( $queue->owes( 'customer', 1 ) );
		$queue->flush();
		$this->assertSame( array( array( 'customer', 1, null, get_current_blog_id() ) ), $writes );
	}

	/** Empty and repeated flushes do not write extra rows. */
	public function test_flush_is_idempotent(): void {
		// Arrange.
		$writes = array();
		$queue  = $this->queue( $writes );
		// Act / Assert.
		$queue->flush();
		$this->assertSame( array(), $writes );
		$queue->owe( 'order', 1 );
		$queue->flush();
		$queue->flush();
		$this->assertSame( array( array( 'order', 1, null, get_current_blog_id() ) ), $writes );
	}

	/** An empty shutdown still latches; reset clears both latch and entries. */
	public function test_shutdown_writes_immediately_until_reset(): void {
		// Arrange.
		$writes = array();
		$queue  = $this->queue( $writes );
		// Act / Assert.
		$queue->flush_at_shutdown();
		$queue->owe( 'order', 1 );
		$this->assertSame( array( array( 'order', 1, null, get_current_blog_id() ) ), $writes );
		$queue->reset();
		$queue->owe( 'order', 2 );
		$this->assertTrue( $queue->owes( 'order', 2 ) );
		$this->assertSame( 1, count( $writes ) );
		$queue->reset();
		$queue->flush();
		$this->assertFalse( $queue->owes( 'order', 2 ) );
		$this->assertSame( 1, count( $writes ) );
	}

	/** A throwing writer propagates without replaying any drained entries. */
	public function test_throwing_write_does_not_replay_on_later_flush(): void {
		// Arrange.
		$attempts = array();
		$error    = new \RuntimeException( 'write failed' );
		$queue    = new Request_Write_Queue(
			3,
			static function ( $type, $id ) use ( &$attempts, $error ): void {
				$attempts[] = $id;
				throw $error; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test exception, not output.
			}
		);
		$queue->owe( 'order', 1 );
		$queue->owe( 'order', 2 );
		// Act / Assert.
		try {
			$queue->flush();
			$this->fail( 'The write exception must propagate.' );
		} catch ( \RuntimeException $caught ) {
			$this->assertSame( $error, $caught );
		}
		$queue->flush();
		$this->assertSame( array( 1 ), $attempts );
		$this->assertFalse( $queue->owes( 'order', 2 ) );
	}

	/** Reentrant writes start a fresh queue rather than getting lost. */
	public function test_reentrant_owe_remains_pending_after_flush(): void {
		// Arrange.
		$writes = array();
		$queue  = new Request_Write_Queue(
			1,
			static function ( $type, $id ) use ( &$writes, &$queue ): void {
				$writes[] = $id;
				if ( 1 === $id ) {
					$queue->owe( 'order', 2 );
				}
			}
		);
		$queue->owe( 'order', 1 );
		// Act / Assert.
		$queue->flush();
		$this->assertSame( array( 1 ), $writes );
		$this->assertTrue( $queue->owes( 'order', 2 ) );
		$queue->flush();
		$queue->flush();
		$this->assertSame( array( 1, 2 ), $writes );
	}
}
