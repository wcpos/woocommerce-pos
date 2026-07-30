<?php
/**
 * Route-dispatch pins for line-item product identity on the v2 push (issue #1403 row 1).
 *
 * Stock wc/v3 prefers a posted line `sku` over the posted `product_id` and throws
 * `woocommerce_rest_required_product_reference` when both are empty on create — but
 * the POS client sends `sku` on every line and uses product_id 0 for misc/custom
 * products. v1 overrode `get_product_id` (trust the ids, pass product_id 0 through)
 * and `maybe_set_item_meta_data` (`_sku` meta for misc lines). These pins drive the
 * REAL registered route (`POST /wcpos/v2/push/orders`, real inner wc/v3 dispatch,
 * real Mutation_Store) and assert the v1 semantics on v2.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Line_Item_Identity extends Sync_REST_Store_Test_Case {
	private const REC = '8e1b4d6f-5a7c-4d9e-8fb0-4a5b6c7d8e9f';
	private const LINE_UUID = '77777777-8888-4999-8aaa-bbbbccccdddd';
	private $mutation_seq = 0;

	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	private function push_envelope( string $operation, array $payload, $base_revision = null ) {
		$envelope = array(
			'mutationId'   => sprintf( 'd4e5f6a7-5555-4666-8777-%012d', ++$this->mutation_seq ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function order_revision( int $order_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id ) );
		$data     = Meta_Normalizer::normalize( $response->get_data() );
		$data     = Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );

		return Order_Serializer::canonical_revision( $data );
	}

	private function misc_line( array $over = array() ): array {
		return array_merge(
			array(
				'product_id' => 0,
				'name'       => 'Misc Item',
				'quantity'   => 1,
				'subtotal'   => '5.00',
				'total'      => '5.00',
				'meta_data'  => array(
					array(
						'key'   => '_woocommerce_pos_uuid',
						'value' => self::LINE_UUID,
					),
					array(
						'key'   => '_woocommerce_pos_data',
						'value' => array(
							'price'         => '5.00',
							'regular_price' => '5.00',
							'tax_status'    => 'taxable',
						),
					),
				),
			),
			$over
		);
	}

	private function order_meta(): array {
		return array(
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => self::REC,
			),
		);
	}

	private function single_line( int $order_id ): \WC_Order_Item_Product {
		$items = array_values( wc_get_order( $order_id )->get_items() );
		$this->assertCount( 1, $items );

		return $items[0];
	}

	public function test_misc_product_without_sku_creates_through_v2_push(): void {
		// Was 400 woocommerce_rest_required_product_reference before the fix.
		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array( $this->misc_line() ),
				'meta_data'  => $this->order_meta(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$line = $this->single_line( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 0, $line->get_product_id() );
		$this->assertSame( 'Misc Item', $line->get_name() );
		$this->assertSame( self::LINE_UUID, $line->get_meta( '_woocommerce_pos_uuid', true ) );
		// No typed sku → no _sku meta, and the lookup sentinel never persists.
		$this->assertSame( '', (string) $line->get_meta( '_sku', true ) );
	}

	public function test_misc_product_with_colliding_sku_keeps_product_id_zero_and_sku_meta(): void {
		$real = ProductHelper::create_simple_product(
			array(
				'regular_price' => 99,
				'price'         => 99,
			)
		);
		$real->set_sku( 'COLLIDE-PIN-1' );
		$real->save();

		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array( $this->misc_line( array( 'sku' => 'COLLIDE-PIN-1' ) ) ),
				'meta_data'  => $this->order_meta(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$line = $this->single_line( (int) $response->get_data()['document']['id'] );
		// Was silently bound to the catalog product (and its price) before the fix.
		$this->assertSame( 0, $line->get_product_id() );
		$this->assertSame( '5.00', $line->get_subtotal() );
		// The typed sku survives as _sku meta — the synthetic-product read path
		// (Orders::order_item_product) serves it on receipts/re-reads, v1 parity.
		$this->assertSame( 'COLLIDE-PIN-1', $line->get_meta( '_sku', true ) );
	}

	public function test_real_product_line_ignores_a_foreign_sku(): void {
		$a = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$a->set_sku( 'PIN-PROD-A' );
		$a->save();
		$b = ProductHelper::create_simple_product(
			array(
				'regular_price' => 20,
				'price'         => 20,
			)
		);
		$b->set_sku( 'PIN-PROD-B' );
		$b->save();

		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $a->get_id(),
						'quantity'   => 1,
						'sku'        => 'PIN-PROD-B',
						'meta_data'  => array(
							array(
								'key'   => '_woocommerce_pos_uuid',
								'value' => self::LINE_UUID,
							),
						),
					),
				),
				'meta_data'  => $this->order_meta(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$line = $this->single_line( (int) $response->get_data()['document']['id'] );
		// Was bound to product B (and priced from it) before the fix.
		$this->assertSame( $a->get_id(), $line->get_product_id() );
		$this->assertSame( '10', wc_format_decimal( $line->get_subtotal(), 0 ) );
	}

	public function test_misc_line_survives_a_sentinel_sku_catalog_collision(): void {
		// A merchant CAN assign the literal sentinel to a catalog product;
		// the forward must detect the collision and still keep the line misc.
		$squatter = ProductHelper::create_simple_product(
			array(
				'regular_price' => 42,
				'price'         => 42,
			)
		);
		$squatter->set_sku( 'wcpos-misc-item-no-sku-lookup' );
		$squatter->save();

		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array( $this->misc_line() ),
				'meta_data'  => $this->order_meta(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$line = $this->single_line( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 0, $line->get_product_id() );
		$this->assertSame( '5.00', $line->get_subtotal() );
	}

	public function test_partial_update_line_without_product_id_cannot_be_rebound_by_sku(): void {
		$a = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$b = ProductHelper::create_simple_product(
			array(
				'regular_price' => 20,
				'price'         => 20,
			)
		);
		$b->set_sku( 'REBIND-TARGET' );
		$b->save();
		$created = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $a->get_id(),
						'quantity'   => 1,
						'meta_data'  => array(
							array(
								'key'   => '_woocommerce_pos_uuid',
								'value' => self::LINE_UUID,
							),
						),
					),
				),
				'meta_data'  => $this->order_meta(),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$order_id = (int) $created->get_data()['document']['id'];
		$line_id  = (int) $created->get_data()['document']['line_items'][0]['id'];

		// Partial update: no product_id, foreign sku — v1 stripped the sku for
		// every non-misc shape; the stored line's product must win.
		$updated = $this->push_envelope(
			'update',
			array(
				'line_items' => array(
					array(
						'id'       => $line_id,
						'quantity' => 2,
						'sku'      => 'REBIND-TARGET',
					),
				),
				'meta_data'  => $this->order_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $updated->get_status() );
		$line = $this->single_line( $order_id );
		$this->assertSame( $a->get_id(), $line->get_product_id() );
		$this->assertSame( 2, $line->get_quantity() );
	}

	public function test_misc_line_sku_clear_updates_the_sku_meta(): void {
		$created = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array( $this->misc_line( array( 'sku' => 'MISC-CLEAR-1' ) ) ),
				'meta_data'  => $this->order_meta(),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$order_id = (int) $created->get_data()['document']['id'];
		$line_id  = (int) $created->get_data()['document']['line_items'][0]['id'];
		$this->assertSame( 'MISC-CLEAR-1', $this->single_line( $order_id )->get_meta( '_sku', true ) );

		// An explicit sku clear on the misc line clears the meta too (v1's
		// maybe_set_item_meta_data replaced _sku with the posted value, '' included).
		$updated = $this->push_envelope(
			'update',
			array(
				'line_items' => array(
					array(
						'id'         => $line_id,
						'product_id' => 0,
						'sku'        => '',
					),
				),
				'meta_data'  => $this->order_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( '', (string) $this->single_line( $order_id )->get_meta( '_sku', true ) );
	}

	public function test_non_numeric_product_id_is_not_coerced_into_a_misc_line(): void {
		$real = ProductHelper::create_simple_product(
			array(
				'regular_price' => 33,
				'price'         => 33,
			)
		);
		$real->set_sku( 'BOGUS-BAIT-1' );
		$real->save();

		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array(
					$this->misc_line(
						array(
							'product_id' => 'bogus',
							'sku'        => 'BOGUS-BAIT-1',
						)
					),
				),
				'meta_data'  => $this->order_meta(),
			)
		);

		// Not misc → the sku is stripped (no catalog rebinding) and no misc
		// artifacts are injected; wc/v3 coerces the malformed reference to a
		// product-less line, exactly as it would for any non-POS client.
		$this->assertSame( 201, $response->get_status() );
		$line = $this->single_line( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 0, $line->get_product_id() );
		$this->assertSame( '5.00', $line->get_subtotal() );
		$this->assertSame( '', (string) $line->get_meta( '_sku', true ) );
	}

	public function test_misc_line_survives_a_quantity_update_and_null_as_delete_still_works(): void {
		$created = $this->push_envelope(
			'create',
			array(
				'status'     => 'processing',
				'line_items' => array( $this->misc_line( array( 'sku' => 'MISC-KEEP-1' ) ) ),
				'meta_data'  => $this->order_meta(),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$order_id = (int) $created->get_data()['document']['id'];
		$line_id  = (int) $created->get_data()['document']['line_items'][0]['id'];

		// Quantity update on the misc line, client shape (sku + uuid meta re-sent).
		$updated = $this->push_envelope(
			'update',
			array(
				'line_items' => array(
					array(
						'id'         => $line_id,
						'product_id' => 0,
						'sku'        => 'MISC-KEEP-1',
						'quantity'   => 3,
						'subtotal'   => '15.00',
						'total'      => '15.00',
						'meta_data'  => array(
							array(
								'key'   => '_woocommerce_pos_uuid',
								'value' => self::LINE_UUID,
							),
						),
					),
				),
				'meta_data'  => $this->order_meta(),
			),
			$this->order_revision( $order_id )
		);
		$this->assertSame( 200, $updated->get_status() );
		$line = $this->single_line( $order_id );
		$this->assertSame( 3, $line->get_quantity() );
		$this->assertSame( 0, $line->get_product_id() );
		$this->assertSame( self::LINE_UUID, $line->get_meta( '_woocommerce_pos_uuid', true ) );

		// wc/v3's null-as-delete marker must remain untouched by the normalization.
		$removed = $this->push_envelope(
			'update',
			array(
				'line_items' => array(
					array(
						'id'         => $line_id,
						'product_id' => null,
					),
				),
				'meta_data'  => $this->order_meta(),
			),
			$this->order_revision( $order_id )
		);
		$this->assertSame( 200, $removed->get_status() );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}
}
