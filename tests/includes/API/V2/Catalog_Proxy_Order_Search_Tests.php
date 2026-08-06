<?php
/**
 * Shared V1 order-search parity probes for the v2 catalog proxy.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Tests\API\Traits\Order_Address_Scrub_Helpers;

/**
 * Shared V1 order-search parity probes.
 */
trait Catalog_Proxy_Order_Search_Tests {
	use Order_Address_Scrub_Helpers;

	/**
	 * Order targeted by the search probes.
	 *
	 * @var \WC_Order
	 */
	private $target_order;

	/**
	 * Create orders with distinct billing search fields.
	 */
	private function create_order_search_fixtures(): void {
		$this->target_order = OrderHelper::create_order();
		$this->target_order->set_billing_first_name( 'AureliaProbe' );
		$this->target_order->set_billing_last_name( 'QuillonProbe' );
		$this->target_order->set_billing_email( 'aurelia.order.probe@example.invalid' );
		// IDs share the global auto-increment: scrub numeric address fields so the
		// numeric-id LIKE search can never collide with a postcode/phone.
		$this->scrub_numeric_address_fields( $this->target_order );
		$this->target_order->save();

		$other_order = OrderHelper::create_order();
		$other_order->set_billing_first_name( 'BenedictProbe' );
		$other_order->set_billing_last_name( 'RenshawProbe' );
		$other_order->set_billing_email( 'benedict.order.probe@example.invalid' );
		$this->scrub_numeric_address_fields( $other_order );
		$other_order->save();
	}

	/**
	 * Numeric order IDs retain V1 search semantics.
	 */
	public function test_order_search_by_id_matches_v1(): void {
		$this->assert_order_search_finds_target( (string) $this->target_order->get_id() );
	}

	/**
	 * Billing first names retain V1 search semantics.
	 */
	public function test_order_search_by_billing_first_name_matches_v1(): void {
		$this->assert_order_search_finds_target( 'AureliaProbe' );
	}

	/**
	 * Billing last names retain V1 search semantics.
	 */
	public function test_order_search_by_billing_last_name_matches_v1(): void {
		$this->assert_order_search_finds_target( 'QuillonProbe' );
	}

	/**
	 * Partial billing emails retain V1 search semantics.
	 */
	public function test_order_search_by_billing_email_matches_v1(): void {
		$this->assert_order_search_finds_target( 'aurelia.order.probe' );
	}

	/**
	 * Array-valued creation channels are preserved by the proxy filter.
	 */
	public function test_created_via_array_returns_matching_orders(): void {
		$checkout_order = OrderHelper::create_order();
		$checkout_order->set_created_via( 'checkout' );
		$checkout_order->save();

		$rest_order = OrderHelper::create_order();
		$rest_order->set_created_via( 'rest-api' );
		$rest_order->save();

		$other_order = OrderHelper::create_order();
		$other_order->set_created_via( 'other' );
		$other_order->save();

		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'created_via' => array( 'checkout', 'rest-api' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing(
			array( $checkout_order->get_id(), $rest_order->get_id() ),
			wp_list_pluck( $response->get_data(), 'id' )
		);
	}

	/** Order status sorting retains V1 semantics in both directions. */
	public function test_orderby_status_matches_v1(): void {
		$pending   = $this->create_orderby_order( array( 'status' => 'pending' ) );
		$completed = $this->create_orderby_order( array( 'status' => 'completed' ) );
		$on_hold   = $this->create_orderby_order( array( 'status' => 'on-hold' ) );

		$this->assert_orderby_sequences(
			'status',
			array( $pending, $completed, $on_hold ),
			array( $completed->get_id(), $on_hold->get_id(), $pending->get_id() )
		);
	}

	/** Customer sorting retains V1 semantics in both directions. */
	public function test_orderby_customer_matches_v1(): void {
		$customer1 = CustomerHelper::create_customer();
		$customer2 = CustomerHelper::create_customer();
		$customer3 = CustomerHelper::create_customer();
		$order1     = $this->create_orderby_order( array( 'customer_id' => $customer1->get_id() ) );
		$order2     = $this->create_orderby_order( array( 'customer_id' => $customer2->get_id() ) );
		$order3     = $this->create_orderby_order( array( 'customer_id' => $customer3->get_id() ) );

		$this->assert_orderby_sequences(
			'customer_id',
			array( $order1, $order2, $order3 ),
			array( $order1->get_id(), $order2->get_id(), $order3->get_id() )
		);
	}

	/** Payment method sorting retains V1 semantics in both directions. */
	public function test_orderby_payment_method_matches_v1(): void {
		$alpha = $this->create_orderby_order();
		$alpha->set_payment_method( 'alpha' );
		$alpha->set_payment_method_title( 'alpha' );
		$alpha->save();
		$bravo = $this->create_orderby_order();
		$bravo->set_payment_method( 'bravo' );
		$bravo->set_payment_method_title( 'bravo' );
		$bravo->save();
		$charlie = $this->create_orderby_order();
		$charlie->set_payment_method( 'charlie' );
		$charlie->set_payment_method_title( 'charlie' );
		$charlie->save();

		$this->assert_orderby_sequences(
			'payment_method',
			array( $alpha, $bravo, $charlie ),
			array( $alpha->get_id(), $bravo->get_id(), $charlie->get_id() )
		);
	}

	/** Order total sorting retains V1 semantics in both directions. */
	public function test_orderby_total_matches_v1(): void {
		$low    = $this->create_orderby_order( array( 'total' => 100 ) );
		$middle = $this->create_orderby_order( array( 'total' => 200 ) );
		$high   = $this->create_orderby_order( array( 'total' => 300 ) );

		$this->assert_orderby_sequences(
			'total',
			array( $low, $middle, $high ),
			array( $low->get_id(), $middle->get_id(), $high->get_id() )
		);
	}

	/**
	 * Dispatch a real V2 request and assert that it finds the target order.
	 *
	 * @param string $search Search value.
	 */
	private function assert_order_search_finds_target( string $search ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'search' => $search ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals(
			array( $this->target_order->get_id() ),
			wp_list_pluck( $data, 'id' )
		);
	}

	/**
	 * Create a collision-safe order for an orderby probe.
	 *
	 * @param array $args Order fixture arguments.
	 *
	 * @return \WC_Order
	 */
	private function create_orderby_order( array $args = array() ) {
		$order = OrderHelper::create_order( $args );
		$this->scrub_numeric_address_fields( $order );
		$order->save();

		return $order;
	}

	/**
	 * Assert exact ascending and descending ID sequences.
	 *
	 * @param string      $orderby   Requested orderby value.
	 * @param \WC_Order[] $orders    Orders to include.
	 * @param int[]       $ascending Expected ascending IDs.
	 */
	private function assert_orderby_sequences( string $orderby, array $orders, array $ascending ): void {
		$ids = array_map(
			static function ( $order ): int {
				return $order->get_id();
			},
			$orders
		);
		foreach ( array( 'asc' => $ascending, 'desc' => array_reverse( $ascending ) ) as $order => $expected ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
			$request->set_query_params(
				array(
					'include' => $ids,
					'orderby' => $orderby,
					'order'   => $order,
				)
			);

			$response = $this->server->dispatch( $request );

			$this->assertEquals( 200, $response->get_status() );
			$this->assertEquals( $expected, wp_list_pluck( $response->get_data(), 'id' ) );
		}
	}
}
