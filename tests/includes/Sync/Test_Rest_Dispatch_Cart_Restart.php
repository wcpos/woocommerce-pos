<?php
/**
 * Route-dispatch pins for the cart restart on v2 order updates (wcpos/monorepo#2183).
 *
 * When the cashier removes every line from an unpaid open cart and adds a line again,
 * the till restamps date_created_gmt and sends it on its next push. For an order that
 * already exists on the server that push is an update; wc/v3 drops the readonly field
 * on PUT, so the v2 writer carries it through the write intent — only while the order
 * is still an unpaid `pos-open` cart with no payment-ledger rows, only forward in time,
 * re-decided at pre-insert under the payment lock. These pins drive the REAL registered
 * route (`POST /wcpos/v2/push/orders`, real inner wc/v3 dispatch, real Mutation_Store).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer
 */
class Test_Rest_Dispatch_Cart_Restart extends Sync_REST_Store_Test_Case {
	private const REC          = '9b1c2d3e-5f60-4a7b-8c9d-0e1f2a3b4c5d';
	private const STORED_GMT   = '2026-05-01T09:00:00';
	private const RESTART_GMT  = '2026-05-07T12:30:45';
	private $mutation_seq      = 0;

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/** A card leg in flight: the cart is spoken for even though nothing is captured yet. */
	private function pending_card_row(): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => 'pos_card',
			'kind'      => 'card',
			'amount'    => '5.00',
			'status'    => 'pending',
		);
	}

	private function push_envelope( string $operation, array $payload, $base_revision = null ) {
		$envelope = array(
			'mutationId'   => sprintf( 'd4e5f6a7-4444-4555-8666-%012d', ++$this->mutation_seq ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function order_revision( int $order_id ): string {
		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
		$request->set_param( 'dp', '6' );
		$response = rest_do_request( $request );
		$data     = Meta_Normalizer::normalize( $response->get_data() );
		$data     = Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );

		return Order_Serializer::canonical_revision( $data );
	}

	private function uuid_meta(): array {
		return array(
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => self::REC,
			),
		);
	}

	/** An order the till already pushed: known uuid, known creation date, the given status. */
	private function make_order( string $status, array $line_items = array() ): int {
		$order = wc_create_order();
		foreach ( $line_items as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->set_status( $status );
		$order->set_date_created( strtotime( self::STORED_GMT . 'Z' ) );
		$order->update_meta_data( '_woocommerce_pos_uuid', self::REC );
		$order->save();

		return $order->get_id();
	}

	private function stored_date_gmt( int $order_id ): string {
		$date = wc_get_order( $order_id )->get_date_created( 'edit' );
		$this->assertNotNull( $date );

		return gmdate( 'Y-m-d\TH:i:s', $date->getTimestamp() );
	}

	/** The restart as the till sends it: an emptied cart (total 0) taking its first new line and a fresh date. */
	private function restart_payload( int $product_id ): array {
		return array(
			'status'           => 'pos-open',
			'date_created_gmt' => self::RESTART_GMT,
			'line_items'       => array(
				array(
					'product_id' => $product_id,
					'quantity'   => 1,
				),
			),
			'meta_data'        => $this->uuid_meta(),
		);
	}

	public function test_update_of_an_unpaid_open_cart_takes_the_client_date_created_gmt(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertSame( self::RESTART_GMT, $response->get_data()['document']['date_created_gmt'] );
		$this->assertCount( 1, wc_get_order( $order_id )->get_items() );
	}

	/**
	 * A restart alone neither creates nor overwrites the receipt time.
	 *
	 * @dataProvider receipt_time_cases
	 * @param string|null $received_gmt Existing receipt time, or null when absent.
	 */
	public function test_restart_alone_leaves_the_receipt_time_untouched( ?string $received_gmt ): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		if ( null !== $received_gmt ) {
			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_wcpos_sale_received_gmt', $received_gmt );
			$order->save();
		}

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
		$order = wc_get_order( $order_id );
		$this->assertSame( null !== $received_gmt, $order->meta_exists( '_wcpos_sale_received_gmt' ) );
		$this->assertSame( $received_gmt ?? '', $order->get_meta( '_wcpos_sale_received_gmt' ) );
	}

	/**
	 * Receipt-time states before the cart restarts.
	 *
	 * @return array
	 */
	public static function receipt_time_cases(): array {
		return array(
			'absent'   => array( null ),
			'existing' => array( '2026-05-01T09:01:00Z' ),
		);
	}

	/** The loaded open cart can restart even when the same push moves it out of pos-open. */
	public function test_restart_and_hold_in_one_push_takes_the_restart_date(): void {
		$product           = ProductHelper::create_simple_product();
		$order_id          = $this->make_order( 'pos-open' );
		$payload           = $this->restart_payload( $product->get_id() );
		$payload['status'] = 'on-hold';

		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertSame( 'on-hold', wc_get_order( $order_id )->get_status() );
	}

	/** The `Z`-suffixed form the till may send is the same instant. */
	public function test_update_of_an_unpaid_open_cart_takes_a_zulu_client_date_created_gmt(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$payload  = $this->restart_payload( $product->get_id() );

		$payload['date_created_gmt'] = self::RESTART_GMT . 'Z';
		$response                    = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/** A cart that still carries lines (the removal and the re-add arrived in one push) restarts too. */
	public function test_update_of_an_unpaid_open_cart_with_lines_takes_the_client_date_created_gmt(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open', array( array( $product, 1 ) ) );
		$this->assertTrue( wc_get_order( $order_id )->needs_payment() );

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/** The full document echoes the acked date on every ordinary edit: an unchanged value writes nothing. */
	public function test_update_echoing_the_stored_date_created_gmt_leaves_it_alone(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$payload  = $this->restart_payload( $product->get_id() );

		$payload['date_created_gmt'] = self::STORED_GMT;
		$response                    = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/** The field only ever moves forward: a second till still holding the old date cannot undo a restart. */
	public function test_update_with_an_earlier_date_created_gmt_keeps_the_stored_date(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$payload  = $this->restart_payload( $product->get_id() );

		$payload['date_created_gmt'] = '2026-04-30T23:59:59';
		$response                    = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertCount( 1, wc_get_order( $order_id )->get_items() );
	}

	public function test_update_of_an_open_cart_with_a_payment_row_keeps_the_stored_date(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open', array( array( $product, 1 ) ) );
		$order    = wc_get_order( $order_id );
		Ledger::instance()->save( $order, array( $this->pending_card_row() ), false );
		$order->save();
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order_id ) ) );
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/** Still `pos-open` with no ledger rows, but a paid date: paid by some other path, so no restart. */
	public function test_update_of_an_open_cart_with_a_paid_date_keeps_the_stored_date(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open', array( array( $product, 1 ) ) );
		$order    = wc_get_order( $order_id );
		$order->set_date_paid( strtotime( self::STORED_GMT . 'Z' ) + HOUR_IN_SECONDS );
		$order->save();
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order_id ) ) );

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/**
	 * A payment row that lands AFTER the pre-read and BEFORE wc/v3 loads the order: the
	 * restart is re-decided at pre-insert on the order about to be written, so the row wins.
	 */
	public function test_a_payment_row_landing_after_the_pre_read_refuses_the_restart(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$landed   = 0;
		$row      = $this->pending_card_row();
		add_filter(
			'rest_request_before_callbacks',
			function ( $response, $handler, $request ) use ( $order_id, $row, &$landed ) {
				if ( 'PUT' === $request->get_method() && '/wc/v3/orders/' . $order_id === $request->get_route() ) {
					$order = wc_get_order( $order_id );
					Ledger::instance()->save( $order, array( $row ), false );
					$order->save();
					++$landed;
				}
				return $response;
			},
			10,
			3
		);

		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );

		$this->assertSame( 1, $landed );
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order_id ) ) );
		$this->assertCount( 1, wc_get_order( $order_id )->get_items() );
	}

	/** The payment lock held elsewhere means a payment operation is running: the push succeeds without the restart. */
	public function test_a_held_payment_lock_forwards_the_update_without_the_restart(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$lock     = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order_id ) );
		try {
			$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );
		} finally {
			$lock->release( $order_id );
		}

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertCount( 1, wc_get_order( $order_id )->get_items() );
		// Released: the same restart now goes through.
		$response = $this->push_envelope( 'update', $this->restart_payload( $product->get_id() ), $this->order_revision( $order_id ) );
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::RESTART_GMT, $this->stored_date_gmt( $order_id ) );
	}

	/** A paid order is not a cart: a newer date on its push is ignored (the push may not set a paid status or change its lines either, ADR 0040). */
	public function test_update_of_a_paid_order_keeps_the_stored_date(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'processing', array( array( $product, 1 ) ) );
		$payload  = array(
			'date_created_gmt' => self::RESTART_GMT,
			'meta_data'        => $this->uuid_meta(),
		);

		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertSame( 'processing', wc_get_order( $order_id )->get_status() );
	}

	/** PHP would silently read 2026-02-31 as March 3; the documented invalid-date 400 fires instead. */
	public function test_update_with_a_calendar_invalid_date_created_gmt_is_a_400_and_writes_nothing(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$payload  = $this->restart_payload( $product->get_id() );

		$payload['date_created_gmt'] = '2026-02-31T12:00:00Z';
		$response                    = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_invalid_date_created_gmt', $response->get_data()['code'] );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}

	/** The same rule on the create path of this lane: nothing is created from an impossible date. */
	public function test_create_with_a_calendar_invalid_date_created_gmt_is_a_400_and_creates_nothing(): void {
		$product = ProductHelper::create_simple_product();
		$before  = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );

		$response = $this->push_envelope(
			'create',
			array(
				'status'           => 'pos-open',
				'date_created_gmt' => '2026-02-31T12:00:00Z',
				'line_items'       => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'meta_data'        => $this->uuid_meta(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_invalid_date_created_gmt', $response->get_data()['code'] );
		$this->assertSame( $before, count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) ) );
	}

	public function test_update_with_a_far_future_date_created_gmt_is_a_400_and_writes_nothing(): void {
		$product  = ProductHelper::create_simple_product();
		$order_id = $this->make_order( 'pos-open' );
		$payload  = $this->restart_payload( $product->get_id() );

		$payload['date_created_gmt'] = gmdate( 'Y-m-d\TH:i:s', time() + 2 * DAY_IN_SECONDS );
		$response                    = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_future_date_created_gmt', $response->get_data()['code'] );
		$this->assertSame( self::STORED_GMT, $this->stored_date_gmt( $order_id ) );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}
}
