<?php
/**
 * HPOS coverage for the decimal-quantity order write contract (issue #1389).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WP_REST_Response;

/**
 * The fractional line-item quantity round trip holds when orders live in the
 * HPOS tables — same relaxation, storage-sensitive write path.
 */
class Test_Decimal_Quantity_Write_Contract_HPOS extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable the v2 sync routes, POS request marker, and HPOS.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage and drop the POS request marker.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Dispatch one mutation envelope through the registered v2 push route.
	 *
	 * @param array $envelope Mutation envelope.
	 */
	private function dispatch_order_mutation( array $envelope ): WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * With decimal_qty on, a POS order create and update carry fractional
	 * line-item quantities into the HPOS record.
	 */
	public function test_hpos_order_line_item_decimal_quantity_round_trip(): void {
		$this->setup_decimal_quantity_tests();
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 10 ) );

		$created = $this->dispatch_order_mutation(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001397',
				'operation'    => 'create',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000001397',
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
		$order_id = (int) $document['id'];
		$this->assert_order_record_existence( $order_id, true, true );
		$this->assertEquals( 0.5, $document['line_items'][0]['quantity'] );
		$items = array_values( wc_get_order( $order_id )->get_items( 'line_item' ) );
		$this->assertEquals( 0.5, $items[0]->get_quantity() );

		$updated = $this->dispatch_order_mutation(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000001398',
				'operation'    => 'update',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000001397',
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
}
