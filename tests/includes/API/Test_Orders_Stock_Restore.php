<?php
/**
 * Tests for stock restoration when orders are deleted via the POS API.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\Orders_Controller;

/**
 * Tests for stock restoration on order deletion.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Orders_Stock_Restore extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Previous value of the woocommerce_manage_stock option.
	 *
	 * @var string
	 */
	private $previous_manage_stock_option;

	/**
	 * Set up test.
	 */
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Orders_Controller();

		// Enable stock management globally, preserving the original value.
		$this->previous_manage_stock_option = get_option( 'woocommerce_manage_stock', 'no' );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		update_option( 'woocommerce_manage_stock', $this->previous_manage_stock_option );
		parent::tearDown();
	}

	/**
	 * Helper to create a DELETE request.
	 *
	 * @param string $path Route path.
	 *
	 * @return \WP_REST_Request
	 */
	private function wp_rest_delete_request( $path = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( 'DELETE' );
		$request->set_route( $path );

		return $request;
	}

	/**
	 * Test that stock is restored when a completed order is trashed via the POS API.
	 */
	public function test_stock_restored_when_order_trashed(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'  => true,
				'stock_quantity' => 10,
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		$order = OrderHelper::create_order( array( 'product' => $product ) );
		$order->set_status( 'completed' );
		$order->save();

		// Reduce stock as WooCommerce would on payment complete.
		wc_maybe_reduce_stock_levels( $order->get_id() );

		// Verify stock was reduced (order has qty 4).
		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 6, $product->get_stock_quantity() );

		// Delete (trash) the order via the POS API.
		$request  = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Verify stock was restored.
		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );
	}

	/**
	 * Test that stock is restored when a completed order is force-deleted via the POS API.
	 */
	public function test_stock_restored_when_order_force_deleted(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'  => true,
				'stock_quantity' => 10,
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		$order = OrderHelper::create_order( array( 'product' => $product ) );
		$order->set_status( 'completed' );
		$order->save();

		wc_maybe_reduce_stock_levels( $order->get_id() );

		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 6, $product->get_stock_quantity() );

		// Force delete the order via the POS API.
		$request = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_param( 'force', true );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Verify stock was restored.
		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );
	}

	/**
	 * Test that stock is NOT restored when the setting is disabled.
	 */
	public function test_stock_not_restored_when_setting_disabled(): void {
		// Disable via setting.
		update_option( 'woocommerce_pos_settings_general', array( 'restore_stock_on_delete' => false ) );

		try {
			$product = ProductHelper::create_simple_product(
				array(
					'manage_stock'   => true,
					'stock_quantity' => 10,
					'regular_price'  => 10,
					'price'          => 10,
				)
			);

			$order = OrderHelper::create_order( array( 'product' => $product ) );
			$order->set_status( 'completed' );
			$order->save();

			wc_maybe_reduce_stock_levels( $order->get_id() );

			$product = wc_get_product( $product->get_id() );
			$this->assertEquals( 6, $product->get_stock_quantity() );

			// Delete the order via the POS API.
			$request  = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
			$response = $this->server->dispatch( $request );
			$this->assertEquals( 200, $response->get_status() );

			// Stock should NOT be restored.
			$product = wc_get_product( $product->get_id() );
			$this->assertEquals( 6, $product->get_stock_quantity() );
		} finally {
			delete_option( 'woocommerce_pos_settings_general' );
		}
	}

	/**
	 * Test that stock is NOT restored when the filter returns false.
	 */
	public function test_stock_not_restored_when_filter_disabled(): void {
		// Disable via filter.
		add_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_false' );

		try {
			$product = ProductHelper::create_simple_product(
				array(
					'manage_stock'  => true,
					'stock_quantity' => 10,
					'regular_price' => 10,
					'price'         => 10,
				)
			);

			$order = OrderHelper::create_order( array( 'product' => $product ) );
			$order->set_status( 'completed' );
			$order->save();

			wc_maybe_reduce_stock_levels( $order->get_id() );

			$product = wc_get_product( $product->get_id() );
			$this->assertEquals( 6, $product->get_stock_quantity() );

			// Delete the order via the POS API.
			$request  = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
			$response = $this->server->dispatch( $request );
			$this->assertEquals( 200, $response->get_status() );

			// Stock should NOT be restored.
			$product = wc_get_product( $product->get_id() );
			$this->assertEquals( 6, $product->get_stock_quantity() );
		} finally {
			remove_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_false' );
		}
	}

	/**
	 * Test that stock is not double-restored if it was never reduced.
	 */
	public function test_no_stock_change_when_stock_not_reduced(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'  => true,
				'stock_quantity' => 10,
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		// Create an order but don't reduce stock (e.g., pending order).
		$order = OrderHelper::create_order( array( 'product' => $product ) );

		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );

		// Delete the order via the POS API.
		$request  = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Stock should remain unchanged.
		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );
	}

	/**
	 * Test that stock is not double-restored when an order is trashed then force-deleted.
	 */
	public function test_stock_not_double_restored_on_trash_then_force_delete(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'  => true,
				'stock_quantity' => 10,
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		$order = OrderHelper::create_order( array( 'product' => $product ) );
		$order->set_status( 'completed' );
		$order->save();

		// Reduce stock as WooCommerce would on payment complete.
		wc_maybe_reduce_stock_levels( $order->get_id() );

		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 6, $product->get_stock_quantity() );

		// First: trash the order — stock should be restored to 10.
		$request  = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );

		// Second: force-delete the trashed order — stock should stay at 10, not go to 14.
		$request = $this->wp_rest_delete_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_param( 'force', true );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $product->get_stock_quantity() );
	}
}
