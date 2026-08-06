<?php
/**
 * Route-dispatch pins for omission-as-removal on v2 full-document order updates.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;

/**
 * Verify omitted order items are removed only for line collections present in the payload.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Omitted_Line_Removal extends Sync_REST_Store_Test_Case {
	private const RECORD_UUID   = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051001';
	private const LINE_ONE_UUID = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051002';
	private const LINE_TWO_UUID = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051003';
	private const FEE_UUID      = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051004';
	private const NEW_LINE_UUID = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051005';
	private const SHIPPING_UUID = '92f1a7c0-7c0e-4f17-9cc9-0f2ee4051006';

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
	 * Build one metadata entry containing a stable POS UUID.
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
			'mutationId'   => sprintf( '92f1a7c0-7c0e-4f17-9cc9-%012d', ++$this->mutation_sequence ),
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
	 * Create an order with two product lines, one fee, and optional shipping.
	 *
	 * @param bool $with_shipping Whether to include a shipping line.
	 *
	 * @return array Created order details and acknowledgement.
	 */
	private function create_order( bool $with_shipping = false ): array {
		$product_one = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$product_two = ProductHelper::create_simple_product(
			array(
				'regular_price' => 5,
				'price'         => 5,
			)
		);
		$payload = array(
			'status'     => 'pending',
			'line_items' => array(
				array(
					'product_id' => $product_one->get_id(),
					'quantity'   => 1,
					'meta_data'  => $this->uuid_meta( self::LINE_ONE_UUID ),
				),
				array(
					'product_id' => $product_two->get_id(),
					'quantity'   => 1,
					'meta_data'  => $this->uuid_meta( self::LINE_TWO_UUID ),
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
		);
		if ( $with_shipping ) {
			$payload['shipping_lines'] = array(
				array(
					'method_id'    => 'flat_rate',
					'method_title' => 'Flat rate',
					'total'        => '3.00',
					'meta_data'    => $this->uuid_meta( self::SHIPPING_UUID ),
				),
			);
		}

		$response = $this->push_envelope( 'create', $payload );
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();

		return array(
			'document'       => $data['document'],
			'order_id'       => (int) $data['document']['id'],
			'product_two_id' => $product_two->get_id(),
			'revision'       => $data['currentRevision'],
		);
	}

	/**
	 * A full document that omits a product and fee removes both stored items.
	 *
	 * @return void
	 */
	public function test_omitted_lines_full_document_removes_product_and_fee_and_recalculates_total(): void {
		// Arrange.
		$created               = $this->create_order();
		$payload               = $created['document'];
		$payload['line_items'] = array( $payload['line_items'][0] );
		$payload['fee_lines']  = array();

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 1, count( $order->get_items( 'line_item' ) ) );
		$this->assertEquals( 0, count( $order->get_items( 'fee' ) ) );
		$this->assertEquals( '10.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/**
	 * An absent fee_lines key makes no statement and preserves the stored fee.
	 *
	 * @return void
	 */
	public function test_omitted_lines_absent_fee_lines_key_preserves_stored_fee(): void {
		// Arrange.
		$created = $this->create_order();
		$payload = $created['document'];
		unset( $payload['fee_lines'] );

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 1, count( $order->get_items( 'fee' ) ) );
		$this->assertEquals( '17.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/**
	 * An explicitly empty line_items collection removes every stored product line.
	 *
	 * @return void
	 */
	public function test_omitted_lines_empty_line_items_array_removes_all_product_lines(): void {
		// Arrange.
		$created               = $this->create_order();
		$payload               = $created['document'];
		$payload['line_items'] = array();

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 0, count( $order->get_items( 'line_item' ) ) );
		$this->assertEquals( 1, count( $order->get_items( 'fee' ) ) );
		$this->assertEquals( '2.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/**
	 * One update can remove an omitted line while appending a fresh UUID line.
	 *
	 * @return void
	 */
	public function test_omitted_lines_with_new_uuid_line_removes_old_line_and_appends_new_line(): void {
		// Arrange.
		$created               = $this->create_order();
		$payload               = $created['document'];
		$payload['line_items'] = array(
			$payload['line_items'][0],
			array(
				'product_id' => $created['product_two_id'],
				'quantity'   => 2,
				'meta_data'  => $this->uuid_meta( self::NEW_LINE_UUID ),
			),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$lines = array_values( $order->get_items( 'line_item' ) );
		$uuids = array_map(
			static function ( $line ) {
				return $line->get_meta( '_woocommerce_pos_uuid', true );
			},
			$lines
		);
		$this->assertEquals( 2, count( $lines ) );
		$this->assertNotContains( self::LINE_TWO_UUID, $uuids );
		$this->assertContains( self::NEW_LINE_UUID, $uuids );
		$this->assertEquals( '22.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/**
	 * A stale update is rejected before an omitted stored line can be removed.
	 *
	 * @return void
	 */
	public function test_omitted_lines_stale_base_revision_returns_conflict_and_removes_nothing(): void {
		// Arrange.
		$created               = $this->create_order();
		$payload               = $created['document'];
		$payload['line_items'] = array( $payload['line_items'][0] );
		$order                 = wc_get_order( $created['order_id'] );
		$order->update_meta_data( '_server_edit', 'newer-state' );
		$order->save();

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 409, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 2, count( $order->get_items( 'line_item' ) ) );
		$this->assertEquals( 1, count( $order->get_items( 'fee' ) ) );
	}

	/**
	 * An explicitly empty shipping_lines collection removes stored shipping.
	 *
	 * @return void
	 */
	public function test_omitted_lines_empty_shipping_lines_array_removes_stored_shipping(): void {
		// Arrange.
		$created                   = $this->create_order( true );
		$payload                   = $created['document'];
		$payload['shipping_lines'] = array();

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( 0, count( $order->get_items( 'shipping' ) ) );
		$this->assertEquals( '17.00', wc_format_decimal( $order->get_total(), 2 ) );
	}
}
