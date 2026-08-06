<?php
/**
 * Shared V1 order-search parity probes for the v2 catalog proxy.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

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
	 * Search is intersected with include and reduced by exclude, matching V1.
	 */
	public function test_order_search_combines_with_include_and_exclude(): void {
		$first_match = OrderHelper::create_order();
		$first_match->set_billing_first_name( 'SetTheoryProbe' );
		$this->scrub_numeric_address_fields( $first_match );
		$first_match->save();

		$second_match = OrderHelper::create_order();
		$second_match->set_billing_first_name( 'SetTheoryProbe' );
		$this->scrub_numeric_address_fields( $second_match );
		$second_match->save();

		$outside_search = OrderHelper::create_order();
		$outside_search->set_billing_first_name( 'OutsideSearchProbe' );
		$this->scrub_numeric_address_fields( $outside_search );
		$outside_search->save();

		$this->assertEquals(
			array( $second_match->get_id() ),
			$this->order_ids_for_query(
				array(
					'search'  => 'SetTheoryProbe',
					'include' => array( $second_match->get_id(), $outside_search->get_id() ),
				)
			)
		);
		$this->assertSame(
			array(),
			$this->order_ids_for_query(
				array(
					'search'  => 'SetTheoryProbe',
					'include' => array( $outside_search->get_id() ),
				)
			)
		);
		$this->assertEquals(
			array( $second_match->get_id() ),
			$this->order_ids_for_query(
				array(
					'search'  => 'SetTheoryProbe',
					'exclude' => array( $first_match->get_id() ),
				)
			)
		);
	}

	/**
	 * The cashier query returns only orders carrying that cashier's audit meta.
	 */
	public function test_pos_cashier_filter_returns_only_that_cashiers_orders(): void {
		$cashier_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$other_cashier_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$matching_order = OrderHelper::create_order();
		$matching_order->update_meta_data( '_pos_user', (string) $cashier_id );
		$matching_order->save();

		$other_order = OrderHelper::create_order();
		$other_order->update_meta_data( '_pos_user', (string) $other_cashier_id );
		$other_order->save();

		$this->assertEquals(
			array( $matching_order->get_id() ),
			$this->order_ids_for_query( array( 'pos_cashier' => $cashier_id ) )
		);
	}

	/**
	 * The store query returns only orders carrying that store's audit meta.
	 */
	public function test_pos_store_filter_returns_only_that_stores_orders(): void {
		$matching_order = OrderHelper::create_order();
		$matching_order->update_meta_data( '_pos_store', '314159' );
		$matching_order->save();

		$other_order = OrderHelper::create_order();
		$other_order->update_meta_data( '_pos_store', '271828' );
		$other_order->save();

		$this->assertEquals(
			array( $matching_order->get_id() ),
			$this->order_ids_for_query( array( 'pos_store' => 314159 ) )
		);
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

	/**
	 * Dispatch a real V2 request and assert that it finds the target order.
	 *
	 * @param string $search Search value.
	 */
	private function assert_order_search_finds_target( string $search ): void {
		$this->assertEquals(
			array( $this->target_order->get_id() ),
			$this->order_ids_for_query( array( 'search' => $search ) )
		);
	}

	/**
	 * Dispatch a real V2 order query and return its order IDs.
	 *
	 * @param array $query Query parameters.
	 *
	 * @return int[]
	 */
	private function order_ids_for_query( array $query ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( $query );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		return array_map( 'intval', wp_list_pluck( $data, 'id' ) );
	}
}
