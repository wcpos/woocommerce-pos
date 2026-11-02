<?php
/** Per-test tripwire for payment locks, including leases hidden by WP's rollback. */
namespace WCPOS\WooCommercePOS\Tests\Helpers;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

final class Order_Lock_Guard implements TestListener {
	use TestListenerDefaultImplementation;

	private $snapshot;

	public function startTest( Test $test ): void {
		$this->snapshot = null;
		add_filter( 'query', array( $this, 'before_rollback' ) );
	}

	public function before_rollback( string $sql ): string {
		if ( 'ROLLBACK' === $sql && null === $this->snapshot ) {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'tear_down' === ( $frame['function'] ?? '' ) && 'WP_UnitTestCase_Base' === ( $frame['class'] ?? '' ) ) {
					$this->snapshot = $this->locks();
					break;
				}
			}
		}
		return $sql;
	}

	public function endTest( Test $test, float $time ): void {
		remove_filter( 'query', array( $this, 'before_rollback' ) );
		$locks = $this->snapshot ?? $this->locks();
		if ( null === $this->snapshot ) {
			// WP leaves autocommit off. Close our post-test read transaction so a
			// separate-process test can reinstall tables without a metadata-lock wait.
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
		}
		if ( $test instanceof TestCase && array_filter( $locks ) ) {
			$test->getTestResultObject()->addFailure(
				$test,
				new AssertionFailedError( 'Payment lock leak after ' . get_class( $test ) . '::' . $test->getName() . ': ' . json_encode( $locks ) ),
				$time
			);
		}
	}

	private function locks(): array {
		global $wpdb;
		$owners = new \ReflectionProperty( Order_Lock::class, 'owners' );
		$owners->setAccessible( true );
		$held = new \ReflectionProperty( Order_Lock::class, 'held' );
		$held->setAccessible( true );
		return array(
			'leases' => $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wcpos_payment_lock_' ) . '%' ) ),
			'owners' => $owners->getValue(),
			'held' => $held->getValue( Order_Lock::instance() ),
		);
	}
}
