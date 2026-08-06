<?php
/**
 * Route-dispatch pins for full-document v2 order re-push idempotency (#1456).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WCPOS\WooCommercePOS\Sync\Api;
use WP_REST_Response;

/**
 * Record whether order-item lookups allow the item to load from storage.
 */
class Tracking_Order extends WC_Order {
	/**
	 * Load-from-database arguments passed to get_item().
	 *
	 * @var bool[]
	 */
	public static $get_item_load_from_db = array();

	/**
	 * Record the load mode before delegating to WooCommerce.
	 *
	 * @param int  $item_id      Order item ID.
	 * @param bool $load_from_db Whether to load the item from the database.
	 * @return \WC_Order_Item|false
	 */
	public function get_item( $item_id, $load_from_db = true ) {
		self::$get_item_load_from_db[] = $load_from_db;
		return parent::get_item( $item_id, $load_from_db );
	}
}

/**
 * Re-pushes acknowledged order documents verbatim through the real v2 route.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Repush_Idempotency extends Sync_REST_Store_Test_Case {
	/**
	 * Stable client identity used by every push in one test transaction.
	 */
	private const RECORD_UUID = '57000000-0000-4000-8000-000000000001';

	/**
	 * Sequence used for unique mutation IDs.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Enable the v2 push route with tax disabled so the pin isolates re-push behavior.
	 *
	 * @return void
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	/**
	 * Restore request state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Dispatch one create or update through the registered v2 order route.
	 *
	 * @param string      $operation     Mutation operation.
	 * @param array       $payload       Full order document.
	 * @param string|null $base_revision Current server revision for an update.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( string $operation, array $payload, ?string $base_revision = null ): WP_REST_Response {
		$envelope = array(
			'mutationId'   => sprintf( '58000000-0000-4000-8000-%012d', ++$this->sequence ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::RECORD_UUID,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Build a line, fee, and shipping document, optionally with one coupon.
	 *
	 * @param string|null $coupon_code Coupon code to include.
	 *
	 * @return array
	 */
	private function create_payload( ?string $coupon_code = null ): array {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'tax_status'    => 'none',
			)
		);
		$payload = array(
			'status'         => 'pending',
			'line_items'     => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 1,
					'subtotal'   => '10',
					'total'      => '10',
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_data',
							'value' => wp_json_encode(
								array(
									'price'         => '10',
									'regular_price' => '10',
									'tax_status'    => 'none',
								)
							),
						),
					),
				),
			),
			'fee_lines'      => array(
				array(
					'name'       => 'Handling',
					'tax_status' => 'none',
					'total'      => '2',
				),
			),
			'shipping_lines' => array(
				array(
					'method_title' => 'Local pickup',
					'method_id'    => 'local_pickup',
					'total'        => '3',
				),
			),
		);
		if ( null !== $coupon_code ) {
			$payload['coupon_lines'] = array( array( 'code' => $coupon_code ) );
		}
		return $payload;
	}

	/**
	 * Remove fields that are expected to change on an otherwise identical save.
	 *
	 * The display_key/display_value twins are derived display data whose presence flips
	 * with the STORED meta value type — the first re-push legitimately settles
	 * `_woocommerce_pos_data` from the historical JSON string to the typed
	 * array (a shape Orders::order_item_product explicitly supports), which
	 * regenerates the derived twins. Money and structure stay byte-compared.
	 *
	 * @param array $document Serialized order document.
	 *
	 * @return array
	 */
	private function without_volatile_fields( array $document ): array {
		foreach ( $document as $key => $value ) {
			if ( \in_array( $key, array( 'date_modified', 'date_modified_gmt', 'display_key', 'display_value' ), true ) ) {
				unset( $document[ $key ] );
			} elseif ( \is_array( $value ) ) {
				$document[ $key ] = $this->without_volatile_fields( $value );
			}
		}
		return $document;
	}

	/**
	 * Assert collection counts and every stable serialized byte are unchanged.
	 *
	 * @param array $before Earlier acknowledged document.
	 * @param array $after  Later acknowledged document.
	 *
	 * @return void
	 */
	private function assert_document_stable( array $before, array $after ): void {
		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines' ) as $collection ) {
			$this->assertEquals( \count( $before[ $collection ] ), \count( $after[ $collection ] ), $collection . ' count changed on re-push.' );
		}
		$this->assertEquals(
			wp_json_encode( $this->without_volatile_fields( $before ) ),
			wp_json_encode( $this->without_volatile_fields( $after ) ),
			'The serialized document changed beyond date_modified fields.'
		);
	}

	/**
	 * Assert persisted item counts after the final push.
	 *
	 * @param WC_Order $order                Persisted order.
	 * @param int      $expected_coupon_count Expected coupon count.
	 *
	 * @return void
	 */
	private function assert_persisted_counts( WC_Order $order, int $expected_coupon_count ): void {
		$this->assertCount( 1, $order->get_items( 'line_item' ) );
		$this->assertCount( 1, $order->get_items( 'fee' ) );
		$this->assertCount( 1, $order->get_items( 'shipping' ) );
		$this->assertCount( $expected_coupon_count, $order->get_items( 'coupon' ) );
	}

	/**
	 * Count one variation attribute key in a serialized order line.
	 *
	 * @param array  $document Serialized order document.
	 * @param string $key      Item meta key.
	 *
	 * @return int
	 */
	private function line_item_meta_count( array $document, string $key ): int {
		return count(
			array_filter(
				$document['line_items'][0]['meta_data'] ?? array(),
				static fn( array $meta ): bool => $key === ( $meta['key'] ?? null )
			)
		);
	}

	/**
	 * The acknowledgement can be re-pushed twice without changing lines or money bytes.
	 *
	 * @return void
	 */
	public function test_full_ack_document_repush_twice_preserves_item_counts_and_money_bytes(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertEquals( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		// Act.
		$first = $this->push_order( 'update', $created->get_data()['document'], $created->get_data()['currentRevision'] );
		$this->assertEquals( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$second = $this->push_order( 'update', $first->get_data()['document'], $first->get_data()['currentRevision'] );
		// Assert.
		$this->assertEquals( 200, $second->get_status(), wp_json_encode( $second->get_data() ) );
		$this->assert_document_stable( $created->get_data()['document'], $first->get_data()['document'] );
		$this->assert_document_stable( $first->get_data()['document'], $second->get_data()['document'] );
		$order = wc_get_order( (int) $second->get_data()['document']['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assert_persisted_counts( $order, 0 );
	}


	/**
	 * A couponed acknowledgement can be re-pushed twice without reapplying or dropping its discount.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Sync\Test_Rest_Dispatch_Coupon_Lines::test_update_with_acked_coupon_line_ids_succeeds_and_preserves_the_line()
	 *
	 * @return void
	 */
	public function test_couponed_full_ack_repush_twice_preserves_coupon_line_and_discount_bytes(): void {
		// Arrange.
		CouponHelper::create_coupon(
			'push-idempotent-coupon',
			'publish',
			array(
				'discount_type' => 'fixed_cart',
				'coupon_amount' => '1',
			)
		);
		$created = $this->push_order( 'create', $this->create_payload( 'push-idempotent-coupon' ) );
		$this->assertEquals( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		// Act.
		$first = $this->push_order( 'update', $created->get_data()['document'], $created->get_data()['currentRevision'] );
		$this->assertEquals( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$second = $this->push_order( 'update', $first->get_data()['document'], $first->get_data()['currentRevision'] );
		// Assert.
		$this->assertEquals( 200, $second->get_status(), wp_json_encode( $second->get_data() ) );
		$this->assert_document_stable( $created->get_data()['document'], $first->get_data()['document'] );
		$this->assert_document_stable( $first->get_data()['document'], $second->get_data()['document'] );
		$this->assertEquals( 1.00, round( (float) $created->get_data()['document']['discount_total'], 2 ) );
		$this->assertEquals( $created->get_data()['document']['discount_total'], $second->get_data()['document']['discount_total'] );
		$this->assertEquals( $created->get_data()['document']['total'], $second->get_data()['document']['total'] );
		$this->assertEquals( $created->get_data()['document']['coupon_lines'][0]['id'], $second->get_data()['document']['coupon_lines'][0]['id'] );
		$order = wc_get_order( (int) $second->get_data()['document']['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assert_persisted_counts( $order, 1 );
		$this->assertEquals( 14.00, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * Count meta entries carrying one key on the document's first line item.
	 *
	 * @param array  $document Serialized order document.
	 * @param string $meta_key Meta key to count.
	 *
	 * @return int
	 */
	private function line_item_meta_count( array $document, string $meta_key ): int {
		$count = 0;
		foreach ( $document['line_items'][0]['meta_data'] as $meta ) {
			if ( $meta_key === $meta['key'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * A variation acknowledgement does not duplicate its pa_* line-item meta.
	 *
	 * @return void
	 */
	public function test_variable_product_ack_repush_twice_preserves_variation_attribute_meta_count(): void {
		// Arrange.
		$order_class_filter = static function (): string {
			return Tracking_Order::class;
		};
		add_filter( 'woocommerce_order_class', $order_class_filter );
		Tracking_Order::$get_item_load_from_db = array();

		try {
			$parent       = ProductHelper::create_variation_product();
			$variation_id = $parent->get_children()[0];
			$variation    = wc_get_product( $variation_id );
			$variation->set_regular_price( '10' );
			$variation->set_price( '10' );
			$variation->set_tax_status( 'none' );
			$variation->save();
			$payload = array(
				'status'     => 'pending',
				'line_items' => array(
					array(
						'product_id'   => $parent->get_id(),
						'variation_id' => $variation_id,
						'quantity'     => 1,
						'subtotal'     => '10',
						'total'        => '10',
					),
				),
			);

			$created = $this->push_order( 'create', $payload );
			$this->assertEquals( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );

			// Act.
			$first = $this->push_order( 'update', $created->get_data()['document'], $created->get_data()['currentRevision'] );
			$this->assertEquals( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
			$second = $this->push_order( 'update', $first->get_data()['document'], $first->get_data()['currentRevision'] );

			// Assert.
			$this->assertEquals( 200, $second->get_status(), wp_json_encode( $second->get_data() ) );
			$this->assertSame( 1, $this->line_item_meta_count( $created->get_data()['document'], 'pa_size' ) );
			$this->assertSame( 1, $this->line_item_meta_count( $first->get_data()['document'], 'pa_size' ) );
			$this->assertSame( 1, $this->line_item_meta_count( $second->get_data()['document'], 'pa_size' ) );
			// The identity-drop guard resolved the stored item on every update —
			// an empty recording would mean get_item() was never exercised.
			$this->assertNotEmpty( Tracking_Order::$get_item_load_from_db );
			$this->assertNotContains( false, Tracking_Order::$get_item_load_from_db );
		} finally {
			remove_filter( 'woocommerce_order_class', $order_class_filter );
		}
	}
}
