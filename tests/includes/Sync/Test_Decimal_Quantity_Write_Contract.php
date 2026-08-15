<?php
/**
 * Decimal quantity coverage for the v2 write contract (issue #1389).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Pins the decimal-quantity round trips through the real v2 push routes:
 * with decimal_qty enabled, order line-item quantities and product/variation
 * stock_quantity accept fractions end-to-end; with it disabled — or on a
 * direct non-POS wc/v3 request — WooCommerce's strict integer schema stays
 * in force (Services\Decimal_Quantities gating).
 */
class Test_Decimal_Quantity_Write_Contract extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the v2 sync routes and mark requests as POS requests.
	 */
	public function setUp(): void {
		parent::setUp();
		// wcpos_request() reads the transport headers, not WP_REST_Request headers.
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/**
	 * Drop the POS request marker (hook state is restored by the WP test case).
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/**
	 * Dispatch one mutation envelope through the registered v2 push route.
	 *
	 * @param string $collection Push collection.
	 * @param array  $envelope   Mutation envelope.
	 */
	private function dispatch_mutation( string $collection, array $envelope ): WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Compute the baseRevision the write path's conflict check derives for a product.
	 *
	 * @param int $product_id Product id.
	 */
	private function product_revision( int $product_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id ) );

		return Revision::compute( Meta_Normalizer::normalize( $response->get_data() ) );
	}

	/**
	 * A stock-managed variation carrying a POS uuid, ready for a push update.
	 *
	 * @return array{0: WC_Product_Variation, 1: string} Variation and its uuid.
	 */
	private function stock_managed_variation(): array {
		$parent    = ProductHelper::create_variation_product();
		$variation = wc_get_product( $parent->get_children()[0] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 10 );
		$variation->save();
		$uuid = wp_generate_uuid4();
		update_post_meta( $variation->get_id(), Pos_Uuid::META_KEY, $uuid );

		return array( wc_get_product( $variation->get_id() ), $uuid );
	}

	/**
	 * Compute the baseRevision the write path derives for a variation — the
	 * serialized payload's date_modified_gmt (see Write_Controller::revision_for).
	 *
	 * @param int $variation_id Variation id.
	 */
	private function variation_revision( int $variation_id ): string {
		$variation  = wc_get_product( $variation_id );
		$controller = new \WC_REST_Products_Controller();
		$response   = rest_ensure_response( $controller->prepare_object_for_response( $variation, new WP_REST_Request( 'GET', '/' ) ) );
		$payload    = rest_get_server()->response_to_data( $response, false );

		return (string) ( $payload['date_modified_gmt'] ?? $variation_id );
	}

	/**
	 * With decimal_qty on, a POS order create and update carry fractional line-item quantities end-to-end.
	 */
	public function test_order_line_item_decimal_quantity_round_trip(): void {
		$this->setup_decimal_quantity_tests();
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 10 ) );

		$created = $this->dispatch_mutation(
			'orders',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001391',
				'operation'    => 'create',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000001391',
				'baseRevision' => null,
				'payload'      => array(
					'status'     => 'pending',
					'line_items' => array(
						array(
							'product_id' => $product->get_id(),
							'quantity'   => 0.5,
						),
					),
				),
			)
		);

		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document = $created->get_data()['document'];
		$this->assertEquals( 0.5, $document['line_items'][0]['quantity'] );
		$order_id = (int) $document['id'];
		$items    = array_values( wc_get_order( $order_id )->get_items( 'line_item' ) );
		$this->assertEquals( 0.5, $items[0]->get_quantity() );

		$updated = $this->dispatch_mutation(
			'orders',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001392',
				'operation'    => 'update',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000001391',
				'baseRevision' => $created->get_data()['currentRevision'],
				'payload'      => array(
					'line_items' => array(
						array(
							'id'       => (int) $document['line_items'][0]['id'],
							'quantity' => 1.5,
						),
					),
				),
			)
		);

		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$items = array_values( wc_get_order( $order_id )->get_items( 'line_item' ) );
		$this->assertEquals( 1.5, $items[0]->get_quantity() );
	}

	/**
	 * With decimal_qty on, a POS product update stores a fractional stock_quantity.
	 */
	public function test_product_decimal_stock_quantity_round_trip(): void {
		$this->setup_decimal_quantity_tests();
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'regular_price'  => 10,
			)
		);
		$uuid = wp_generate_uuid4();
		update_post_meta( $product->get_id(), Pos_Uuid::META_KEY, $uuid );

		$response = $this->dispatch_mutation(
			'products',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001393',
				'operation'    => 'update',
				'collection'   => 'products',
				'recordId'     => $uuid,
				'baseRevision' => $this->product_revision( $product->get_id() ),
				'payload'      => array( 'stock_quantity' => 0.5 ),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( 0.5, $response->get_data()['document']['stock_quantity'] );
		$this->assertEquals( 0.5, wc_get_product( $product->get_id() )->get_stock_quantity() );
	}

	/**
	 * With decimal_qty on, a POS variation update stores a fractional stock_quantity.
	 */
	public function test_variation_decimal_stock_quantity_round_trip(): void {
		$this->setup_decimal_quantity_tests();
		list( $variation, $uuid ) = $this->stock_managed_variation();

		$response = $this->dispatch_mutation(
			'variations',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001394',
				'operation'    => 'update',
				'collection'   => 'variations',
				'recordId'     => $uuid,
				'baseRevision' => $this->variation_revision( $variation->get_id() ),
				'payload'      => array( 'stock_quantity' => 0.5 ),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( 0.5, $response->get_data()['document']['payload']['stock_quantity'] );
		$this->assertEquals( 0.5, wc_get_product( $variation->get_id() )->get_stock_quantity() );
	}

	/**
	 * With decimal_qty off, the strict integer schema still rejects fractional quantities.
	 */
	public function test_decimal_quantities_rejected_when_setting_disabled(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 10 ) );

		$order_push = $this->dispatch_mutation(
			'orders',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001395',
				'operation'    => 'create',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000001395',
				'baseRevision' => null,
				'payload'      => array(
					'status'     => 'pending',
					'line_items' => array(
						array(
							'product_id' => $product->get_id(),
							'quantity'   => 0.5,
						),
					),
				),
			)
		);

		$this->assertSame( 400, $order_push->get_status(), wp_json_encode( $order_push->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $order_push->get_data()['code'] );

		// Valid baseRevision so the push clears the conflict check and the 400
		// provably comes from the strict wc/v3 schema on the forward.
		list( $variation, $uuid ) = $this->stock_managed_variation();
		$variation_push           = $this->dispatch_mutation(
			'variations',
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001396',
				'operation'    => 'update',
				'collection'   => 'variations',
				'recordId'     => $uuid,
				'baseRevision' => $this->variation_revision( $variation->get_id() ),
				'payload'      => array( 'stock_quantity' => 0.5 ),
			)
		);

		$this->assertSame( 400, $variation_push->get_status(), wp_json_encode( $variation_push->get_data() ) );
	}

	/**
	 * A POS request does not relax the separate refund controller's quantity schema.
	 */
	public function test_refund_line_item_quantity_schema_stays_integer_with_setting_enabled(): void {
		$this->setup_decimal_quantity_tests();
		$route     = '/wc/v3/orders/(?P<order_id>[\d]+)/refunds';
		$endpoints = array(
			$route => array(
				array(
					'args' => array(
						'line_items' => array(
							'items' => array(
								'properties' => array(
									'quantity' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
			),
		);

		$filtered = apply_filters( 'rest_endpoints', $endpoints );

		$this->assertSame( 'integer', $filtered[ $route ][0]['args']['line_items']['items']['properties']['quantity']['type'] );
	}

	/**
	 * A non-POS wc/v3 request keeps the strict integer schema even with decimal_qty on.
	 */
	public function test_direct_wc_request_keeps_strict_schema_with_setting_enabled(): void {
		$this->setup_decimal_quantity_tests();
		unset( $_SERVER['HTTP_X_WCPOS'] ); // not a POS request.
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 10 ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 0.5,
					),
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}
}
