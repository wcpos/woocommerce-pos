<?php
/**
 * Route-dispatch pins for first-write-wins till metadata on v2 order updates.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WP_REST_Request;

/**
 * Verify till metadata is stamped from the original v2 update payload only once.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Till_Meta extends Sync_REST_Store_Test_Case {
	/** Stable order UUID used by each isolated test. */
	private const RECORD_UUID = '72819847-9b85-44d7-9946-69eb0914726a';

	/**
	 * Mutation sequence used to produce unique idempotency keys.
	 *
	 * @var int
	 */
	private $mutation_sequence = 0;

	/**
	 * Inner wc/v3 requests observed during a push.
	 *
	 * @var WP_REST_Request[]
	 */
	private $forwarded = array();

	/**
	 * Set up the real v2 and inner wc/v3 routes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_wc_request' ), 1, 3 );
	}

	/**
	 * Restore route-related global state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'rest_pre_dispatch', array( $this, 'capture_wc_request' ), 1 );
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/**
	 * Capture inner WooCommerce REST requests without replacing their dispatch.
	 *
	 * @param mixed           $result  Current pre-dispatch result.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request Request being dispatched.
	 *
	 * @return mixed
	 */
	public function capture_wc_request( $result, $server, WP_REST_Request $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/v3/' ) ) {
			$this->forwarded[] = $request;
		}

		return $result;
	}

	/**
	 * Convert a key-value map to WooCommerce metadata entries.
	 *
	 * @param array $values Metadata keyed by name.
	 *
	 * @return array
	 */
	private function meta_entries( array $values ): array {
		$entries = array();
		foreach ( $values as $key => $value ) {
			$entries[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $entries;
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
			'mutationId'   => sprintf( '72819847-9b85-44d7-9946-%012d', ++$this->mutation_sequence ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::RECORD_UUID,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . 'push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Create an order through v2 with optional till metadata.
	 *
	 * @param array $till_meta Till metadata keyed by name.
	 *
	 * @return array Created order ID, document, and revision.
	 */
	private function create_order( array $till_meta = array() ): array {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$meta = array( '_woocommerce_pos_uuid' => self::RECORD_UUID ) + $till_meta;
		$response = $this->push_envelope(
			'create',
			array(
				'status'     => 'pending',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'meta_data' => $this->meta_entries( $meta ),
			)
		);
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();

		return array(
			'order_id' => (int) $data['document']['id'],
			'document' => $data['document'],
			'revision' => $data['currentRevision'],
		);
	}

	/**
	 * Missing till metadata is persisted from the first update.
	 *
	 * @return void
	 */
	public function test_till_meta_create_without_values_update_with_values_persists_values(): void {
		// Arrange.
		$created = $this->create_order();
		$payload = array(
			'meta_data' => $this->meta_entries(
				array(
					'_pos_cash_amount_tendered' => '20.00',
					'_pos_cash_change'          => '5.00',
				)
			),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( '20.00', $order->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertEquals( '5.00', $order->get_meta( '_pos_cash_change' ) );
	}

	/**
	 * Existing till metadata cannot be overwritten by an update.
	 *
	 * @return void
	 */
	public function test_till_meta_create_with_values_update_with_different_values_preserves_created_values(): void {
		// Arrange.
		$created = $this->create_order(
			array(
				'_pos_cash_amount_tendered' => '20.00',
				'_pos_cash_change'          => '5.00',
				'_pos_card_cashback'        => '3.00',
			)
		);
		$payload = array(
			'meta_data' => $this->meta_entries(
				array(
					'_pos_cash_amount_tendered' => '40.00',
					'_pos_cash_change'          => '10.00',
					'_pos_card_cashback'        => '6.00',
				)
			),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( '20.00', $order->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertEquals( '5.00', $order->get_meta( '_pos_cash_change' ) );
		$this->assertEquals( '3.00', $order->get_meta( '_pos_card_cashback' ) );
	}

	/**
	 * Till metadata written by an update cannot be overwritten by a later update.
	 *
	 * @return void
	 */
	public function test_till_meta_two_updates_with_different_values_preserves_first_update_values(): void {
		// Arrange.
		$created = $this->create_order();
		$first = array(
			'meta_data' => $this->meta_entries(
				array(
					'_pos_cash_amount_tendered' => '20.00',
					'_pos_cash_change'          => '5.00',
				)
			),
		);
		$second = array(
			'meta_data' => $this->meta_entries(
				array(
					'_pos_cash_amount_tendered' => '40.00',
					'_pos_cash_change'          => '10.00',
				)
			),
		);

		// Act.
		$first_response = $this->push_envelope( 'update', $first, $created['revision'] );
		$second_response = $this->push_envelope( 'update', $second, $first_response->get_data()['currentRevision'] );

		// Assert.
		$this->assertEquals( 200, $first_response->get_status() );
		$this->assertEquals( 200, $second_response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( '20.00', $order->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertEquals( '5.00', $order->get_meta( '_pos_cash_change' ) );
	}

	/**
	 * Till metadata never reaches the inner wc/v3 update payload.
	 *
	 * @return void
	 */
	public function test_till_meta_update_forward_strips_all_till_keys(): void {
		// Arrange.
		$created = $this->create_order();
		$this->forwarded = array();
		$payload = array(
			'meta_data' => $this->meta_entries(
				array(
					'_pos_cash_amount_tendered' => '20.00',
					'_pos_cash_change'          => '5.00',
					'_pos_card_cashback'        => '3.00',
					'public_note'               => 'keep',
				)
			),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $created['revision'] );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$updates = array_values(
			array_filter(
				$this->forwarded,
				static function ( WP_REST_Request $request ): bool {
					return 'PUT' === $request->get_method();
				}
			)
		);
		$this->assertEquals( 1, count( $updates ) );
		$keys = array_column( $updates[0]->get_body_params()['meta_data'], 'key' );
		$this->assertEquals(
			array(),
			array_intersect(
				array( '_pos_cash_amount_tendered', '_pos_cash_change', '_pos_card_cashback' ),
				$keys
			)
		);
		$this->assertContains( 'public_note', $keys );
	}

	/**
	 * A direct non-POS wc/v3 update does not invoke the v2 till stamp.
	 *
	 * @return void
	 */
	public function test_till_meta_non_pos_wc_update_keeps_stock_meta_behavior(): void {
		// Arrange.
		$created = $this->create_order(
			array(
				'_pos_cash_amount_tendered' => '20.00',
				'_pos_cash_change'          => '5.00',
			)
		);
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $created['order_id'] );
		$request->set_body_params(
			array(
				'meta_data' => $this->meta_entries(
					array(
						'_pos_cash_amount_tendered' => '40.00',
						'_pos_cash_change'          => '10.00',
					)
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$order = wc_get_order( $created['order_id'] );
		$this->assertEquals( '40.00', $order->get_meta( '_pos_cash_amount_tendered' ) );
		$this->assertEquals( '10.00', $order->get_meta( '_pos_cash_change' ) );
	}
}
