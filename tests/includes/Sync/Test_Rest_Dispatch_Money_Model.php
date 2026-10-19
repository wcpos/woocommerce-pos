<?php
/**
 * Route-dispatch pins for the push lane under ADR 0040 (the money model without an epoch).
 *
 * The push lane carries lines, customer, address, notes and status changes among open
 * statuses; it never carries money. These pins drive the REAL registered route
 * (`POST /{namespace}/push/orders`, real inner wc/v3 dispatch, real Mutation_Store) and read
 * storage back through the data store on a fresh order, never the object the push returned.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Money_Model extends Sync_REST_Store_Test_Case {
	protected const REC = '6c0d4e2a-7b1f-4c3d-9e5a-1f2b3c4d5e6f';
	private $mutation_seq = 0;
	/** @var string Mutation id of the last push. */
	protected $last_mutation_id = '';

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	protected function push_envelope( string $operation, array $payload, $base_revision = null, string $protocol = '3' ) {
		$this->last_mutation_id = sprintf( 'e5f6a7b8-5555-4666-8777-%012d', ++$this->mutation_seq );
		$envelope               = array(
			'mutationId'   => $this->last_mutation_id,
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WCPOS-Protocol', $protocol );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	protected function order_revision( int $order_id ): string {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
		$request->set_param( 'dp', '6' );
		$data = Meta_Normalizer::normalize( rest_do_request( $request )->get_data() );
		$data = Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );

		return Order_Serializer::canonical_revision( $data );
	}

	protected function uuid_meta(): array {
		return array(
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => self::REC,
			),
		);
	}

	/** An order the till already pushed: known uuid, the given status and lines. */
	protected function make_order( string $status, array $line_items = array() ): int {
		$order = wc_create_order();
		foreach ( $line_items as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->set_created_via( 'woocommerce-pos' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->update_meta_data( '_woocommerce_pos_uuid', self::REC );
		$order->save();

		return $order->get_id();
	}

	protected function order_id_for_uuid(): int {
		$ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_woocommerce_pos_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test lookup.
				'meta_value' => self::REC, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test lookup.
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	protected function cash_payment( string $amount ): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => 'pos_cash',
			'amount'    => $amount,
			'currency'  => get_woocommerce_currency(),
		);
	}

	public function test_push_from_a_protocol_2_client_is_refused_426_with_nothing_reserved(): void {
		// Arrange.
		$payload = array(
			'status'    => 'pos-open',
			'meta_data' => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'create', $payload, null, '2' );

		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'wcpos_update_required', $response->get_data()['code'] );
		$this->assertNull( ( new Mutation_Store() )->lookup( 'orders', $this->last_mutation_id ) );
		$this->assertSame( 0, $this->order_id_for_uuid() );
	}

	public function test_payment_record_from_a_protocol_2_client_is_refused_426(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open' );
		$order    = wc_get_order( $order_id );
		$order->set_total( '10.00' );
		$order->save();
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/orders/' . $order_id . '/payments' );
		$request->set_header( 'X-WCPOS-Protocol', '2' );
		$request->set_body_params( array( 'payment' => $this->cash_payment( '10.00' ) ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'wcpos_update_required', $response->get_data()['code'] );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order_id ) ) );
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );
	}
}
