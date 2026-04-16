<?php
/**
 * Tests for the Landing Profile service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use ReflectionMethod;
use WCPOS\WooCommercePOS\Services\Landing_Profile;
use WP_UnitTestCase;

/**
 * Tests the landing profile service.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Landing_Profile
 */
class Test_Landing_Profile extends WP_UnitTestCase {
	/**
	 * Original HPOS state.
	 *
	 * @var bool
	 */
	private $original_hpos_state;

	/**
	 * Orders created by this test.
	 *
	 * @var array<int>
	 */
	private $order_ids = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_hpos_state = OrderUtil::custom_orders_table_usage_is_enabled();
		delete_transient( Landing_Profile::TRANSIENT_KEY );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( array_reverse( $this->order_ids ) as $order_id ) {
			OrderHelper::delete_order( $order_id );
		}

		OrderHelper::toggle_cot_feature_and_usage( $this->original_hpos_state );
		delete_transient( Landing_Profile::TRANSIENT_KEY );

		parent::tearDown();
	}

	/**
	 * Counts POS orders when legacy post storage is authoritative.
	 */
	public function test_counts_pos_orders_with_legacy_storage(): void {
		OrderHelper::toggle_cot_feature_and_usage( false );

		$this->create_order_with_created_via( 'woocommerce-pos', 'completed' );
		$this->create_order_with_created_via( 'checkout', 'completed' );
		$this->create_order_with_created_via( 'woocommerce-pos', 'cancelled' );

		$this->assertSame( 1, $this->get_pos_order_count() );
	}

	/**
	 * Counts POS orders when HPOS storage is authoritative.
	 */
	public function test_counts_pos_orders_with_hpos_storage(): void {
		OrderHelper::create_order_custom_table_if_not_exist();
		OrderHelper::toggle_cot_feature_and_usage( true );

		$this->create_order_with_created_via( 'woocommerce-pos', 'processing' );
		$this->create_order_with_created_via( 'checkout', 'processing' );
		$this->create_order_with_created_via( 'woocommerce-pos', 'cancelled' );

		$this->assertSame( 1, $this->get_pos_order_count() );
	}

	/**
	 * Creates an order with the requested created_via value and status.
	 *
	 * @param string $created_via The created_via value to save.
	 * @param string $status      The order status to save.
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_created_via( string $created_via, string $status ) {
		$order = OrderHelper::create_order(
			array(
				'status' => $status,
			)
		);

		$order->set_created_via( $created_via );
		$order->set_status( $status );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return $order;
	}

	/**
	 * Invokes the storage-specific POS order count helper.
	 *
	 * @return int
	 */
	private function get_pos_order_count(): int {
		$method = new ReflectionMethod( Landing_Profile::class, 'get_pos_order_count' );
		$method->setAccessible( true );

		return (int) $method->invoke( new Landing_Profile() );
	}
}
