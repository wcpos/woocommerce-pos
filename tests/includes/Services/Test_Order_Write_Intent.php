<?php
/**
 * REST order-write subject and intent coverage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Order_Write_Intent;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

/**
 * Real writes, with the current lane exercised before legacy request hooks exist.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Order_Write_Intent
 */
class Test_Order_Write_Intent extends Sync_REST_Store_Test_Case {
	/**
	 * Original physical POS header.
	 *
	 * @var mixed
	 */
	private $wcpos_header;
	/**
	 * Original checkout settings.
	 *
	 * @var mixed
	 */
	private $checkout_settings;
	/**
	 * Process-local intent state, reset between HTTP simulations.
	 *
	 * @var \ReflectionProperty
	 */
	private $intent_stack;

	/** Enable overselling prevention before REST route schemas are captured. */
	public function setUp(): void {
		$this->checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => true ) );
		parent::setUp();
		$this->wcpos_header       = $_SERVER['HTTP_X_WCPOS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the test harness header verbatim.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$this->intent_stack = new \ReflectionProperty( Order_Write_Intent::class, 'stack' );
		$this->intent_stack->setAccessible( true );
		$this->intent_stack->setValue( null, array() );
	}

	/** Restore process-local state and the pre-transaction settings. */
	public function tearDown(): void {
		$this->intent_stack->setValue( null, array() );
		if ( null === $this->wcpos_header ) {
			unset( $_SERVER['HTTP_X_WCPOS'] );
		} else {
			$_SERVER['HTTP_X_WCPOS'] = $this->wcpos_header;
		}
		parent::tearDown();
		if ( false === $this->checkout_settings ) {
			delete_option( 'woocommerce_pos_settings_checkout' );
		} else {
			update_option( 'woocommerce_pos_settings_checkout', $this->checkout_settings );
		}
	}

	/** Capturing the neutralised request instead of the declaration loses the sale intent. */
	public function test_paid_create_publishes_the_clients_intent_under_stock_neutralisation(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
		$observed = array();
		$observer = static function ( $order, $request ) use ( &$observed ) {
			$intent = Order_Write_Intent::current();
			$observed[] = array( $intent->requested_status(), $intent->set_paid(), $intent->is_create(), $intent->is_subject( $order ), $request->get_param( 'status' ) );
			return $order;
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10, 2 );
		try {
			foreach ( array( '/wcpos/v2/push/orders', '/wcpos/v1/orders' ) as $route ) {
				// Act.
				$response = $this->create_order(
					$route,
					array(
						'status' => 'completed',
						'set_paid' => true,
						'line_items' => array(
							array(
								'product_id' => $product->get_id(),
								'quantity' => 1,
							),
						),
					)
				);
				// Assert.
				$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
				$this->assertSame( array( array( 'completed', true, true, true, 'pending' ) ), $observed );
				$data = $response->get_data();
				$this->assertSame( 'completed', wc_get_order( $data['document']['id'] ?? $data['id'] )->get_status() );
				$this->assertNull( Order_Write_Intent::current() );
				$observed = array();
			}
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10 );
		}
	}

	/** Direct POS wc/v3 writes publish; non-POS writes must not replace the observation. */
	public function test_direct_wc3_update_publishes_an_ad_hoc_intent(): void {
		// Arrange.
		$order = wc_create_order();
		$observed = array();
		$observer = static function ( $prepared_order ) use ( &$observed ) {
			$intent = Order_Write_Intent::current();
			$observed[] = array( $intent, $intent->operation(), $intent->id(), $intent->requested_status(), $intent->is_subject( $prepared_order ) );
			return $prepared_order;
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10 );
		try {
			// Act.
			$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order->get_id() );
			$request->set_method( 'PATCH' );
			$request->set_body_params( array( 'status' => 'pos-open' ) );
			$response = $this->server->dispatch( $request );
			// Assert.
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$intent = Order_Write_Intent::current();
			$this->assertSame( array( array( $intent, 'update', $order->get_id(), 'pos-open', true ) ), $observed );

			// Act. A new REST request loads a distinct order object, but publishes nothing.
			unset( $_SERVER['HTTP_X_WCPOS'] );
			$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $order->get_id() );
			$request->set_method( 'PATCH' );
			$request->remove_header( 'X-WCPOS' );
			$request->set_body_params( array( 'status' => 'pos-open' ) );
			$response = $this->server->dispatch( $request );
			// Assert. The last ad-hoc intent remains current; strict subject identity rejects reuse.
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( array( $intent, 'update', $order->get_id(), 'pos-open', false ), $observed[1] );
			$this->assertCount( 2, $observed );
			$this->assertSame( $intent, Order_Write_Intent::current() );
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10 );
		}
	}

	/** Rebinding to a nested create loses the outer subject and its created-via stamp. */
	public function test_nested_order_create_inside_a_pos_create_is_not_the_subject(): void {
		// Arrange.
		$observed = array();
		$nested = false;
		$observer = function ( $order ) use ( &$observed, &$nested ) {
			$observed[] = Order_Write_Intent::current()->is_subject( $order );
			if ( ! $nested ) {
				$nested = true;
				$request = $this->wp_rest_post_request( '/wc/v3/orders' );
				$request->remove_header( 'X-WCPOS' );
				$request->set_body_params( array( 'status' => 'pending' ) );
				$response = $this->server->dispatch( $request );
				$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
			}
			return $order;
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10 );
		try {
			foreach ( array( '/wcpos/v2/push/orders', '/wcpos/v1/orders' ) as $route ) {
				// Act.
				$response = $this->create_order( $route, array( 'status' => 'pending' ) );
				// Assert.
				$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
				$this->assertSame( array( true, false ), $observed );
				$data = $response->get_data();
				$this->assertSame( 'woocommerce-pos', wc_get_order( $data['document']['id'] ?? $data['id'] )->get_created_via() );
				$this->assertNull( Order_Write_Intent::current() );
				$observed = array();
				$nested = false;
			}
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observer, 10 );
		}
	}

	/**
	 * Dispatch a real create, using the push envelope on v2.
	 *
	 * @param string $route   REST route.
	 * @param array  $payload Order document.
	 * @return \WP_REST_Response
	 */
	private function create_order( string $route, array $payload ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( $route );
		if ( '/wcpos/v2/push/orders' === $route ) {
			$payload = array(
				'mutationId' => wp_generate_uuid4(),
				'operation' => 'create',
				'collection' => 'orders',
				'recordId' => wp_generate_uuid4(),
				'baseRevision' => null,
				'payload' => $payload,
			);
		}
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		return $this->server->dispatch( $request );
	}
}
