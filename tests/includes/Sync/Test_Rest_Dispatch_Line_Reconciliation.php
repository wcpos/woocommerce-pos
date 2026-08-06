<?php
/**
 * Route-dispatch pins for UUID-based order item reconciliation on v2 updates.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order_Item_Product;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * Verify order item IDs are restored from their stable POS UUIDs before wc/v3 dispatch.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Line_Reconciliation extends Sync_REST_Store_Test_Case {
	private const RECORD_UUID = '91f1a7c0-7c0e-4f17-9cc9-0f2ee4051001';
	private const LINE_UUID   = '91f1a7c0-7c0e-4f17-9cc9-0f2ee4051002';
	private const FEE_UUID    = '91f1a7c0-7c0e-4f17-9cc9-0f2ee4051003';
	private const NEW_UUID    = '91f1a7c0-7c0e-4f17-9cc9-0f2ee4051004';

	/**
	 * Mutation sequence used to produce unique idempotency keys.
	 *
	 * @var int
	 */
	private $mutation_sequence = 0;

	/**
	 * Set up the real v2 and inner wc/v3 routes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/**
	 * Restore route-related global state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Build one metadata entry containing the stable POS UUID.
	 *
	 * @param string $uuid UUID value.
	 *
	 * @return array
	 */
	private function uuid_meta( string $uuid ): array {
		return array(
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => $uuid,
			),
		);
	}

	/**
	 * Dispatch one order mutation through the registered v2 push route.
	 *
	 * @param string      $operation     Mutation operation.
	 * @param array       $payload       Order document payload.
	 * @param string|null $base_revision Current order revision for updates.
	 *
	 * @return \WP_REST_Response
	 */
	private function push_envelope( string $operation, array $payload, $base_revision = null ) {
		$envelope = array(
			'mutationId'   => sprintf( '91f1a7c0-7c0e-4f17-9cc9-%012d', ++$this->mutation_sequence ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::RECORD_UUID,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Create a push order containing one product line and one fee line.
	 *
	 * @return array Created order details and acknowledgement.
	 */
	private function create_order(): array {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'pending',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
						'meta_data'  => $this->uuid_meta( self::LINE_UUID ),
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Handling',
						'tax_status' => 'none',
						'total'      => '2.00',
						'meta_data'  => $this->uuid_meta( self::FEE_UUID ),
					),
				),
				'meta_data'  => $this->uuid_meta( self::RECORD_UUID ),
			)
		);
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();

		return array(
			'document'  => $data['document'],
			'order_id'  => (int) $data['document']['id'],
			'product_id' => $product->get_id(),
			'revision'  => $data['currentRevision'],
		);
	}

	/**
	 * Strip order item IDs from a full acknowledged document without changing metadata.
	 *
	 * @param array $document Acknowledged order document.
	 *
	 * @return array
	 */
	private function strip_item_ids( array $document ): array {
		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines' ) as $line_type ) {
			if ( ! isset( $document[ $line_type ] ) || ! is_array( $document[ $line_type ] ) ) {
				continue;
			}
			foreach ( $document[ $line_type ] as $index => $line ) {
				if ( is_array( $line ) ) {
					unset( $document[ $line_type ][ $index ]['id'] );
				}
			}
		}

		return $document;
	}

	/**
	 * Recompute the canonical revision after direct stored-order manipulation.
	 *
	 * @param int $order_id Stored order ID.
	 *
	 * @return string
	 */
	private function order_revision( int $order_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id ) );
		$data     = Meta_Normalizer::normalize( $response->get_data() );
		$data     = Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );

		return Order_Serializer::canonical_revision( $data );
	}

	/**
	 * A full document whose acknowledged line IDs are missing keeps counts and totals stable.
	 *
	 * @return void
	 */
	public function test_line_reconciliation_full_document_without_ids_preserves_counts_and_total(): void {
		// Arrange.
		$created        = $this->create_order();
		$original_total = wc_get_order( $created['order_id'] )->get_total();
		$payload        = $this->strip_item_ids( $created['document'] );

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 1, count( $order->get_items( 'line_item' ) ) );
		$this->assertEquals( 1, count( $order->get_items( 'fee' ) ) );
		$this->assertEquals( $original_total, $order->get_total() );
	}

	/**
	 * A UUID-matched line with changed values updates the stored item in place.
	 *
	 * @return void
	 */
	public function test_line_reconciliation_changed_line_without_id_updates_stored_item_in_place(): void {
		// Arrange.
		$created          = $this->create_order();
		$original_line_id = (int) $created['document']['line_items'][0]['id'];
		$payload          = $this->strip_item_ids( $created['document'] );
		$payload['line_items'][0]['quantity'] = 2;
		$payload['line_items'][0]['subtotal'] = '20.00';
		$payload['line_items'][0]['total']    = '20.00';

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$lines = array_values( wc_get_order( $created['order_id'] )->get_items( 'line_item' ) );
		$this->assertEquals( 1, count( $lines ) );
		$this->assertEquals( $original_line_id, $lines[0]->get_id() );
		$this->assertEquals( 2, $lines[0]->get_quantity() );
		$this->assertEquals( '20.00', wc_format_decimal( $lines[0]->get_total(), 2 ) );
	}

	/**
	 * A fresh UUID remains unmatched and appends once beside reconciled original items.
	 *
	 * @return void
	 */
	public function test_line_reconciliation_fresh_uuid_without_id_appends_once_alongside_originals(): void {
		// Arrange.
		$created          = $this->create_order();
		$original_line_id = (int) $created['document']['line_items'][0]['id'];
		$payload          = $this->strip_item_ids( $created['document'] );
		$payload['line_items'][] = array(
			'product_id' => $created['product_id'],
			'quantity'   => 1,
			'meta_data'  => $this->uuid_meta( self::NEW_UUID ),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$lines = array_values( wc_get_order( $created['order_id'] )->get_items( 'line_item' ) );
		$this->assertEquals( 2, count( $lines ) );
		$this->assertEquals( 1, count( wc_get_order( $created['order_id'] )->get_items( 'fee' ) ) );
		$this->assertContains(
			$original_line_id,
			array_map(
				static function ( $line ) {
					return $line->get_id();
				},
				$lines
			)
		);
		$this->assertEquals(
			1,
			count(
				array_filter(
					$lines,
					static function ( $line ) {
						return self::NEW_UUID === $line->get_meta( '_woocommerce_pos_uuid', true );
					}
				)
			)
		);
	}

	/**
	 * A line with neither an ID nor UUID retains wc/v3's existing append behavior.
	 *
	 * @return void
	 */
	public function test_line_reconciliation_line_without_id_or_uuid_preserves_append_behavior(): void {
		// Arrange.
		$created          = $this->create_order();
		$original_line_id = (int) $created['document']['line_items'][0]['id'];
		$payload          = $this->strip_item_ids( $created['document'] );
		$payload['line_items'][] = array(
			'product_id' => $created['product_id'],
			'quantity'   => 1,
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$lines = array_values( wc_get_order( $created['order_id'] )->get_items( 'line_item' ) );
		$this->assertEquals( 2, count( $lines ) );
		$this->assertContains(
			$original_line_id,
			array_map(
				static function ( $line ) {
					return $line->get_id();
				},
				$lines
			)
		);
	}

	/**
	 * A duplicate stored UUID is ambiguous, so the posted line must remain an append.
	 *
	 * @return void
	 */
	public function test_line_reconciliation_duplicate_stored_uuid_leaves_posted_line_unmatched(): void {
		// Arrange.
		$created   = $this->create_order();
		$order     = wc_get_order( $created['order_id'] );
		$existing  = array_values( $order->get_items( 'line_item' ) )[0];
		$duplicate = new WC_Order_Item_Product();
		$duplicate->set_product( wc_get_product( $created['product_id'] ) );
		$duplicate->set_quantity( 1 );
		$duplicate->set_subtotal( '10.00' );
		$duplicate->set_total( '10.00' );
		$duplicate->update_meta_data( '_woocommerce_pos_uuid', self::LINE_UUID );
		$order->add_item( $duplicate );
		$order->calculate_totals();
		$order->save();
		$stored_ids  = array( $existing->get_id(), $duplicate->get_id() );
		$posted_line = $this->strip_item_ids( $created['document'] )['line_items'][0];

		// Act.
		$response = $this->push_envelope(
			'update',
			array(
				'line_items' => array( $posted_line ),
				'meta_data'  => $this->uuid_meta( self::RECORD_UUID ),
			),
			$this->order_revision( $created['order_id'] )
		);

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$line_ids = array_map(
			static function ( $line ) {
				return $line->get_id();
			},
			array_values( wc_get_order( $created['order_id'] )->get_items( 'line_item' ) )
		);
		$this->assertEquals( 3, count( $line_ids ) );
		$this->assertContains( $stored_ids[0], $line_ids );
		$this->assertContains( $stored_ids[1], $line_ids );
	}
}
