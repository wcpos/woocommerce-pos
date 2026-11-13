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
use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
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
	}

	/**
	 * Dispatch one create or update through the registered v2 order route.
	 *
	 * @param string      $operation     Mutation operation.
	 * @param array       $payload       Full order document.
	 * @param string|null $base_revision Current server revision for an update.
	 * @param string|null $mutation_id   Mutation ID to replay; a fresh one when null.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( string $operation, array $payload, ?string $base_revision = null, ?string $mutation_id = null ): WP_REST_Response {
		$envelope = array(
			'mutationId'   => $mutation_id ?? sprintf( '58000000-0000-4000-8000-%012d', ++$this->sequence ),
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

	/**
	 * Register a third-party plugin that writes order (or line) meta at the end of every push request.
	 *
	 * @param bool $lines Write `_x_third_party_line_stamp` on every line instead of order meta.
	 *
	 * @return void
	 */
	private function add_third_party_writer( bool $lines ): void {
		$n = 0;
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response, $handler, $request ) use ( $lines, &$n ) {
				$data = $response instanceof WP_REST_Response && '/wcpos/v2/push/orders' === $request->get_route() ? $response->get_data() : array();
				$id   = (int) ( $data['document']['id'] ?? $data['current']['id'] ?? 0 );
				if ( 0 !== $id ) {
					$order = wc_get_order( $id );
					++$n;
					if ( $lines ) {
						foreach ( array_keys( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) ) as $item_id ) {
							wc_update_order_item_meta( $item_id, '_x_third_party_line_stamp', (string) $n );
						}
					} else {
						$order->update_meta_data( '_x_third_party_stamp', (string) $n );
						$order->save_meta_data();
					}
				}
				return $response;
			},
			10,
			3
		);
	}

	/**
	 * The acknowledged document with a new customer note, so the update is a real edit.
	 *
	 * @param WP_REST_Response $response Previous acknowledgement.
	 * @param string           $note     Customer note to send.
	 *
	 * @return array
	 */
	private function edited_document( WP_REST_Response $response, string $note ): array {
		return array_merge( $response->get_data()['document'], array( 'customer_note' => $note ) );
	}

	/**
	 * POS-2VT: order and hidden line meta written after every push no longer 409 the next push.
	 *
	 * @return void
	 */
	public function test_meta_written_after_every_push_no_longer_409s_the_next_push(): void {
		// Arrange.
		$this->add_third_party_writer( false );
		$this->add_third_party_writer( true );
		$previous = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $previous->get_status(), wp_json_encode( $previous->get_data() ) );
		// Act and assert: each update anchors on the previous acknowledgement.
		foreach ( array( 'first edit', 'second edit', 'third edit' ) as $note ) {
			$previous = $this->push_order( 'update', $this->edited_document( $previous, $note ), $previous->get_data()['currentRevision'] );
			$this->assertSame( 200, $previous->get_status(), wp_json_encode( $previous->get_data() ) );
		}
	}

	/**
	 * Excluded order and line meta keys written after every push no longer 409 the next push.
	 *
	 * @return void
	 */
	public function test_excluded_order_and_line_meta_keys_written_after_every_push_no_longer_409(): void {
		// Arrange.
		$this->add_third_party_writer( false );
		$this->add_third_party_writer( true );
		add_filter( 'wcpos_sync_order_revision_excluded_meta_keys', static fn() => array( '_x_third_party_stamp', '_x_third_party_line_stamp' ) );
		$previous = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $previous->get_status(), wp_json_encode( $previous->get_data() ) );
		// Act and assert: each update anchors on the previous acknowledgement.
		foreach ( array( 'first edit', 'second edit', 'third edit' ) as $note ) {
			$previous = $this->push_order( 'update', $this->edited_document( $previous, $note ), $previous->get_data()['currentRevision'] );
			$this->assertSame( 200, $previous->get_status(), wp_json_encode( $previous->get_data() ) );
		}
	}


	/**
	 * A POS-owned change made out of band still 409s a push anchored on the last good acknowledgement.
	 *
	 * @return void
	 */
	public function test_pos_owned_change_still_409s_a_stale_push(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$first = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $created->get_data()['currentRevision'] );
		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$anchor   = $first->get_data()['currentRevision'];
		$document = $first->get_data()['document'];
		$id       = (int) $document['id'];
		$line_id  = (int) $document['line_items'][0]['id'];
		$changes  = array(
			'order meta _pos_store'           => static function () use ( $id ): void {
				$order = wc_get_order( $id );
				$order->update_meta_data( '_pos_store', 'moved' );
				$order->save_meta_data();
			},
			'line meta _woocommerce_pos_data' => static function () use ( $line_id ): void {
				wc_update_order_item_meta( $line_id, '_woocommerce_pos_data', wp_json_encode( array( 'price' => '11' ) ) );
			},
			'visible line meta Size'          => static function () use ( $line_id ): void {
				wc_update_order_item_meta( $line_id, 'Size', 'L' );
			},
			'core field customer_note'        => static function () use ( $id ): void {
				$order = wc_get_order( $id );
				$order->set_customer_note( 'moved' );
				$order->save();
			},
			'line item quantity'              => static function () use ( $id, $line_id ): void {
				$item = wc_get_order( $id )->get_item( $line_id );
				$item->set_quantity( $item->get_quantity() + 1 );
				$item->save();
			},
		);
		// Act and assert: each change 409s, and the next case re-anchors on that 409.
		foreach ( $changes as $label => $change ) {
			$change();
			$stale = $this->push_order( 'update', array_merge( $document, array( 'customer_note' => $label ) ), $anchor );
			$this->assertSame( 409, $stale->get_status(), $label . ': ' . wp_json_encode( $stale->get_data() ) );
			$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] ?? null, $label );
			$anchor   = $stale->get_data()['currentRevision'];
			$document = $stale->get_data()['current'];
		}
	}

	/**
	 * A stale push names only differences inside the revision, including the till's own edit.
	 *
	 * @return void
	 */
	public function test_stale_order_push_409_names_what_moved_inside_the_revision(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id      = (int) $created->get_data()['document']['id'];
		$line_id = (int) $created->get_data()['document']['line_items'][0]['id'];
		$this->set_order_meta( $id, 'x_audit', 'moved' );
		wc_update_order_item_meta( $line_id, 'x_line_audit', 'moved' );
		$this->set_order_meta( $id, '_x_hidden_stamp', 'moved' );
		// Act.
		$stale = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] ?? null );
		$this->assertSame( array( 'customer_note', 'line_items.' . $line_id . '.meta_data.x_line_audit', 'meta_data.x_audit' ), $stale->get_data()['differs'] ?? null, wp_json_encode( $stale->get_data() ) );
	}

	/**
	 * A key the allow-list filter adds 409s a push once another plugin moves it.
	 *
	 * @return void
	 */
	public function test_allow_list_filter_brings_a_meta_key_into_the_revision(): void {
		// Arrange.
		add_filter( 'wcpos_sync_order_revision_meta_keys', static fn( array $keys ): array => array_merge( $keys, array( '_x_third_party_stamp' ) ) );
		$this->add_third_party_writer( false );
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		// Act.
		$stale = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] ?? null );
	}

	/**
	 * A key both the allow-list and the excluded filter name stays out of the hash.
	 *
	 * @return void
	 */
	public function test_excluded_meta_key_wins_over_the_allow_list(): void {
		// Arrange.
		add_filter( 'wcpos_sync_order_revision_excluded_meta_keys', static fn() => array( '_pos_store' ) );
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order = wc_get_order( (int) $created->get_data()['document']['id'] );
		$order->update_meta_data( '_pos_store', 'moved' );
		$order->save_meta_data();
		// Act.
		$update = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $update->get_status(), wp_json_encode( $update->get_data() ) );
	}

	/**
	 * The till's ack under the 2.0.2 recipe for an order with non-allow-listed order and hidden line meta.
	 *
	 * The new recipe plus `$old` hashes exactly what 2.0.2 hashed for this order; removing `$old` is the upgrade.
	 *
	 * @param WP_REST_Response $created The create's acknowledgement.
	 *
	 * @return string
	 */
	private function legacy_ack( WP_REST_Response $created ): string {
		$id    = (int) $created->get_data()['document']['id'];
		$order = wc_get_order( $id );
		$order->update_meta_data( '_wc_order_attribution_source_type', 'typein' );
		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( '_x_hidden_line', '1' );
			$item->save();
			break;
		}
		$order->save();
		// The create itself also writes `_pos_user_created` and `_woocommerce_pos_version`, which 2.0.2 hashed too.
		$old = static fn( array $keys ): array => array_merge( $keys, array( '_wc_order_attribution_source_type', '_x_hidden_line', '_pos_user_created', '_woocommerce_pos_version' ) );
		add_filter( 'wcpos_sync_order_revision_meta_keys', $old );
		$legacy = Order_Serializer::current_state( $id )['revision'];
		remove_filter( 'wcpos_sync_order_revision_meta_keys', $old );
		$this->assertNotSame( $legacy, Order_Serializer::current_state( $id )['revision'], 'The recipes hash the same bytes: the test would be vacuous.' );
		return $legacy;
	}

	/**
	 * A till's ack from before the upgrade (the 2.0.2 recipe) is accepted after it.
	 *
	 * @return void
	 */
	public function test_a_2_0_3_ack_is_accepted_after_the_upgrade(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$legacy = $this->legacy_ack( $created );
		// Act.
		$update = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $legacy );
		// Assert.
		$this->assertSame( 200, $update->get_status(), wp_json_encode( $update->get_data() ) );
	}

	/**
	 * A till's 2.0.2 ack still 409s once a POS-owned key moved after it.
	 *
	 * @return void
	 */
	public function test_a_2_0_3_ack_after_a_pos_owned_change_still_409s(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$legacy = $this->legacy_ack( $created );
		$order  = wc_get_order( (int) $created->get_data()['document']['id'] );
		$order->update_meta_data( '_pos_store', 'moved' );
		$order->save_meta_data();
		// Act.
		$stale = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), $legacy );
		// Assert.
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] ?? null );
	}

	/**
	 * Write one order meta value out of band, as another plugin would.
	 *
	 * @param int    $id    Order ID.
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 *
	 * @return void
	 */
	private function set_order_meta( int $id, string $key, string $value ): void {
		$order = wc_get_order( $id );
		$order->update_meta_data( $key, $value );
		$order->save_meta_data();
	}

	/**
	 * The value of the first meta entry with this key in a serialized meta_data list, or null.
	 *
	 * @param array  $meta_data Serialized meta entries.
	 * @param string $key       Meta key.
	 *
	 * @return mixed
	 */
	private function meta_value( array $meta_data, string $key ) {
		foreach ( $meta_data as $entry ) {
			if ( $key === Meta_Entry::key( $entry ) ) {
				return Meta_Entry::value( $entry );
			}
		}
		return null;
	}

	/**
	 * The common arrange: create, write `OLD` out of band, push a first edit; its ack is the document the till holds.
	 *
	 * The first edit anchors on the order as it stands after the write, the revision a pull hands the
	 * till: a visible key moves the revision, and a hidden one leaves it equal to the create's ack.
	 *
	 * @param callable $write Writes one value out of band: fn( int $order_id, int $line_id, string $value ).
	 *
	 * @return array{0: WP_REST_Response, 1: int, 2: int} The held ack, the order ID and the first line ID.
	 */
	private function held_after_first_edit( callable $write ): array {
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id      = (int) $created->get_data()['document']['id'];
		$line_id = (int) $created->get_data()['document']['line_items'][0]['id'];
		$write( $id, $line_id, 'OLD' );
		$first = $this->push_order( 'update', $this->edited_document( $created, 'first edit' ), Order_Serializer::current_state( $id )['revision'] );
		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		return array( $first, $id, $line_id );
	}

	/**
	 * A stale echo of hidden third-party order meta does not revert another plugin's newer value.
	 *
	 * @return void
	 */
	public function test_a_stale_echo_does_not_revert_hidden_third_party_order_meta(): void {
		// Arrange.
		$write                    = fn( int $id, int $line_id, string $value ) => $this->set_order_meta( $id, '_tracking_number', $value );
		list( $held, $id, $line ) = $this->held_after_first_edit( $write );
		$this->assertSame( 'OLD', $this->meta_value( $held->get_data()['document']['meta_data'], '_tracking_number' ) );
		$write( $id, $line, 'NEW' );
		// Act.
		$push = $this->push_order( 'update', $this->edited_document( $held, 'second edit' ), $held->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$this->assertSame( 'NEW', wc_get_order( $id )->get_meta( '_tracking_number' ) );
		$this->assertCount( 1, wc_get_order( $id )->get_meta( '_tracking_number', false ) );
	}

	/**
	 * A stale echo of hidden third-party line meta does not revert another plugin's newer value.
	 *
	 * @return void
	 */
	public function test_a_stale_echo_does_not_revert_hidden_third_party_line_meta(): void {
		// Arrange.
		$write                       = static fn( int $id, int $line_id, string $value ) => wc_update_order_item_meta( $line_id, '_tracking_number', $value );
		list( $held, $id, $line_id ) = $this->held_after_first_edit( $write );
		$this->assertSame( 'OLD', $this->meta_value( $held->get_data()['document']['line_items'][0]['meta_data'], '_tracking_number' ) );
		$write( $id, $line_id, 'NEW' );
		// Act.
		$push = $this->push_order( 'update', $this->edited_document( $held, 'second edit' ), $held->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$this->assertSame( 'NEW', wc_get_order_item_meta( $line_id, '_tracking_number', true ) );
		$this->assertCount( 1, wc_get_order_item_meta( $line_id, '_tracking_number', false ) );
	}

	/**
	 * A new line pushed with a hidden third-party key keeps it.
	 *
	 * @return void
	 */
	public function test_a_new_line_pushed_with_a_hidden_third_party_key_keeps_it(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document                 = $this->edited_document( $created, 'add a line' );
		$old_line_id              = (int) $document['line_items'][0]['id'];
		$document['line_items'][] = array(
			'product_id' => (int) $document['line_items'][0]['product_id'],
			'quantity'   => 1,
			'subtotal'   => '10',
			'total'      => '10',
			'meta_data'  => array(
				array(
					'key'   => '_x_addon',
					'value' => 'gift-wrap',
				),
			),
		);
		// Act.
		$push = $this->push_order( 'update', $document, $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$items = wc_get_order( (int) $document['id'] )->get_items( 'line_item' );
		unset( $items[ $old_line_id ] );
		$this->assertCount( 1, $items );
		$this->assertSame( 'gift-wrap', reset( $items )->get_meta( '_x_addon' ) );
	}

	/**
	 * A hidden third-party order meta key the order does not have yet is written.
	 *
	 * @return void
	 */
	public function test_a_hidden_third_party_key_new_to_the_order_is_written(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$document                = $this->edited_document( $created, 'add a key' );
		$document['meta_data'][] = array(
			'key'   => '_x_new_key',
			'value' => 'v1',
		);
		// Act.
		$push = $this->push_order( 'update', $document, $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$this->assertSame( 'v1', wc_get_order( (int) $document['id'] )->get_meta( '_x_new_key' ) );
	}

	/**
	 * A push that omits a hidden third-party order or line key does not delete it.
	 *
	 * @return void
	 */
	public function test_a_push_that_omits_a_hidden_third_party_key_does_not_delete_it(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id      = (int) $created->get_data()['document']['id'];
		$line_id = (int) $created->get_data()['document']['line_items'][0]['id'];
		$this->set_order_meta( $id, '_tracking_number', 'KEEP' );
		wc_update_order_item_meta( $line_id, '_tracking_number', 'KEEP' );
		// Act.
		$push = $this->push_order( 'update', $this->edited_document( $created, 'edit' ), $created->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$this->assertSame( 'KEEP', wc_get_order( $id )->get_meta( '_tracking_number' ) );
		$this->assertSame( 'KEEP', wc_get_order_item_meta( $line_id, '_tracking_number', true ) );
	}

	/**
	 * Pushed allow-listed hidden meta, on the order and on a line, and visible order meta are still written.
	 *
	 * @return void
	 */
	public function test_pushed_allow_listed_and_visible_meta_is_still_written(): void {
		// Arrange.
		add_filter( 'wcpos_sync_order_revision_meta_keys', static fn( array $keys ): array => array_merge( $keys, array( '_x_site_key' ) ) );
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id      = (int) $created->get_data()['document']['id'];
		$line_id = (int) $created->get_data()['document']['line_items'][0]['id'];
		$this->set_order_meta( $id, '_x_site_key', 'before' );
		$this->set_order_meta( $id, 'gift_message', 'before' );
		wc_update_order_item_meta( $line_id, 'x_engraving', 'before' );
		$anchor   = Order_Serializer::current_state( $id )['revision'];
		$pos_data = array(
			'price'         => '10',
			'regular_price' => '10',
			'tax_status'    => 'none',
			'note'          => 'edited',
		);
		$document = $this->edited_document( $created, 'edit meta' );
		foreach ( $document['line_items'][0]['meta_data'] as $i => $entry ) {
			if ( '_woocommerce_pos_data' === $entry['key'] ) {
				$document['line_items'][0]['meta_data'][ $i ]['value'] = wp_json_encode( $pos_data );
			}
		}
		$document['line_items'][0]['meta_data'][] = array(
			'key'   => 'x_engraving',
			'value' => 'after',
		);
		$document['meta_data'][]                  = array(
			'key'   => 'gift_message',
			'value' => 'hello',
		);
		$document['meta_data'][] = array(
			'key'   => '_x_site_key',
			'value' => 'after',
		);
		// Act.
		$push = $this->push_order( 'update', $document, $anchor );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$stored = wc_get_order_item_meta( $line_id, '_woocommerce_pos_data', true );
		$this->assertSame( $pos_data, is_array( $stored ) ? $stored : json_decode( (string) $stored, true ) );
		$this->assertSame( array( 'after' ), wc_get_order_item_meta( $line_id, 'x_engraving', false ) );
		$this->assertSame( 'hello', wc_get_order( $id )->get_meta( 'gift_message' ) );
		$this->assertSame( 'after', wc_get_order( $id )->get_meta( '_x_site_key' ) );
	}

	/**
	 * A pushed entry cannot rewrite a hidden third-party row through its meta id, on the order or on a line.
	 *
	 * @return void
	 */
	public function test_a_push_cannot_rewrite_a_hidden_third_party_row_by_its_meta_id(): void {
		// Arrange.
		$created = $this->push_order( 'create', $this->create_payload() );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id      = (int) $created->get_data()['document']['id'];
		$line_id = (int) $created->get_data()['document']['line_items'][0]['id'];
		$this->set_order_meta( $id, '_tracking_number', 'NEW' );
		wc_update_order_item_meta( $line_id, '_tracking_number', 'NEW' );
		$row_id = static function ( array $meta_data ): int {
			foreach ( $meta_data as $meta ) {
				if ( '_tracking_number' === $meta->key ) {
					return (int) $meta->id;
				}
			}
			return 0;
		};
		$order_row = $row_id( wc_get_order( $id )->get_meta_data() );
		$line_row  = $row_id( wc_get_order( $id )->get_item( $line_id )->get_meta_data() );
		$this->assertGreaterThan( 0, $order_row );
		$this->assertGreaterThan( 0, $line_row );
		$document                                 = $this->edited_document( $created, 'hijack by id' );
		$document['meta_data'][]                  = array(
			'id'    => $order_row,
			'key'   => '_x_other',
			'value' => 'hijack',
		);
		$document['line_items'][0]['meta_data'][] = array(
			'id'    => $line_row,
			'key'   => '_x_other',
			'value' => 'hijack',
		);
		// Act.
		$push = $this->push_order( 'update', $document, Order_Serializer::current_state( $id )['revision'] );
		// Assert.
		$this->assertSame( 200, $push->get_status(), wp_json_encode( $push->get_data() ) );
		$this->assertSame( array( 'NEW' ), array_values( array_map( static fn( $meta ) => $meta->value, wc_get_order( $id )->get_meta( '_tracking_number', false ) ) ) );
		$this->assertSame( array( 'NEW' ), wc_get_order_item_meta( $line_id, '_tracking_number', false ) );
		foreach ( wc_get_order( $id )->get_meta_data() as $meta ) {
			$this->assertFalse( $order_row === (int) $meta->id && 'hijack' === $meta->value, 'Order meta row ' . $order_row . ' was rewritten.' );
		}
		foreach ( wc_get_order( $id )->get_item( $line_id )->get_meta_data() as $meta ) {
			$this->assertFalse( $line_row === (int) $meta->id && 'hijack' === $meta->value, 'Line meta row ' . $line_row . ' was rewritten.' );
		}
	}

	/**
	 * A third party's change to visible order meta still 409s a stale push, as on 2.0.2.
	 *
	 * @return void
	 */
	public function test_a_visible_third_party_order_meta_change_still_409s(): void {
		// Arrange.
		$write                    = fn( int $id, int $line_id, string $value ) => $this->set_order_meta( $id, 'gift_message', $value );
		list( $held, $id, $line ) = $this->held_after_first_edit( $write );
		$this->assertSame( 'OLD', $this->meta_value( $held->get_data()['document']['meta_data'], 'gift_message' ) );
		$write( $id, $line, 'NEW' );
		// Act.
		$stale = $this->push_order( 'update', $this->edited_document( $held, 'second edit' ), $held->get_data()['currentRevision'] );
		// Assert.
		$this->assertSame( 409, $stale->get_status(), wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] ?? null );
	}

	/**
	 * Assert the order and every uuid-bearing line keep exactly one, unchanged, `_woocommerce_pos_uuid` row.
	 *
	 * @param int      $id         Order ID.
	 * @param string[] $line_uuids Uuid sent on create, by line ID.
	 * @param string   $step       Step label for failure messages.
	 *
	 * @return void
	 */
	private function assert_pos_uuids( int $id, array $line_uuids, string $step ): void {
		$rows = array_map( static fn( $meta ) => $meta->value, wc_get_order( $id )->get_meta( '_woocommerce_pos_uuid', false ) );
		$this->assertSame( array( self::RECORD_UUID ), array_values( $rows ), $step );
		foreach ( $line_uuids as $line_id => $uuid ) {
			$this->assertSame( array( $uuid ), wc_get_order_item_meta( $line_id, '_woocommerce_pos_uuid', false ), $step . ' line ' . $line_id );
		}
	}

	/**
	 * The order and line `_woocommerce_pos_uuid` rows survive a create, its replay and an update unchanged.
	 *
	 * @return void
	 */
	public function test_pos_uuid_round_trips_on_create_replay_and_update(): void {
		// Arrange.
		$payload = $this->create_payload();
		$uuids   = array(
			'line_items'     => '57000000-0000-4000-8000-000000000011',
			'fee_lines'      => '57000000-0000-4000-8000-000000000012',
			'shipping_lines' => '57000000-0000-4000-8000-000000000013',
		);
		foreach ( $uuids as $type => $uuid ) {
			$payload[ $type ][0]['meta_data']   = $payload[ $type ][0]['meta_data'] ?? array();
			$payload[ $type ][0]['meta_data'][] = array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => $uuid,
			);
		}
		$mutation_id = '58000000-0000-4000-8000-000000999999';
		// Act and assert.
		$created = $this->push_order( 'create', $payload, null, $mutation_id );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id         = (int) $created->get_data()['document']['id'];
		$line_uuids = array();
		foreach ( $uuids as $type => $uuid ) {
			$line_uuids[ (int) $created->get_data()['document'][ $type ][0]['id'] ] = $uuid;
		}
		$this->assert_pos_uuids( $id, $line_uuids, 'create' );
		$replay = $this->push_order( 'create', $payload, null, $mutation_id );
		$this->assertSame( 201, $replay->get_status(), wp_json_encode( $replay->get_data() ) );
		$this->assertSame( $id, (int) $replay->get_data()['document']['id'] );
		$this->assert_pos_uuids( $id, $line_uuids, 'replay' );
		$update = $this->push_order( 'update', $this->edited_document( $created, 'edit' ), $created->get_data()['currentRevision'] );
		$this->assertSame( 200, $update->get_status(), wp_json_encode( $update->get_data() ) );
		$this->assert_pos_uuids( $id, $line_uuids, 'update' );
	}
}
