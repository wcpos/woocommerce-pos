<?php
/**
 * Tests for receipt snapshot store.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Snapshot_Store class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Snapshot_Store extends WC_REST_Unit_Test_Case {
	/**
	 * Snapshot service.
	 *
	 * @var Receipt_Snapshot_Store
	 */
	private $store;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->store = Receipt_Snapshot_Store::instance();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( Receipt_Snapshot_Store::OPTION_SEQUENCE );
		parent::tearDown();
	}

	/**
	 * Test snapshot is persisted once and remains immutable.
	 */
	public function test_persist_snapshot_is_immutable_after_first_write(): void {
		$order = OrderHelper::create_order();

		$this->store->handle_payment_complete( $order->get_id() );
		$first_snapshot = $this->store->get_snapshot( $order->get_id() );

		$this->assertIsArray( $first_snapshot );
		$this->assertEquals( 'fiscal', $first_snapshot['meta']['mode'] );
		$this->assertNotEmpty( $first_snapshot['fiscal']['immutable_id'] );
		$this->assertNotEmpty( $first_snapshot['fiscal']['sequence'] );

		// Try to trigger another write after mutating order data.
		$order->set_customer_note( 'changed after payment' );
		$order->save();
		$this->store->handle_payment_complete( $order->get_id() );

		$second_snapshot = $this->store->get_snapshot( $order->get_id() );
		$this->assertEquals( $first_snapshot, $second_snapshot );
	}

	/**
	 * Test mode resolution prefers request mode and falls back to setting.
	 */
	public function test_resolve_mode_behavior(): void {
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'receipt_default_mode' => 'live',
			),
			false
		);

		$this->assertEquals( 'fiscal', $this->store->resolve_mode( 'fiscal' ) );
		$this->assertEquals( 'live', $this->store->resolve_mode( null ) );
	}

	/**
	 * Test fiscal sequence increments across snapshots.
	 */
	public function test_sequence_increments_for_new_snapshots(): void {
		$order_one = OrderHelper::create_order();
		$order_two = OrderHelper::create_order();

		$this->store->handle_payment_complete( $order_one->get_id() );
		$this->store->handle_payment_complete( $order_two->get_id() );

		$one = $this->store->get_snapshot( $order_one->get_id() );
		$two = $this->store->get_snapshot( $order_two->get_id() );

		$this->assertIsArray( $one );
		$this->assertIsArray( $two );
		$this->assertNotEquals( $one['fiscal']['sequence'], $two['fiscal']['sequence'] );
		$this->assertGreaterThan( $one['fiscal']['sequence'], $two['fiscal']['sequence'] );
	}

	/**
	 * Test next_sequence throws when lock acquisition fails.
	 */
	public function test_next_sequence_throws_when_lock_not_acquired(): void {
		global $wpdb;

		$original_wpdb = $wpdb;
		$wpdb          = new class() {
			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
			}

			public function get_var( $query ) {
				if ( false !== strpos( $query, 'GET_LOCK' ) ) {
					return 0;
				}

				return null;
			}
		};

		$method = new \ReflectionMethod( Receipt_Snapshot_Store::class, 'next_sequence' );
		$method->setAccessible( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to acquire receipt sequence lock' );

		try {
			$method->invoke( $this->store );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

	/**
	 * Test persist_snapshot rejects non-encodable JSON payloads.
	 */
	public function test_persist_snapshot_throws_on_json_encode_failure(): void {
		$order = OrderHelper::create_order();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to encode receipt snapshot to JSON' );

		$this->store->persist_snapshot(
			$order->get_id(),
			array(
				'meta'   => array(),
				'fiscal' => array(),
				'bad'    => INF,
			)
		);
	}
}
