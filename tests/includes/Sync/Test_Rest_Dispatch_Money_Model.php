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

use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
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

	/** Every server-owned meta key (ADR 0040 rule 2), spelled out so the pin does not trust the constant it pins. */
	protected function server_money_meta_keys(): array {
		return array( '_wcpos_payments', '_wcpos_payment_method', '_wcpos_payment_id', '_wcpos_payment_live', '_wcpos_commands', '_wcpos_completed_at', '_wcpos_completions', '_wcpos_gateway_attempts', '_wcpos_awaiting_customer', '_payment_method', '_payment_method_title', '_transaction_id', '_date_paid', '_paid_date' );
	}

	/** Every top-level wc/v3 money field, set to a value that would mark the order paid. */
	protected function server_money_fields(): array {
		return array(
			'set_paid'             => true,
			'date_paid'            => '2026-05-01T10:00:00',
			'date_paid_gmt'        => '2026-05-01T10:00:00',
			'transaction_id'       => 'txn-from-the-till',
			'payment_method'       => 'pos_cash',
			'payment_method_title' => 'Cash',
		);
	}

	/** The stored rows (id, key, value) of the given meta keys, read from the data store on a fresh order. */
	protected function stored_meta_rows( int $order_id, array $keys ): array {
		$order = wc_get_order( $order_id );
		$rows  = array();
		foreach ( $order->get_data_store()->read_meta( $order ) as $row ) {
			if ( in_array( $row->meta_key, $keys, true ) ) {
				$rows[] = array( (int) $row->meta_id, $row->meta_key, $row->meta_value );
			}
		}
		sort( $rows );

		return $rows;
	}

	protected function product( string $price = '10' ): \WC_Product {
		return \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array(
				'regular_price' => $price,
				'price'         => $price,
			)
		);
	}

	public function test_create_carrying_every_server_owned_key_stores_none_of_them(): void {
		// Arrange.
		$completed = 0;
		$counter   = static function () use ( &$completed ): void {
			++$completed;
		};
		add_action( 'woocommerce_payment_complete', $counter );
		$meta = $this->uuid_meta();
		foreach ( $this->server_money_meta_keys() as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => '_wcpos_payments' === $key ? (string) wp_json_encode( array( 'schema' => 1, 'payments' => array( $this->cash_payment( '10.00' ) + array( 'status' => 'captured' ) ) ) ) : 'from-the-till',
			);
		}
		$payload = $this->server_money_fields() + array(
			'line_items' => array(
				array(
					'product_id' => $this->product()->get_id(),
					'quantity'   => 1,
				),
			),
			'meta_data'  => $meta,
		);

		// Act.
		try {
			$response = $this->push_envelope( 'create', $payload );
		} finally {
			remove_action( 'woocommerce_payment_complete', $counter );
		}

		// Assert.
		$this->assertSame( 201, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order_id = (int) $response->get_data()['document']['id'];
		$order    = wc_get_order( $order_id );
		$this->assertSame( array(), $this->stored_meta_rows( $order_id, $this->server_money_meta_keys() ) );
		$this->assertNull( $order->get_date_paid( 'edit' ) );
		$this->assertSame( '', $order->get_payment_method( 'edit' ) );
		$this->assertSame( '', $order->get_payment_method_title( 'edit' ) );
		$this->assertSame( '', $order->get_transaction_id( 'edit' ) );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 0, $completed );
	}

	public function test_update_carrying_every_server_owned_key_by_key_and_by_meta_id_leaves_storage_unchanged(): void {
		// Arrange: a pos-open order with a captured row, an attempt in history and a stamp.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$order    = wc_get_order( $order_id );
		Ledger::instance()->save( $order, array( $this->cash_payment( '5.00' ) + array( 'status' => 'captured' ) ), false );
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( wp_generate_uuid4() => 'cancelled' ) );
		$order->update_meta_data( '_wcpos_awaiting_customer', array( 'attempt_id' => wp_generate_uuid4() ) );
		$order->save();
		$keys   = $this->server_money_meta_keys();
		$before = $this->stored_meta_rows( $order_id, $keys );
		$this->assertGreaterThanOrEqual( 4, count( $before ), 'the ledger, its index, the attempts and the stamp are stored' );
		$meta = $this->uuid_meta();
		foreach ( $keys as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => 'changed-by-key',
			);
		}
		foreach ( $before as $row ) {
			$meta[] = array(
				'id'    => $row[0],
				'key'   => $row[1],
				'value' => 'changed-by-id',
			);
			$meta[] = array(
				'id'    => $row[0],
				'key'   => 'harmless',
				'value' => 'renamed-by-id',
			);
			$meta[] = array(
				'id'    => $row[0],
				'key'   => $row[1],
				'value' => null,
			);
		}
		$payload = $this->server_money_fields() + array(
			'customer_note' => 'The push was applied',
			'meta_data'     => $meta,
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->stored_meta_rows( $order_id, $keys ) );
		$order = wc_get_order( $order_id );
		$this->assertNull( $order->get_date_paid( 'edit' ) );
		$this->assertSame( '', $order->get_payment_method( 'edit' ) );
		$this->assertSame( '', $order->get_transaction_id( 'edit' ) );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( 'The push was applied', $order->get_customer_note() );
		$this->assertSame( '', $order->get_meta( 'harmless' ) );
	}

	public function test_create_with_a_paid_status_is_refused_422_and_creates_nothing(): void {
		// Arrange.
		$payload = array(
			'status'     => 'processing',
			'line_items' => array(
				array(
					'product_id' => $this->product()->get_id(),
					'quantity'   => 1,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'create', $payload );

		// Assert.
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_status_not_pushable', $response->get_data()['code'] );
		$this->assertSame( 'processing', $response->get_data()['data']['requested'] );
		$this->assertSame( 0, $this->order_id_for_uuid() );
		$this->assertNull( ( new Mutation_Store() )->lookup( 'orders', $this->last_mutation_id ) );
	}

	/**
	 * @dataProvider server_owned_statuses
	 * @param string $status A status the server alone may set.
	 */
	public function test_update_to_processing_on_an_uncovered_order_is_refused_and_sets_no_date_paid( string $status ): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', array( 'status' => $status ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_status_not_pushable', $response->get_data()['code'] );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertNull( $order->get_date_paid( 'edit' ) );
		$this->assertNull( ( new Mutation_Store() )->lookup( 'orders', $this->last_mutation_id ) );
	}

	/** Paid, derived and failed statuses, in both spellings; `wc-completed` is also the default per-gateway POS status. */
	public static function server_owned_statuses(): array {
		return array(
			'processing'   => array( 'processing' ),
			'completed'    => array( 'completed' ),
			'wc-completed' => array( 'wc-completed' ),
			'refunded'     => array( 'refunded' ),
			'pos-partial'  => array( 'pos-partial' ),
			'failed'       => array( 'failed' ),
		);
	}

	/**
	 * @dataProvider pushable_statuses
	 * @param string $status A pushable status (D4).
	 */
	public function test_update_to_each_pushable_status_is_accepted( string $status ): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', array( 'status' => $status ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $status, wc_get_order( $order_id )->get_status() );
	}

	/** D4's set: the open statuses a till may push, and cancel. */
	public static function pushable_statuses(): array {
		return array(
			'pos-open'  => array( 'pos-open' ),
			'pending'   => array( 'pending' ),
			'on-hold'   => array( 'on-hold' ),
			'cancelled' => array( 'cancelled' ),
		);
	}

	/** A line-edit push of the order's own lines: its first line one unit up. */
	protected function quantity_edit( int $order_id, int $delta = 1 ): array {
		$order   = wc_get_order( $order_id );
		$item_id = array_key_first( $order->get_items() );

		return array(
			'line_items' => array(
				array(
					'id'       => $item_id,
					'quantity' => $order->get_item( $item_id )->get_quantity() + $delta,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);
	}

	public function test_the_order_lock_is_held_from_the_revision_read_through_the_write(): void {
		// Arrange: probes that try to take the order lock as another writer would.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$revision = $this->order_revision( $order_id );
		$probe    = static function () use ( $order_id ): bool {
			$other = new Order_Lock();
			$taken = $other->acquire( $order_id );
			if ( $taken ) {
				$other->release( $order_id );
			}
			return $taken;
		};
		$at_write = array();
		$at_read  = array();
		$write    = static function ( $order ) use ( $probe, &$at_write ) {
			$at_write[] = $probe();
			return $order;
		};
		$read     = static function ( $result, $server, $request ) use ( $probe, $order_id, &$at_read ) {
			if ( 'GET' === $request->get_method() && '/wc/v3/orders/' . $order_id === $request->get_route() ) {
				$at_read[] = $probe();
			}
			return $result;
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $write, 20 );
		add_filter( 'rest_pre_dispatch', $read, 10, 3 );

		// Act.
		try {
			$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id ), $revision );
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $write, 20 );
			remove_filter( 'rest_pre_dispatch', $read, 10 );
		}

		// Assert: the revision read (the first inner GET) and the write ran under the lock; it is free after.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( false ), $at_write );
		$this->assertFalse( $at_read[0] );
		$this->assertTrue( $probe() );
	}

	/**
	 * @dataProvider money_sensitive_edits
	 * @param string $field The money-sensitive field the stale push carries.
	 */
	public function test_capture_landing_before_the_push_is_a_revision_conflict( string $field ): void {
		// Arrange: the till reads the revision, then a cash capture completes the sale.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$revision = $this->order_revision( $order_id );
		$quantity = wc_get_order( $order_id )->get_item( array_key_first( wc_get_order( $order_id )->get_items() ) )->get_quantity();
		$recorded = Ledger::instance()->record( wc_get_order( $order_id ), $this->cash_payment( wc_get_order( $order_id )->get_total() ) );
		$this->assertIsArray( $recorded );
		$this->assertTrue( wc_get_order( $order_id )->is_paid() );
		$payload = 'currency' === $field
			? array(
				'currency'  => 'EUR',
				'meta_data' => $this->uuid_meta(),
			)
			: $this->quantity_edit( $order_id );

		// Act.
		$response = $this->push_envelope( 'update', $payload, $revision );

		// Assert.
		$this->assertSame( 409, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $response->get_data()['code'] );
		$this->assertNotSame( $revision, $response->get_data()['currentRevision'] );
		$order = wc_get_order( $order_id );
		$this->assertSame( $quantity, $order->get_item( array_key_first( $order->get_items() ) )->get_quantity() );
		$this->assertSame( get_woocommerce_currency(), $order->get_currency() );
	}

	public static function money_sensitive_edits(): array {
		return array(
			'line_items' => array( 'line_items' ),
			'currency'   => array( 'currency' ),
		);
	}

	/** The order's own document as the till holds it after a pull (dp 6, POS links, meta normalised). */
	protected function order_document( int $order_id ): array {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
		$request->set_param( 'dp', '6' );
		$data = Meta_Normalizer::normalize( rest_do_request( $request )->get_data() );

		return Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );
	}

	/** A paid order the way the server completes one: lines, stock reduced, then the given status. */
	protected function paid_order( string $status, array $line_items ): int {
		$order_id = $this->make_order( 'pending', $line_items );
		$order    = wc_get_order( $order_id );
		$order->payment_complete();
		$order = wc_get_order( $order_id );
		if ( $status !== $order->get_status() ) {
			$order->set_status( $status );
			$order->save();
		}

		return $order_id;
	}

	protected function first_item( int $order_id ): \WC_Order_Item_Product {
		$order = wc_get_order( $order_id );

		return $order->get_item( array_key_first( $order->get_items() ) );
	}

	/**
	 * @dataProvider locked_statuses
	 * @param string $status A status WooCommerce's is_editable() refuses.
	 */
	public function test_line_change_on_a_processing_order_is_refused_reopen_required( string $status ): void {
		// Arrange.
		$order_id = $this->paid_order( $status, array( array( $this->product(), 2 ) ) );
		$reduced  = $this->first_item( $order_id )->get_meta( '_reduced_stock', true );

		// Act.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( $status, $response->get_data()['data']['order_status'] );
		$this->assertSame( 2, $this->first_item( $order_id )->get_quantity() );
		$this->assertSame( $reduced, $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertSame( $status, wc_get_order( $order_id )->get_status() );
	}

	public static function locked_statuses(): array {
		return array(
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
			'cancelled'  => array( 'cancelled' ),
			'refunded'   => array( 'refunded' ),
			'failed'     => array( 'failed' ),
		);
	}

	public function test_full_document_with_unchanged_lines_on_a_paid_order_applies_the_note(): void {
		// Arrange: the full document a till holds, paid status and all.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 2 ) ) );
		$document = $this->order_document( $order_id );
		$this->assertSame( 'processing', $document['status'] );
		$document['customer_note'] = 'Gift wrap please';

		// Act.
		$response = $this->push_envelope( 'update', $document, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'Gift wrap please', $order->get_customer_note() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 2, $this->first_item( $order_id )->get_quantity() );
	}

	public function test_line_change_on_an_on_hold_order_is_applied(): void {
		// Arrange.
		$order_id = $this->make_order( 'on-hold', array( array( $this->product(), 2 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 3, $this->first_item( $order_id )->get_quantity() );
		$this->assertSame( 'on-hold', wc_get_order( $order_id )->get_status() );
	}

	public function test_line_change_on_a_layaway_is_applied_without_a_status_change(): void {
		// Arrange: a partly paid sale.
		$order_id = $this->make_order( 'pos-partial', array( array( $this->product(), 2 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 3, $this->first_item( $order_id )->get_quantity() );
		$this->assertSame( 'pos-partial', wc_get_order( $order_id )->get_status() );
	}

	public function test_reopen_and_line_change_in_one_push_is_one_write(): void {
		// Arrange: a paid sale with one captured cash row.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		Ledger::instance()->record( wc_get_order( $order_id ), $this->cash_payment( wc_get_order( $order_id )->get_total() ) );
		$this->assertSame( 'completed', wc_get_order( $order_id )->get_status() );
		$rows      = $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) );
		$date_paid = wc_get_order( $order_id )->get_date_paid( 'edit' );
		$this->assertNotNull( $date_paid );
		$payload           = $this->quantity_edit( $order_id );
		$payload['status'] = 'pos-open';

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( 3, $this->first_item( $order_id )->get_quantity() );
		$this->assertSame( $rows, $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) ) );
		$this->assertSame( $date_paid->getTimestamp(), $order->get_date_paid( 'edit' )->getTimestamp() );
		$reopen_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order_id ) ),
			static fn( $note ) => false !== strpos( $note->content, 're-opened via POS' )
		);
		$this->assertCount( 1, $reopen_notes );
	}

	public function test_reopen_with_cancelled_in_the_payload_does_not_unlock_lines(): void {
		// Arrange.
		$order_id          = $this->paid_order( 'processing', array( array( $this->product(), 2 ) ) );
		$payload           = $this->quantity_edit( $order_id );
		$payload['status'] = 'cancelled';

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( 'processing', wc_get_order( $order_id )->get_status() );
		$this->assertSame( 2, $this->first_item( $order_id )->get_quantity() );
	}

	protected function stock_product( int $quantity ): \WC_Product {
		return \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array(
				'regular_price'  => '10',
				'price'          => '10',
				'manage_stock'   => true,
				'stock_quantity' => $quantity,
			)
		);
	}

	protected function stock_of( \WC_Product $product ): int {
		return (int) wc_get_product( $product->get_id() )->get_stock_quantity();
	}

	/** A paid processing sale of two units of a product stocked at ten: stock 8, `_reduced_stock` 2. */
	protected function paid_sale_of_two( \WC_Product $product ): int {
		$order_id = $this->paid_order( 'processing', array( array( $product, 2 ) ) );
		$this->assertSame( 8, $this->stock_of( $product ) );
		$this->assertSame( '2', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );

		return $order_id;
	}

	public function test_adding_a_line_to_a_reopened_paid_order_reduces_that_product(): void {
		// Arrange.
		$first    = $this->stock_product( 10 );
		$second   = $this->stock_product( 5 );
		$order_id = $this->paid_sale_of_two( $first );
		$payload  = array(
			'status'     => 'pos-open',
			'line_items' => array(
				array(
					'id'       => $this->first_item( $order_id )->get_id(),
					'quantity' => 2,
				),
				array(
					'product_id' => $second->get_id(),
					'quantity'   => 1,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 8, $this->stock_of( $first ) );
		$this->assertSame( 4, $this->stock_of( $second ) );
		$this->assertCount( 2, wc_get_order( $order_id )->get_items() );
	}

	public function test_removing_a_line_from_a_reopened_paid_order_restores_its_stock(): void {
		// Arrange.
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_sale_of_two( $product );
		$payload  = array(
			'status'     => 'pos-open',
			'line_items' => array(
				array(
					'id'         => $this->first_item( $order_id )->get_id(),
					'product_id' => null,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 10, $this->stock_of( $product ) );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}

	public function test_changing_quantity_on_a_reopened_paid_order_moves_stock_by_the_difference(): void {
		// Arrange.
		$product           = $this->stock_product( 10 );
		$order_id          = $this->paid_sale_of_two( $product );
		$payload           = $this->quantity_edit( $order_id );
		$payload['status'] = 'pos-open';

		// Act: 2 -> 3 in the reopening push.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 7, $this->stock_of( $product ) );
		$this->assertSame( '3', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );

		// Act: 3 -> 1 on the reopened (pos-open, stock reduced) order.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id, -2 ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 9, $this->stock_of( $product ) );
		$this->assertSame( '1', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );
	}

	public function test_line_change_on_an_unpaid_open_cart_leaves_stock_alone(): void {
		// Arrange: an open cart whose stock was never reduced.
		$product  = $this->stock_product( 10 );
		$order_id = $this->make_order( 'pos-open', array( array( $product, 2 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 10, $this->stock_of( $product ) );
		$this->assertSame( 3, $this->first_item( $order_id )->get_quantity() );
	}

	protected function card_row( string $status, string $amount = '5.00', array $extra = array() ): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => 'pos_card',
			'kind'      => 'card',
			'amount'    => $amount,
			'status'    => $status,
		) + $extra;
	}

	/** Seed rows the way the server writes them, without projecting the order's status. */
	protected function seed_rows( int $order_id, array $rows ): void {
		Ledger::instance()->save( wc_get_order( $order_id ), $rows, false );
	}

	protected function push_status( int $order_id, string $status ) {
		return $this->push_envelope(
			'update',
			array(
				'status'    => $status,
				'meta_data' => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);
	}

	protected function assert_money_in_motion( $response, int $order_id, string $status ): void {
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_money_in_motion', $response->get_data()['code'] );
		$this->assertSame( $status, wc_get_order( $order_id )->get_status() );
	}

	public function test_reopen_with_a_pending_row_is_refused_money_in_motion(): void {
		// Arrange.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 1 ) ) );
		$row      = $this->card_row( 'pending' );
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'processing' );
		$this->assertSame( $row['id'], $response->get_data()['data']['payment_id'] );
	}

	public function test_reopen_with_an_authorized_row_is_refused(): void {
		// Arrange.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 1 ) ) );
		$row      = $this->card_row( 'authorized' );
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'processing' );
		$this->assertSame( $row['id'], $response->get_data()['data']['payment_id'] );
	}

	public function test_reopen_with_a_sent_attempt_is_refused(): void {
		// Arrange.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 1 ) ) );
		$attempt  = wp_generate_uuid4();
		$order    = wc_get_order( $order_id );
		$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, array( $attempt => 'sent' ) );
		$order->save();

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'processing' );
		$this->assertSame( $attempt, $response->get_data()['data']['attempt_id'] );
	}

	public function test_reopen_with_a_pending_refund_allocation_is_refused(): void {
		// Arrange.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 1 ) ) );
		$row      = $this->card_row(
			'captured',
			'10.00',
			array(
				'refunds' => array(
					array(
						'id'     => 1234,
						'amount' => '2.00',
						'status' => 'pending',
					),
				),
			)
		);
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'processing' );
		$this->assertSame( $row['id'], $response->get_data()['data']['payment_id'] );
	}

	public function test_reopen_of_a_cancelled_order_with_a_standing_authorization_is_refused(): void {
		// Arrange.
		$order_id = $this->make_order( 'cancelled', array( array( $this->product(), 1 ) ) );
		$this->seed_rows( $order_id, array( $this->card_row( 'authorized' ) ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'cancelled' );
	}

	public function test_reopen_of_a_paid_sale_with_captured_rows_is_accepted_with_rows_and_balance_unchanged(): void {
		// Arrange: two captured tenders cover the total and complete the sale.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		Ledger::instance()->save( wc_get_order( $order_id ), array( $this->cash_payment( '10.00' ) + array( 'status' => 'captured' ), $this->cash_payment( '10.00' ) + array( 'status' => 'captured' ) ) );
		$order = wc_get_order( $order_id );
		$order->set_status( 'processing' );
		$order->save();
		$rows      = $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) );
		$summary   = Ledger::instance()->summary( wc_get_order( $order_id ) );
		$date_paid = wc_get_order( $order_id )->get_date_paid( 'edit' );
		$this->assertNotNull( $date_paid );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( $rows, $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) ) );
		$after = Ledger::instance()->summary( $order );
		$this->assertSame( $summary['paid'], $after['paid'] );
		$this->assertSame( $summary['balance'], $after['balance'] );
		$this->assertSame( $date_paid->getTimestamp(), $order->get_date_paid( 'edit' )->getTimestamp() );
		$reopen_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order_id ) ),
			static fn( $note ) => false !== strpos( $note->content, 're-opened via POS' )
		);
		$this->assertCount( 1, $reopen_notes );
	}

	public function test_cancel_with_a_pending_row_is_refused_money_in_motion(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$this->seed_rows( $order_id, array( $this->card_row( 'pending' ) ) );

		// Act.
		$response = $this->push_status( $order_id, 'cancelled' );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'pos-open' );
	}

	public function test_cancel_with_only_captured_rows_is_accepted(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$this->seed_rows( $order_id, array( $this->cash_payment( '5.00' ) + array( 'status' => 'captured' ) ) );

		// Act.
		$response = $this->push_status( $order_id, 'cancelled' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'cancelled', wc_get_order( $order_id )->get_status() );
	}

	protected function currency_and_quantity_edit( int $order_id ): array {
		$payload             = $this->quantity_edit( $order_id );
		$payload['currency'] = 'EUR';

		return $payload;
	}

	/** Push a currency change and a quantity change onto an open order holding one row of the given status. */
	protected function assert_currency_locked_by_a_row( string $status ): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$this->seed_rows( $order_id, array( $this->cash_payment( '5.00' ) + array( 'status' => $status ) ) );

		// Act.
		$response = $this->push_envelope( 'update', $this->currency_and_quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_currency_locked', $response->get_data()['code'] );
		$this->assertSame( get_woocommerce_currency(), wc_get_order( $order_id )->get_currency() );
		$this->assertSame( 2, $this->first_item( $order_id )->get_quantity() );
	}

	public function test_currency_change_with_a_captured_row_is_refused_currency_locked(): void {
		$this->assert_currency_locked_by_a_row( 'captured' );
	}

	/** A row that no longer counts still names the currency the money moved in. */
	public function test_currency_change_with_a_voided_row_is_still_refused(): void {
		$this->assert_currency_locked_by_a_row( 'voided' );
	}

	public function test_currency_change_with_an_attempt_in_history_is_refused(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$order    = wc_get_order( $order_id );
		$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, array( wp_generate_uuid4() => 'cancelled' ) );
		$order->save();

		// Act.
		$response = $this->push_envelope( 'update', $this->currency_and_quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_currency_locked', $response->get_data()['code'] );
		$this->assertSame( get_woocommerce_currency(), wc_get_order( $order_id )->get_currency() );
	}

	public function test_currency_change_on_an_order_with_no_money_is_applied(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );

		// Act.
		$response = $this->push_envelope( 'update', $this->currency_and_quantity_edit( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'EUR', wc_get_order( $order_id )->get_currency() );
		$this->assertSame( 3, $this->first_item( $order_id )->get_quantity() );
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
