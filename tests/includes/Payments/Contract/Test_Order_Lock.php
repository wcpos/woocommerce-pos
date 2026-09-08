<?php
/**
 * Order payment locking tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

class Test_Order_Lock extends \WP_UnitTestCase {
	public function test_lock_is_reentrant_and_released_after_nested_callback(): void {
		$lock = Order_Lock::instance();
		$result = $lock->with_lock( 123, function () use ( $lock ) {
			return $lock->with_lock( 123, static function () { return 'nested'; } );
		} );
		$this->assertSame( 'nested', $result );
		$this->assertSame( 'released', ( new Order_Lock() )->with_lock( 123, static function () { return 'released'; } ) );
	}

	public function test_second_object_cannot_acquire_held_lock(): void {
		$first = new Order_Lock();
		$this->assertTrue( $first->acquire( 123 ) );
		try {
			$result = ( new Order_Lock() )->with_lock( 123, function () { $this->fail( 'Contending callback ran.' ); } );
			$this->assertSame( 'wcpos_payment_locked', $result->get_error_code() );
			$this->assertSame( array( 'status' => 409, 'retry_after' => 1 ), $result->get_error_data() );
		} finally {
			$first->release( 123 );
		}
	}

	public function test_callback_exception_releases_lock(): void {
		try {
			Order_Lock::instance()->with_lock( 123, static function () { throw new \RuntimeException( 'test' ); } );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'test', $exception->getMessage() );
		}
		$this->assertTrue( ( new Order_Lock() )->with_lock( 123, static function () { return true; } ) );
	}

	public function test_nested_lock_uses_the_option_lease_where_the_server_cannot_hold_two(): void {
		global $wpdb;
		$filter = '__return_false';
		add_filter( 'wcpos_order_lock_supports_multiple_locks', $filter );
		$reflection = new \ReflectionProperty( Order_Lock::class, 'multi_lock' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, null );
		$outer = new Order_Lock();
		$inner = new Order_Lock();
		try {
			$this->assertTrue( $outer->acquire( 501 ) );
			$this->assertTrue( $inner->acquire( 502 ) );
			// The nested lock is an option lease, and the outer MySQL lock is still held.
			$this->assertNotEmpty( get_option( 'wcpos_payment_lock_502' ) );
			$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', sprintf( 'wcpos_order_%d_%d', 501, get_current_blog_id() ) ) ) );
		} finally {
			$inner->release( 502 );
			$outer->release( 501 );
			remove_filter( 'wcpos_order_lock_supports_multiple_locks', $filter );
			$reflection->setValue( null, null );
		}
		$this->assertFalse( get_option( 'wcpos_payment_lock_502' ) );
	}

	public function test_option_fallback_takes_stale_lease_and_preserves_new_owner_on_release(): void {
		$filter = static function ( $sql ) {
			return 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $sql;
		};
		add_filter( 'query', $filter );
		$name = 'wcpos_payment_lock_123';
		add_option( $name, 'old|' . ( time() - 301 ), '', 'no' );
		$lock = new Order_Lock();
		try {
			$this->assertTrue( $lock->acquire( 123 ) );
			$this->assertStringNotContainsString( 'old|', get_option( $name ) );
			update_option( $name, 'replacement|' . time(), false );
			$owner = get_option( $name );
			$lock->release( 123 );
			$this->assertSame( $owner, get_option( $name ) );
		} finally {
			$lock->release( 123 );
			delete_option( $name );
			remove_filter( 'query', $filter );
		}
	}
	public function test_option_fallback_does_not_overwrite_competing_insert(): void {
		global $wpdb;
		$name = 'wcpos_payment_lock_124';
		$competitor = 'competitor|' . time();
		$inserted = false;
		$filter = static function ( $sql ) use ( $wpdb, $name, $competitor, &$inserted ) {
			if ( 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ) {
				return 'SELECT NULL';
			}
			if ( ! $inserted && 0 === strpos( $sql, 'INSERT' ) && false !== strpos( $sql, $name ) ) {
				// Another request inserts after add_option's absence check, before its SQL.
				$inserted = true;
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $competitor, 'no' ) );
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		$lock = new Order_Lock();
		try {
			$this->assertFalse( $lock->acquire( 124 ) );
			$this->assertSame( $competitor, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
		} finally {
			$lock->release( 124 );
			delete_option( $name );
			remove_filter( 'query', $filter );
		}
	}

}
