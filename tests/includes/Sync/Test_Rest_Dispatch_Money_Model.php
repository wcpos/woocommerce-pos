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
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Tests\Helpers\Adopt_Refund_Test_Gateway;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Money_Model extends Sync_REST_Store_Test_Case {
	use Money_Route_Envelope;

	protected const REC = '6c0d4e2a-7b1f-4c3d-9e5a-1f2b3c4d5e6f';
	private $mutation_seq = 0;
	/** @var string Mutation id of the last push. */
	protected $last_mutation_id = '';
	/** @var bool Whether this test turned decimal quantities on. */
	private $decimal_quantities = false;

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		if ( $this->decimal_quantities ) {
			remove_filter( 'woocommerce_pos_general_settings', array( $this, 'enable_decimal_qty_setting' ) );
			remove_filter( 'woocommerce_stock_amount', 'floatval' );
			add_filter( 'woocommerce_stock_amount', 'intval' );
			$this->decimal_quantities = false;
		}
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
		return array( '_wcpos_payments', '_wcpos_payment_method', '_wcpos_payment_id', '_wcpos_payment_live', '_wcpos_commands', '_wcpos_completed_at', '_wcpos_completions', '_wcpos_fiscal_pending', '_wcpos_gateway_attempts', '_wcpos_awaiting_customer', '_payment_method', '_payment_method_title', '_transaction_id', '_date_paid', '_paid_date', '_wcpos_checkout_state' );
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
		$ledger = array(
			'schema'   => 1,
			'payments' => array( $this->cash_payment( '10.00' ) + array( 'status' => 'captured' ) ),
		);
		foreach ( $this->server_money_meta_keys() as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => '_wcpos_payments' === $key ? (string) wp_json_encode( $ledger ) : 'from-the-till',
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

	/** Turn on decimal quantities for this test; tearDown() unwinds both filters. */
	protected function use_decimal_quantities(): void {
		add_filter( 'woocommerce_pos_general_settings', array( $this, 'enable_decimal_qty_setting' ) );
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
		$this->decimal_quantities = true;
	}

	/**
	 * The decimal_qty setting, on.
	 *
	 * @param array $settings General settings.
	 */
	public function enable_decimal_qty_setting( $settings ) {
		$settings['decimal_qty'] = true;

		return $settings;
	}

	protected function decimal_stock_of( \WC_Product $product ): float {
		return (float) wc_get_product( $product->get_id() )->get_stock_quantity();
	}

	/**
	 * A sale of 6.3 units of a product stocked at ten, paid in cash at the till and completed into
	 * processing: stock 3.7, `_reduced_stock` 6.3. Paid through the ledger rather than paid_order(),
	 * because a sale paid outside the ledger refuses a later record as already paid.
	 */
	protected function paid_decimal_sale( \WC_Product $product ): int {
		$this->use_pos_cash_order_status( 'wc-processing' );
		$order_id = $this->make_order( 'pos-open', array( array( $product, 6.3 ) ) );
		$recorded = $this->record_cash_for_total( $order_id );
		$this->assertSame( 200, $recorded->get_status(), 'Response: ' . wp_json_encode( $recorded->get_data() ) );
		$this->assertSame( 'processing', wc_get_order( $order_id )->get_status() );
		$this->assertEqualsWithDelta( 3.7, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertSame( '6.3', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertTrue( (bool) wc_get_order( $order_id )->get_data_store()->get_stock_reduced( $order_id ) );

		return $order_id;
	}

	/**
	 * Store Cash's POS Order Status.
	 *
	 * @param string $status The configured status, `wc-` prefixed.
	 */
	protected function use_pos_cash_order_status( string $status ): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'enabled'      => true,
						'order_status' => $status,
					),
					'pos_card' => array( 'enabled' => true ),
				),
			)
		);
	}

	/** Record cash for the order's current total. */
	protected function record_cash_for_total( int $order_id ) {
		return $this->record_cash( $order_id, (string) wc_get_order( $order_id )->get_total() );
	}

	protected function record_cash( int $order_id, string $amount ) {
		$record = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params( $this->envelope( $order_id, array( 'payment' => $this->cash_payment( $amount ) ) ) );

		return $this->server->dispatch( $record );
	}

	/**
	 * Record cash for whatever the ledger says is still owed, then close the sale. A quantity-only
	 * line edit keeps the line's stored total (the till sends totals with it), so the balance is read
	 * rather than assumed.
	 */
	protected function settle_and_close( int $order_id ) {
		$balance = (string) Ledger::instance()->summary( wc_get_order( $order_id ) )['balance'];
		if ( (float) $balance > 0 ) {
			$recorded = $this->record_cash( $order_id, $balance );
			$this->assertSame( 200, $recorded->get_status(), 'Response: ' . wp_json_encode( $recorded->get_data() ) );
		}

		return $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );
	}

	/** Reopen a paid decimal sale and set its first line to the given quantity, in one push. */
	protected function reopen_with_first_line_quantity( int $order_id, float $quantity ) {
		$payload = array(
			'status'     => 'pos-open',
			'line_items' => array(
				array(
					'id'       => $this->first_item( $order_id )->get_id(),
					'quantity' => $quantity,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		return $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );
	}

	public function test_lowering_a_decimal_quantity_on_a_reopened_paid_order_returns_the_difference(): void {
		// Arrange.
		$this->use_decimal_quantities();
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_decimal_sale( $product );

		// Act: 6.3 -> 5.8 in the reopening push.
		$response = $this->reopen_with_first_line_quantity( $order_id, 5.8 );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertEqualsWithDelta( 4.2, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertSame( '5.8', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );

		// Act: pay anything still owed and close.
		$closed = $this->settle_and_close( $order_id );

		// Assert: the completion does not reduce again.
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$this->assertEqualsWithDelta( 4.2, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertSame( '5.8', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
	}

	public function test_raising_a_decimal_quantity_on_a_reopened_paid_order_reduces_the_difference(): void {
		// Arrange.
		$this->use_decimal_quantities();
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_decimal_sale( $product );

		// Act: 6.3 -> 6.8 in the reopening push.
		$response = $this->reopen_with_first_line_quantity( $order_id, 6.8 );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertEqualsWithDelta( 3.2, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertSame( '6.8', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );

		// Act: pay anything still owed and close.
		$closed = $this->settle_and_close( $order_id );

		// Assert: the completion does not reduce again.
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$this->assertEqualsWithDelta( 3.2, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertSame( '6.8', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
	}

	public function test_adding_a_decimal_line_to_a_reopened_paid_order_reduces_that_product(): void {
		// Arrange.
		$this->use_decimal_quantities();
		$first    = $this->stock_product( 10 );
		$second   = $this->stock_product( 5 );
		$order_id = $this->paid_decimal_sale( $first );
		$payload  = array(
			'status'     => 'pos-open',
			'line_items' => array(
				array(
					'id'       => $this->first_item( $order_id )->get_id(),
					'quantity' => 6.3,
				),
				array(
					'product_id' => $second->get_id(),
					'quantity'   => 1.5,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );
		$items    = array_values( wc_get_order( $order_id )->get_items() );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertCount( 2, $items );
		$this->assertSame( $second->get_id(), $items[1]->get_product_id() );
		$this->assertEqualsWithDelta( 3.7, $this->decimal_stock_of( $first ), 0.000001 );
		$this->assertEqualsWithDelta( 3.5, $this->decimal_stock_of( $second ), 0.000001 );
		$this->assertSame( '1.5', (string) $items[1]->get_meta( '_reduced_stock', true ) );

		// Act: pay anything still owed and close.
		$closed = $this->settle_and_close( $order_id );
		$items    = array_values( wc_get_order( $order_id )->get_items() );

		// Assert: the completion does not reduce again.
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$this->assertEqualsWithDelta( 3.7, $this->decimal_stock_of( $first ), 0.000001 );
		$this->assertEqualsWithDelta( 3.5, $this->decimal_stock_of( $second ), 0.000001 );
		$this->assertSame( '6.3', (string) $items[0]->get_meta( '_reduced_stock', true ) );
		$this->assertSame( '1.5', (string) $items[1]->get_meta( '_reduced_stock', true ) );
	}

	public function test_removing_a_decimal_line_from_a_reopened_paid_order_restores_its_stock(): void {
		// Arrange.
		$this->use_decimal_quantities();
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_decimal_sale( $product );
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
		$this->assertEqualsWithDelta( 10.0, $this->decimal_stock_of( $product ), 0.000001 );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}

	public function test_a_sale_completed_into_a_configured_pending_status_reopened_edited_and_closed_ends_at_the_final_quantities(): void {
		// Arrange: Cash's POS Order Status stored as Pending; cash covers a sale of two.
		$this->use_pos_cash_order_status( 'wc-pending' );
		$first    = $this->stock_product( 10 );
		$second   = $this->stock_product( 5 );
		$order_id = $this->make_order( 'pos-open', array( array( $first, 2 ) ) );
		$record   = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params( $this->envelope( $order_id, array( 'payment' => $this->cash_payment( '20.00' ) ) ) );
		$this->assertSame( 200, $this->server->dispatch( $record )->get_status() );
		$this->assertSame( 'pending', wc_get_order( $order_id )->get_status() );
		$this->assertSame( 8, $this->stock_of( $first ) );
		$this->assertSame( '2', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertTrue( (bool) wc_get_order( $order_id )->get_data_store()->get_stock_reduced( $order_id ) );

		// Act: reopen, drop a unit (2 -> 1), add a line of the second product.
		$reopen   = $this->push_status( $order_id, 'pos-open' );
		$edit     = $this->push_envelope( 'update', $this->quantity_edit( $order_id, -1 ), $this->order_revision( $order_id ) );
		$add      = $this->push_envelope(
			'update',
			array(
				'line_items' => array(
					array(
						'id'       => $this->first_item( $order_id )->get_id(),
						'quantity' => 1,
					),
					array(
						'product_id' => $second->get_id(),
						'quantity'   => 1,
					),
				),
				'meta_data'  => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);
		$items    = array_values( wc_get_order( $order_id )->get_items() );

		// Assert.
		$this->assertSame( 200, $reopen->get_status(), 'Response: ' . wp_json_encode( $reopen->get_data() ) );
		$this->assertSame( 200, $edit->get_status(), 'Response: ' . wp_json_encode( $edit->get_data() ) );
		$this->assertSame( 200, $add->get_status(), 'Response: ' . wp_json_encode( $add->get_data() ) );
		$this->assertCount( 2, $items );
		$this->assertSame( 9, $this->stock_of( $first ) );
		$this->assertSame( 4, $this->stock_of( $second ) );
		$this->assertSame( '1', (string) $items[0]->get_meta( '_reduced_stock', true ) );
		$this->assertSame( '1', (string) $items[1]->get_meta( '_reduced_stock', true ) );

		// Act: pay anything still owed and close.
		$closed = $this->settle_and_close( $order_id );
		$items  = array_values( wc_get_order( $order_id )->get_items() );

		// Assert.
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$this->assertSame( 'pending', wc_get_order( $order_id )->get_status() );
		$this->assertSame( 9, $this->stock_of( $first ) );
		$this->assertSame( 4, $this->stock_of( $second ) );
		$this->assertSame( '1', (string) $items[0]->get_meta( '_reduced_stock', true ) );
		$this->assertSame( '1', (string) $items[1]->get_meta( '_reduced_stock', true ) );
		$this->assertTrue( (bool) wc_get_order( $order_id )->get_data_store()->get_stock_reduced( $order_id ) );
	}

	public function test_an_unpaid_open_cart_edited_then_closed_reduces_once_at_the_final_quantities(): void {
		// Arrange: an open cart whose stock was never reduced.
		$product  = $this->stock_product( 10 );
		$order_id = $this->make_order( 'pos-open', array( array( $product, 2 ) ) );

		// Act: 2 -> 3.
		$response = $this->push_envelope( 'update', $this->quantity_edit( $order_id, 1 ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 10, $this->stock_of( $product ) );
		$this->assertSame( '', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );

		// Act: pay the total and close.
		$recorded = $this->record_cash_for_total( $order_id );
		$closed   = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );

		// Assert.
		$this->assertSame( 200, $recorded->get_status(), 'Response: ' . wp_json_encode( $recorded->get_data() ) );
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 1, $closed->get_data()['completion']['n'] );
		$this->assertSame( 7, $this->stock_of( $product ) );
		$this->assertSame( '3', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
		$this->assertTrue( (bool) wc_get_order( $order_id )->get_data_store()->get_stock_reduced( $order_id ) );
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

	/**
	 * POST a money command with a fresh envelope.
	 *
	 * @param string $path     Route path.
	 * @param int    $order_id Order id.
	 * @param array  $body     Body.
	 */
	protected function money_command( string $path, int $order_id, array $body = array() ) {
		$request = $this->wp_rest_post_request( $path );
		$request->set_body_params( $this->envelope( $order_id, $body ) );
		return $this->server->dispatch( $request );
	}

	/** Pin 5: a money command moves the revision a later push compares against, and both lanes agree on it. */
	public function test_a_record_moves_the_revision_a_later_push_compares_against(): void {
		// Arrange: the push lane's revision of an open order, and the money routes' own.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$b0       = $this->order_revision( $order_id );
		$this->assertSame( $b0, Order_Serializer::current_state( $order_id )['revision'] );

		// Act: cash is recorded; a note push built on b0 follows, then the same push on the new revision.
		$record = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params(
			array(
				'payment'  => $this->cash_payment( '5.00' ),
				'revision' => $b0,
			)
		);
		$recorded = $this->server->dispatch( $record );
		$r1       = $recorded->get_data()['revision'];
		$note     = array(
			'customer_note' => 'Gift wrap',
			'meta_data'     => $this->uuid_meta(),
		);
		$stale    = $this->push_envelope( 'update', $note, $b0 );
		$fresh    = $this->push_envelope( 'update', $note, $r1 );

		// Assert.
		$this->assertSame( 200, $recorded->get_status(), 'Response: ' . wp_json_encode( $recorded->get_data() ) );
		$this->assertNotSame( $b0, $r1 );
		$this->assertSame( 409, $stale->get_status(), 'Response: ' . wp_json_encode( $stale->get_data() ) );
		$this->assertSame( 'woo_rxdb_sync_conflict', $stale->get_data()['code'] );
		$this->assertSame( $r1, $stale->get_data()['currentRevision'] );
		$this->assertSame( 200, $fresh->get_status(), 'Response: ' . wp_json_encode( $fresh->get_data() ) );
		$this->assertSame( 'Gift wrap', wc_get_order( $order_id )->get_customer_note() );
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

	/** Completed on register A, reopened by a push and closed on register B: entry 2 names B (§4.4). */
	public function test_reopen_and_close_on_another_register_writes_entry_2_naming_it(): void {
		// Arrange: a cash sale completed on register A.
		$register_a = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$register_b = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
		$order_id   = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$record     = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params(
			$this->envelope(
				$order_id,
				array(
					'payment' => $this->cash_payment( '20.00' ),
					'provenance' => array( 'register_id' => $register_a ),
				)
			)
		);
		$this->assertSame( 200, $this->server->dispatch( $record )->get_status() );
		$first = Completion_History::of( wc_get_order( $order_id ) )->entries();

		// Act: the push reopens it; register B closes it.
		$reopen = $this->push_status( $order_id, 'pos-open' );
		$close  = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/close' );
		$close->set_body_params( $this->envelope( $order_id, array( 'provenance' => array( 'register_id' => $register_b ) ) ) );
		$closed = $this->server->dispatch( $close );

		// Assert.
		$this->assertSame( 200, $reopen->get_status(), 'Response: ' . wp_json_encode( $reopen->get_data() ) );
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$history = Completion_History::of( wc_get_order( $order_id ) )->entries();
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertSame( $register_a, $history[0]['register'] );
		$this->assertSame( $register_b, $history[1]['register'] );
		$this->assertSame( $first[0]['id'], $history[0]['id'] );
		$this->assertSame( $first[0]['at_gmt'], $history[0]['at_gmt'] );
		$this->assertNotNull( $history[0]['reopened_at_gmt'] );
		$this->assertTrue( wc_get_order( $order_id )->is_paid() );
	}

	/**
	 * A sale a WooCommerce gateway paid outside the ledger: processing, date_paid, a transaction id.
	 *
	 * @param string $price  Line price (two units).
	 * @param string $status The paid status the gateway landed.
	 */
	protected function gateway_paid_order( string $price = '50', string $status = 'processing' ): int {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'with_adopt_gateway' ) );
		\WC_Payment_Gateways::instance()->init();
		Adopt_Refund_Test_Gateway::$calls = 0;
		$order_id = $this->make_order( 'pos-open', array( array( $this->product( $price ), 2 ) ) );
		$order    = wc_get_order( $order_id );
		$order->set_payment_method( 'wcpos_adopt_refund_test' );
		$order->set_transaction_id( 'txn_1' );
		$order->set_date_paid( time() - HOUR_IN_SECONDS );
		$order->set_status( $status );
		$order->save();
		return $order_id;
	}

	/**
	 * Register the refund-capable gateway fixture.
	 *
	 * @param array $gateways Gateway classes.
	 */
	public function with_adopt_gateway( array $gateways ): array {
		return array_merge( $gateways, array( Adopt_Refund_Test_Gateway::class ) );
	}

	/** Reopening a gateway-paid sale adopts its tender as a captured row, with its refunds (D5). */
	public function test_reopen_of_a_gateway_paid_order_adopts_its_tender_as_a_captured_row(): void {
		// Arrange.
		$order_id  = $this->gateway_paid_order();
		$refund    = wc_create_refund(
			array(
				'order_id' => $order_id,
				'amount' => '20.00',
				'refund_payment' => false,
			)
		);
		$date_paid = wc_get_order( $order_id )->get_date_paid( 'edit' )->getTimestamp();

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert: storage, not the push's object.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$stored = $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) );
		$this->assertCount( 1, $stored );
		$rows = json_decode( maybe_unserialize( $stored[0][2] ), true )['payments'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'woocommerce', $rows[0]['source'] );
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( 'webview', $rows[0]['capture_mode'] );
		$this->assertSame( '100.00', $rows[0]['amount'] );
		$this->assertSame( 'txn_1', $rows[0]['provider_refs']['transaction_id'] );
		$this->assertSame(
			array(
				array(
					'id' => $refund->get_id(),
					'amount' => '20.00',
					'status' => 'succeeded',
					'provider_ref' => null,
				),
			),
			$rows[0]['refunds']
		);
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( $date_paid, $order->get_date_paid( 'edit' )->getTimestamp() );
		$this->assertSame( array(), array_filter( Completion_History::of( $order )->entries(), static fn( $entry ) => empty( $entry['legacy'] ) ) );
		$this->assertSame( '20.00', Ledger::instance()->summary( $order )['balance'] );
	}

	/** A pre-ledger sale completed onto a configured on-hold status is reopened by a push: adoption, marking, close. */
	public function test_a_pre_ledger_sale_completed_onto_on_hold_is_reopened_adopted_and_closes_as_completion_2(): void {
		// Arrange: the gateway's POS Order Status stored as On hold; paid outside the ledger.
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash'                => array( 'enabled' => true ),
					'wcpos_adopt_refund_test' => array(
						'enabled'      => true,
						'order_status' => 'wc-on-hold',
					),
				),
			)
		);
		$order_id = $this->gateway_paid_order( '50', 'on-hold' );
		$this->assertSame( 'on-hold', wc_get_order( $order_id )->get_status() );
		$this->assertNotNull( wc_get_order( $order_id )->get_date_paid( 'edit' ) );
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order_id ) )->entries() );

		// Act: reopen, drop a unit, close.
		$reopen = $this->push_status( $order_id, 'pos-open' );
		$rows   = Ledger::instance()->read( wc_get_order( $order_id ) );
		$marked = Completion_History::of( wc_get_order( $order_id ) )->entries();
		$edit   = $this->quantity_edit( $order_id, -1 );
		// The till sends the line's money with its quantity; wc/v3 does not reprice an updated line.
		$edit['line_items'][0] += array(
			'subtotal' => '50.00',
			'total'    => '50.00',
		);
		$edit   = $this->push_envelope( 'update', $edit, $this->order_revision( $order_id ) );
		$closed = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );

		// Assert.
		$this->assertSame( 200, $reopen->get_status(), 'Response: ' . wp_json_encode( $reopen->get_data() ) );
		$this->assertSame( array( 'woocommerce' ), array_column( $rows, 'source' ) );
		$this->assertCount( 1, $marked );
		$this->assertNotNull( $marked[0]['reopened_at_gmt'] );
		$this->assertSame( 200, $edit->get_status(), 'Response: ' . wp_json_encode( $edit->get_data() ) );
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$this->assertSame( '50.00', $closed->get_data()['order']['refund_due'] );
	}

	/** The adopted tender cannot be voided from the till and refunds through its gateway. */
	public function test_adopted_tender_is_not_voidable_and_is_refundable(): void {
		// Arrange.
		$order_id = $this->gateway_paid_order();
		$this->assertSame( 200, $this->push_status( $order_id, 'pos-open' )->get_status() );
		$row = Ledger::instance()->read( wc_get_order( $order_id ) )[0];

		// Act.
		$void   = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/payments/' . $row['id'] . '/void', $order_id );
		$refund = wc_create_refund(
			array(
				'order_id' => $order_id,
				'amount' => '10.00',
				'refund_payment' => false,
			)
		);
		$allocate = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments/' . $row['id'] . '/refund' );
		$allocate->set_body_params(
			$this->envelope(
				$order_id,
				array(
					'refund_id' => $refund->get_id(),
					'amount' => '10.00',
				)
			)
		);
		$allocated = $this->server->dispatch( $allocate );

		// Assert.
		$this->assertSame( 409, $void->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $void->get_data()['code'] );
		$this->assertSame( 200, $allocated->get_status(), 'Response: ' . wp_json_encode( $allocated->get_data() ) );
		$this->assertSame( 'succeeded', $allocated->get_data()['payment']['refunds'][0]['status'] );
		$this->assertSame( 1, Adopt_Refund_Test_Gateway::$calls );
	}

	/** A second reopen finds the adopted row and adopts nothing more. */
	public function test_a_second_reopen_adopts_nothing_more(): void {
		// Arrange: adopted, closed, reopened again.
		$order_id = $this->gateway_paid_order();
		$this->assertSame( 200, $this->push_status( $order_id, 'pos-open' )->get_status() );
		$closed = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$before = $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) ) );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order_id ) ) );
	}

	/** A sale the ledger paid already has its rows: a reopen adopts nothing. */
	public function test_reopen_of_a_ledger_paid_sale_adopts_nothing(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$record   = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params( $this->envelope( $order_id, array( 'payment' => $this->cash_payment( '20.00' ) ) ) );
		$this->assertSame( 200, $this->server->dispatch( $record )->get_status() );
		$before = $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) );

		// Act.
		$response = $this->push_status( $order_id, 'pos-open' );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->stored_meta_rows( $order_id, array( Ledger::META_KEY ) ) );
		$this->assertSame( array( 'app' ), array_column( Ledger::instance()->read( wc_get_order( $order_id ) ), 'source' ) );
	}

	/** A sale completed into a configured on-hold status is marked reopened by a push to pos-open, so close writes entry 2. */
	public function test_a_sale_completed_into_a_configured_on_hold_status_reopens_and_closes_as_completion_2(): void {
		// Arrange: Cash's POS Order Status stored as On hold; cash covers the sale.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'enabled'      => true,
						'order_status' => 'wc-on-hold',
					),
					'pos_card' => array( 'enabled' => true ),
				),
			)
		);
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$record   = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order_id . '/payments' );
		$record->set_body_params( $this->envelope( $order_id, array( 'payment' => $this->cash_payment( '20.00' ) ) ) );
		$this->assertSame( 200, $this->server->dispatch( $record )->get_status() );
		$this->assertSame( 'on-hold', wc_get_order( $order_id )->get_status() );
		$this->assertCount( 1, Completion_History::of( wc_get_order( $order_id ) )->entries() );

		// Act: reopen, drop a unit, close.
		$reopen  = $this->push_status( $order_id, 'pos-open' );
		$marked  = Completion_History::of( wc_get_order( $order_id ) )->entries();
		$edit    = $this->push_envelope( 'update', $this->quantity_edit( $order_id, -1 ), $this->order_revision( $order_id ) );
		$closed  = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );

		// Assert.
		$this->assertSame( 200, $reopen->get_status(), 'Response: ' . wp_json_encode( $reopen->get_data() ) );
		$this->assertNotNull( $marked[0]['reopened_at_gmt'] );
		$this->assertSame( 200, $edit->get_status(), 'Response: ' . wp_json_encode( $edit->get_data() ) );
		$this->assertSame( 200, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 2, $closed->get_data()['completion']['n'] );
		$history = Completion_History::of( wc_get_order( $order_id ) )->entries();
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertIsArray( ( new Fiscal_Record_Store() )->find_sale( $order_id, $history[1]['id'] ) );
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

	/**
	 * Push a delete of the order on its current revision.
	 *
	 * @param int       $order_id Order id.
	 * @param bool|null   $force    The envelope's `force`, or null to leave it out (trash).
	 * @param string|null $revision The base revision, or null to read the current one.
	 */
	protected function push_delete( int $order_id, ?bool $force = null, ?string $revision = null ) {
		$this->last_mutation_id = sprintf( 'e5f6a7b8-5555-4666-8777-%012d', ++$this->mutation_seq );
		$envelope               = array(
			'mutationId'   => $this->last_mutation_id,
			'operation'    => 'delete',
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => $revision ?? $this->order_revision( $order_id ),
		);
		if ( null !== $force ) {
			$envelope['force'] = $force;
		}
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WCPOS-Protocol', '3' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	public function test_delete_with_a_pending_row_is_refused_money_in_motion_and_deletes_nothing(): void {
		// Arrange: an open sale whose card leg is still pending.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$row      = $this->card_row( 'pending' );
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		$response = $this->push_delete( $order_id );

		// Assert: refused like a cancel, naming the leg; the order is not trashed.
		$this->assert_money_in_motion( $response, $order_id, 'pos-open' );
		$this->assertSame( $row['id'], $response->get_data()['data']['payment_id'] );
		$this->assertNull( $response->get_data()['data']['attempt_id'] );
	}

	public function test_delete_with_a_sent_attempt_is_refused_money_in_motion(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$attempt  = wp_generate_uuid4();
		$order    = wc_get_order( $order_id );
		$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, array( $attempt => 'sent' ) );
		$order->save();

		// Act.
		$response = $this->push_delete( $order_id );

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'pos-open' );
		$this->assertNull( $response->get_data()['data']['payment_id'] );
		$this->assertSame( $attempt, $response->get_data()['data']['attempt_id'] );
	}

	public function test_force_delete_with_an_authorized_row_is_refused_and_restores_no_stock(): void {
		// Arrange: a paid sale of two (stock 8) holding an authorized leg; delete restores stock.
		add_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_true' );
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_sale_of_two( $product );
		$row      = $this->card_row( 'authorized' );
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		try {
			$response = $this->push_delete( $order_id, true );
		} finally {
			remove_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_true' );
		}

		// Assert.
		$this->assert_money_in_motion( $response, $order_id, 'processing' );
		$this->assertSame( $row['id'], $response->get_data()['data']['payment_id'] );
		$this->assertSame( 8, $this->stock_of( $product ) );
	}

	public function test_delete_with_only_captured_rows_is_accepted(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 2 ) ) );
		$this->seed_rows( $order_id, array( $this->cash_payment( '5.00' ) + array( 'status' => 'captured' ), $this->card_row( 'voided' ) ) );

		// Act.
		$response = $this->push_delete( $order_id );

		// Assert: trashed; the rows stay on the order.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'trash', wc_get_order( $order_id )->get_status() );
		$this->assertCount( 2, Ledger::instance()->read( wc_get_order( $order_id ) ) );
	}

	public function test_delete_holds_the_order_lock_from_the_revision_read_through_the_forward(): void {
		// Arrange: a probe that tries to take the order lock as another writer would.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$probe    = static function () use ( $order_id ): bool {
			$other = new Order_Lock();
			$taken = $other->acquire( $order_id );
			if ( $taken ) {
				$other->release( $order_id );
			}
			return $taken;
		};
		$seen     = array();
		$spy      = static function ( $result, $server, $request ) use ( $probe, $order_id, &$seen ) {
			if ( '/wc/v3/orders/' . $order_id === $request->get_route() ) {
				$seen[ $request->get_method() ][] = $probe();
			}
			return $result;
		};
		$revision = $this->order_revision( $order_id );
		add_filter( 'rest_pre_dispatch', $spy, 10, 3 );

		// Act.
		try {
			$response = $this->push_delete( $order_id, null, $revision );
		} finally {
			remove_filter( 'rest_pre_dispatch', $spy, 10 );
		}

		// Assert: the revision read (the first inner GET) and the forwarded DELETE ran under the lock; it is free after.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $seen['GET'][0] );
		$this->assertSame( array( false ), $seen['DELETE'] );
		$this->assertTrue( $probe() );
	}

	public function test_close_and_push_name_the_same_payment_in_motion(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$row      = $this->card_row( 'pending' );
		$this->seed_rows( $order_id, array( $row ) );

		// Act.
		$closed    = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/close', $order_id );
		$cancelled = $this->push_status( $order_id, 'cancelled' );
		$deleted   = $this->push_delete( $order_id );

		// Assert.
		$this->assertSame( 409, $closed->get_status(), 'Response: ' . wp_json_encode( $closed->get_data() ) );
		$this->assertSame( 'wcpos_payment_in_flight', $closed->get_data()['code'] );
		$this->assertSame( $row['id'], $closed->get_data()['data']['payment_id'] );
		$this->assertArrayNotHasKey( 'attempt_id', $closed->get_data()['data'] );
		$this->assert_money_in_motion( $cancelled, $order_id, 'pos-open' );
		$this->assertSame( $row['id'], $cancelled->get_data()['data']['payment_id'] );
		$this->assert_money_in_motion( $deleted, $order_id, 'pos-open' );
		$this->assertSame( $row['id'], $deleted->get_data()['data']['payment_id'] );
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

	/** WooCommerce's internal order keys that a push could reach through `meta_data` (setter-backed and plain). */
	protected function internal_order_meta(): array {
		return array(
			'_order_currency'       => 'EUR',
			'_order_stock_reduced'  => 'yes',
			'_created_via'          => 'checkout',
			'_date_completed'       => (string) ( time() - DAY_IN_SECONDS ),
			'_recorded_sales'       => 'yes',
			'_order_key'            => 'wc_order_forged',
			'_customer_user'        => (string) self::factory()->user->create(),
		);
	}

	public function test_push_carrying_woocommerce_internal_order_meta_changes_nothing(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$before   = wc_get_order( $order_id );
		$keys     = array_keys( $this->internal_order_meta() );
		$rows     = $this->stored_meta_rows( $order_id, $keys );
		$meta     = $this->uuid_meta();
		foreach ( $this->internal_order_meta() as $key => $value ) {
			$meta[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}
		foreach ( $rows as $row ) {
			$meta[] = array(
				'id'    => $row[0],
				'key'   => $row[1],
				'value' => 'changed-by-id',
			);
		}

		// Act.
		$response = $this->push_envelope( 'update', array( 'meta_data' => $meta ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$after = wc_get_order( $order_id );
		$this->assertSame( $before->get_currency( 'edit' ), $after->get_currency( 'edit' ) );
		$this->assertSame( 'woocommerce-pos', $after->get_created_via( 'edit' ) );
		$this->assertFalse( (bool) $after->get_data_store()->get_stock_reduced( $order_id ) );
		$this->assertNull( $after->get_date_completed( 'edit' ) );
		$this->assertFalse( (bool) $after->get_data_store()->get_recorded_sales( $order_id ) );
		$this->assertSame( $before->get_order_key( 'edit' ), $after->get_order_key( 'edit' ) );
		$this->assertSame( $before->get_customer_id( 'edit' ), $after->get_customer_id( 'edit' ) );
		$this->assertSame( $rows, $this->stored_meta_rows( $order_id, $keys ) );
	}

	/** Line meta keys that are a line's own storage in WooCommerce, or the stock helper's baseline. */
	protected function internal_line_meta( int $other_product_id ): array {
		return array(
			array(
				'key'   => '_qty',
				'value' => '9',
			),
			array(
				'key'   => '_line_total',
				'value' => '1.00',
			),
			array(
				'key'   => '_product_id',
				'value' => (string) $other_product_id,
			),
			array(
				'key'   => '_reduced_stock',
				'value' => '0',
			),
		);
	}

	public function test_line_meta_carrying_internal_item_keys_on_a_paid_order_changes_nothing(): void {
		// Arrange: the full document of a paid order, one line carrying WooCommerce's own item keys.
		$product  = $this->stock_product( 10 );
		$other    = $this->product();
		$order_id = $this->paid_sale_of_two( $product );
		$item     = $this->first_item( $order_id );
		$total    = $item->get_total( 'edit' );
		$document = $this->order_document( $order_id );
		$document['line_items'][0]['meta_data'] = array_merge( $document['line_items'][0]['meta_data'] ?? array(), $this->internal_line_meta( $other->get_id() ) );

		// Act.
		$response = $this->push_envelope( 'update', $document, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$item = $this->first_item( $order_id );
		$this->assertSame( 2, $item->get_quantity() );
		$this->assertSame( wc_format_decimal( $total, 2 ), wc_format_decimal( $item->get_total( 'edit' ), 2 ) );
		$this->assertSame( $product->get_id(), $item->get_product_id() );
		$this->assertSame( '2', (string) $item->get_meta( '_reduced_stock', true ) );
		$this->assertSame( 8, $this->stock_of( $product ) );
	}

	public function test_line_meta_addressing_the_reduced_stock_row_by_id_changes_nothing(): void {
		// Arrange.
		$product  = $this->stock_product( 10 );
		$order_id = $this->paid_sale_of_two( $product );
		$item     = $this->first_item( $order_id );
		$row_id   = 0;
		foreach ( $item->get_meta_data() as $meta ) {
			if ( '_reduced_stock' === $meta->key ) {
				$row_id = (int) $meta->id;
			}
		}
		$this->assertGreaterThan( 0, $row_id );
		$payload = array(
			'line_items' => array(
				array(
					'id'        => $item->get_id(),
					'meta_data' => array(
						array(
							'id'    => $row_id,
							'key'   => '_reduced_stock',
							'value' => '0',
						),
						array(
							'id'    => $row_id,
							'key'   => 'harmless',
							'value' => '0',
						),
					),
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$item = $this->first_item( $order_id );
		$this->assertSame( '2', (string) $item->get_meta( '_reduced_stock', true ) );
		$this->assertSame( '', (string) $item->get_meta( 'harmless', true ) );
	}

	public function test_tax_class_change_on_a_processing_order_is_refused_reopen_required(): void {
		// Arrange.
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 2 ) ) );
		$payload  = array(
			'line_items' => array(
				array(
					'id'        => $this->first_item( $order_id )->get_id(),
					'tax_class' => 'reduced-rate',
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( '', $this->first_item( $order_id )->get_tax_class( 'edit' ) );
	}

	public function test_fee_tax_status_change_on_a_processing_order_is_refused_reopen_required(): void {
		// Arrange: a paid order with a taxable fee.
		$order_id = $this->make_order( 'pending', array( array( $this->product(), 1 ) ) );
		$order    = wc_get_order( $order_id );
		$fee      = new \WC_Order_Item_Fee();
		$fee->set_name( 'Service' );
		$fee->set_total( '2.00' );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->save();
		$order = wc_get_order( $order_id );
		$order->payment_complete();
		$order = wc_get_order( $order_id );
		$order->set_status( 'processing' );
		$order->save();
		$fee_id  = array_key_first( wc_get_order( $order_id )->get_fees() );
		$payload = array(
			'fee_lines' => array(
				array(
					'id'         => $fee_id,
					'tax_status' => 'none',
				),
			),
			'meta_data' => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( 'taxable', wc_get_order( $order_id )->get_item( $fee_id )->get_tax_status( 'edit' ) );
	}

	public function test_rebinding_a_reduced_line_to_another_product_moves_its_stock(): void {
		// Arrange: a paid sale of two A (10 -> 8), reopened.
		$a        = $this->stock_product( 10 );
		$b        = $this->stock_product( 5 );
		$order_id = $this->paid_sale_of_two( $a );
		$this->assertSame( 200, $this->push_status( $order_id, 'pos-open' )->get_status() );
		$payload = array(
			'line_items' => array(
				array(
					'id'         => $this->first_item( $order_id )->get_id(),
					'product_id' => $b->get_id(),
					'quantity'   => 2,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 10, $this->stock_of( $a ) );
		$this->assertSame( 3, $this->stock_of( $b ) );
		$item = $this->first_item( $order_id );
		$this->assertSame( $b->get_id(), $item->get_product_id() );
		$this->assertSame( '2', (string) $item->get_meta( '_reduced_stock', true ) );
	}

	/** A paid sale (processing unless told otherwise) of one taxed line (10.00 at 10%) whose POS line data says taxable. */
	protected function paid_taxed_pos_line_order( string $status = 'processing' ): int {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		\WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper::create_tax_rate(
			array(
				'country'  => '',
				'rate'     => '10.000',
				'name'     => 'Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);
		$order   = wc_create_order();
		$item_id = $order->add_product( $this->product(), 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_woocommerce_pos_data', '{"tax_status":"taxable","note":"x"}', true );
		$item->save();
		$order->set_created_via( 'woocommerce-pos' );
		$order->update_meta_data( '_woocommerce_pos_uuid', self::REC );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();
		$order_id = $order->get_id();
		wc_get_order( $order_id )->payment_complete();
		$order = wc_get_order( $order_id );
		$order->set_status( $status );
		$order->save();
		$this->assertSame( '1', wc_format_decimal( $this->first_item( $order_id )->get_total_tax( 'edit' ), 0 ) );

		return $order_id;
	}

	/** The order's own document with its first line's POS data replaced by the given JSON. */
	protected function document_with_pos_data( int $order_id, string $pos_data ): array {
		$document = $this->order_document( $order_id );
		$meta     = array_values(
			array_filter(
				$document['line_items'][0]['meta_data'] ?? array(),
				static fn( $entry ) => '_woocommerce_pos_data' !== ( is_array( $entry ) ? ( $entry['key'] ?? '' ) : '' )
			)
		);
		$meta[]   = array(
			'key'   => '_woocommerce_pos_data',
			'value' => $pos_data,
		);
		$document['line_items'][0]['meta_data'] = $meta;

		return $document;
	}

	public function test_pos_data_tax_status_change_on_a_paid_line_is_refused_reopen_required(): void {
		// Arrange.
		$order_id = $this->paid_taxed_pos_line_order();
		$tax      = $this->first_item( $order_id )->get_total_tax( 'edit' );

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_pos_data( $order_id, '{"tax_status":"none","note":"x"}' ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( $tax, $this->first_item( $order_id )->get_total_tax( 'edit' ) );
	}

	public function test_pos_data_re_encoded_with_the_same_value_on_a_paid_line_is_not_a_line_change(): void {
		// Arrange.
		$order_id = $this->paid_taxed_pos_line_order();

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_pos_data( $order_id, '{"note":"x","tax_status":"taxable"}' ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'processing', wc_get_order( $order_id )->get_status() );
	}

	public function test_pos_data_tax_status_change_with_a_reopen_is_applied(): void {
		// Arrange.
		$order_id           = $this->paid_taxed_pos_line_order();
		$document           = $this->document_with_pos_data( $order_id, '{"tax_status":"none","note":"x"}' );
		$document['status'] = 'pos-open';

		// Act.
		$response = $this->push_envelope( 'update', $document, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pos-open', wc_get_order( $order_id )->get_status() );
		$this->assertSame( '0', wc_format_decimal( $this->first_item( $order_id )->get_total_tax( 'edit' ), 0 ) );
	}

	/** A paid processing order with two lines, the first stored at quantity 0; returns its id and that line's id. */
	protected function paid_order_with_a_zero_quantity_line(): array {
		$order_id = $this->paid_order( 'processing', array( array( $this->product(), 1 ), array( $this->product(), 1 ) ) );
		$item     = $this->first_item( $order_id );
		$item->set_quantity( 0 );
		$item->save();

		return array( $order_id, $item->get_id() );
	}

	/** The order's full document with the zero-quantity line posted as wc/v3's integer removal marker. */
	protected function document_with_zero_quantity( int $order_id, int $item_id ): array {
		$document = $this->order_document( $order_id );
		foreach ( $document['line_items'] as $i => $line ) {
			if ( $item_id === (int) $line['id'] ) {
				$document['line_items'][ $i ]['quantity'] = 0;
			}
		}

		return $document;
	}

	public function test_zero_quantity_removal_marker_on_a_paid_order_is_refused_reopen_required(): void {
		// Arrange.
		list( $order_id, $item_id ) = $this->paid_order_with_a_zero_quantity_line();

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_zero_quantity( $order_id, $item_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertNotFalse( wc_get_order( $order_id )->get_item( $item_id ) );
	}

	public function test_zero_quantity_removal_marker_with_a_reopen_removes_the_line(): void {
		// Arrange.
		list( $order_id, $item_id ) = $this->paid_order_with_a_zero_quantity_line();
		$document                   = $this->document_with_zero_quantity( $order_id, $item_id );
		$document['status']         = 'pos-open';

		// Act.
		$response = $this->push_envelope( 'update', $document, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertFalse( wc_get_order( $order_id )->get_item( $item_id ) );
		$this->assertCount( 1, wc_get_order( $order_id )->get_items() );
	}

	public function test_currency_change_on_a_gateway_paid_order_is_refused_currency_locked(): void {
		// Arrange: paid by a WooCommerce gateway, so no ledger row exists.
		$order_id = $this->make_order( 'pending', array( array( $this->product(), 1 ) ) );
		wc_get_order( $order_id )->payment_complete( 'txn-1' );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order_id ) ) );
		$payload = array(
			'currency'  => 'EUR',
			'meta_data' => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_currency_locked', $response->get_data()['code'] );
		$this->assertSame( get_woocommerce_currency(), wc_get_order( $order_id )->get_currency() );
	}

	public function test_currency_only_change_on_an_open_cart_with_no_money_is_applied(): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$payload  = array(
			'currency'  => 'EUR',
			'meta_data' => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'EUR', wc_get_order( $order_id )->get_currency() );
	}

	/** A reopened (pos-open, stock still reduced) sale of two A, and the push that re-binds its line to B. */
	protected function reopened_sale_rebinding_to( \WC_Product $a, \WC_Product $b ): array {
		$order_id = $this->paid_sale_of_two( $a );
		$this->assertSame( 200, $this->push_status( $order_id, 'pos-open' )->get_status() );
		$payload = array(
			'line_items' => array(
				array(
					'id'         => $this->first_item( $order_id )->get_id(),
					'product_id' => $b->get_id(),
					'quantity'   => 2,
				),
			),
			'meta_data'  => $this->uuid_meta(),
		);

		return array( $order_id, $payload );
	}

	public function test_a_failed_forward_moves_no_stock(): void {
		// Arrange: the write fails after wc/v3 prepared the re-bound line.
		$a                         = $this->stock_product( 10 );
		$b                         = $this->stock_product( 5 );
		list( $order_id, $payload ) = $this->reopened_sale_rebinding_to( $a, $b );
		$fail                      = static fn() => new \WP_Error( 'test_refused', 'Refused by test.', array( 'status' => 400 ) );
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $fail, 30 );

		// Act.
		try {
			$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $fail, 30 );
		}

		// Assert.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 8, $this->stock_of( $a ) );
		$this->assertSame( 5, $this->stock_of( $b ) );
		$this->assertSame( '2', (string) $this->first_item( $order_id )->get_meta( '_reduced_stock', true ) );
	}

	public function test_a_prevented_stock_adjustment_is_a_no_op_not_a_failure(): void {
		// Arrange.
		$product           = $this->stock_product( 10 );
		$order_id          = $this->paid_sale_of_two( $product );
		$payload           = $this->quantity_edit( $order_id );
		$payload['status'] = 'pos-open';
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', '__return_true' );

		// Act.
		try {
			$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );
		} finally {
			remove_filter( 'woocommerce_prevent_adjust_line_item_product_stock', '__return_true' );
		}

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 8, $this->stock_of( $product ) );
		$failures = array_filter(
			wc_get_order_notes( array( 'order_id' => $order_id ) ),
			static fn( $note ) => false !== strpos( $note->content, 'could not be adjusted' )
		);
		$this->assertCount( 0, $failures );
	}

	public function test_rebinding_with_a_move_to_pending_leaves_both_products_unreduced(): void {
		// Arrange: WooCommerce restores an order's stock when it moves to pending.
		$a                         = $this->stock_product( 10 );
		$b                         = $this->stock_product( 5 );
		list( $order_id, $payload ) = $this->reopened_sale_rebinding_to( $a, $b );
		$payload['status']         = 'pending';

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pending', wc_get_order( $order_id )->get_status() );
		$this->assertSame( 10, $this->stock_of( $a ) );
		$this->assertSame( 5, $this->stock_of( $b ) );
	}

	/** Every money figure of an order as stored: totals and each line's tax. */
	protected function stored_totals( int $order_id ): array {
		$order   = wc_get_order( $order_id );
		$figures = array( $order->get_total( 'edit' ), $order->get_total_tax( 'edit' ) );
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item ) {
			$figures[] = $item->get_total_tax( 'edit' );
		}

		return $figures;
	}

	/** The order's full document with a new billing country and postcode. */
	protected function document_with_new_billing( int $order_id ): array {
		$document                        = $this->order_document( $order_id );
		$document['billing']['country']  = 'GB';
		$document['billing']['postcode'] = 'SW1A 1AA';

		return $document;
	}

	/** A second rate for every location, added after the sale. */
	protected function add_tax_rate_after_the_sale(): void {
		\WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper::create_tax_rate(
			array(
				'country'  => '',
				'rate'     => '5.000',
				'name'     => 'Later tax',
				'priority' => 2,
				'compound' => false,
				'shipping' => true,
			)
		);
	}

	public function test_address_change_on_a_paid_order_stores_the_address_and_keeps_its_totals(): void {
		// Arrange.
		$order_id = $this->paid_taxed_pos_line_order();
		$before   = $this->stored_totals( $order_id );

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_new_billing( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'GB', $order->get_billing_country() );
		$this->assertSame( 'SW1A 1AA', $order->get_billing_postcode() );
		$this->assertSame( 'GB', $response->get_data()['document']['billing']['country'] );
		$this->assertSame( $before, $this->stored_totals( $order_id ) );
	}

	public function test_a_tax_rate_added_after_the_sale_does_not_move_a_paid_orders_totals(): void {
		// Arrange.
		$order_id = $this->paid_taxed_pos_line_order();
		$before   = $this->stored_totals( $order_id );
		$this->add_tax_rate_after_the_sale();

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_new_billing( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->stored_totals( $order_id ) );
	}

	public function test_a_tax_rate_added_after_the_sale_recalculates_an_editable_on_hold_order(): void {
		// Arrange: on-hold is editable, so wc/v3 recalculates as it always has.
		$order_id = $this->paid_taxed_pos_line_order( 'on-hold' );
		$before   = $this->stored_totals( $order_id );
		$this->add_tax_rate_after_the_sale();

		// Act.
		$response = $this->push_envelope( 'update', $this->document_with_new_billing( $order_id ), $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertNotSame( $before, $this->stored_totals( $order_id ) );
	}

	public function test_shipping_instance_change_on_a_processing_order_is_refused_reopen_required(): void {
		// Arrange: a paid order with a flat-rate shipping line.
		$order_id = $this->make_order( 'pending', array( array( $this->product(), 1 ) ) );
		$order    = wc_get_order( $order_id );
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_instance_id( '1' );
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_total( '5.00' );
		$order->add_item( $shipping );
		$order->calculate_totals();
		$order->save();
		wc_get_order( $order_id )->payment_complete();
		$order = wc_get_order( $order_id );
		$order->set_status( 'processing' );
		$order->save();
		$shipping_id = array_key_first( wc_get_order( $order_id )->get_items( 'shipping' ) );
		$payload     = array(
			'shipping_lines' => array(
				array(
					'id'          => $shipping_id,
					'instance_id' => '2',
				),
			),
			'meta_data'      => $this->uuid_meta(),
		);

		// Act.
		$response = $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );

		// Assert.
		$this->assertSame( 422, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reopen_required', $response->get_data()['code'] );
		$this->assertSame( '1', (string) wc_get_order( $order_id )->get_item( $shipping_id )->get_instance_id() );
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


	/** The stored rows (id, key, value) of a line's storage keys and stock baseline, read from the table. */
	protected function stored_item_meta_rows( int $item_id ): array {
		global $wpdb;
		$keys = array_merge( ( new \WC_Order_Item_Product() )->get_data_store()->get_internal_meta_keys(), array( '_reduced_stock' ) );
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d", $item_id ) ) as $row ) {
			if ( in_array( $row->meta_key, $keys, true ) ) {
				$rows[] = array( (int) $row->meta_id, $row->meta_key, $row->meta_value );
			}
		}
		sort( $rows );

		return $rows;
	}

	/** Every form of entry naming a stored row: by id under its key and under another key, and as id tombstones with and without a key. */
	protected function entries_naming_rows( array $rows ): array {
		$entries = array();
		foreach ( $rows as $row ) {
			$entries[] = array(
				'id'    => $row[0],
				'key'   => $row[1],
				'value' => '7',
			);
			$entries[] = array(
				'id'    => $row[0],
				'key'   => 'harmless',
				'value' => '7',
			);
			$entries[] = array(
				'id'    => $row[0],
				'key'   => $row[1],
				'value' => null,
			);
			$entries[] = array(
				'id'    => $row[0],
				'value' => null,
			);
			$entries[] = array(
				'id'    => $row[0],
				'value' => '7',
			);
		}

		return $entries;
	}

	/** Each key by key and as a key tombstone. */
	protected function entries_naming_keys( array $keys ): array {
		$entries = array();
		foreach ( $keys as $key ) {
			$entries[] = array(
				'key'   => $key,
				'value' => '7',
			);
			$entries[] = array(
				'key'   => $key,
				'value' => null,
			);
		}

		return $entries;
	}

	public static function push_operations(): array {
		return array(
			'create' => array( 'create' ),
			'update' => array( 'update' ),
		);
	}

	/**
	 * I-c8: a push leaves every server-owned row, the payment fields and the status alone in every entry form,
	 * with the before-save keeper unhooked, so the push lane relies on nothing the keeper restores.
	 *
	 * @dataProvider push_operations
	 */
	public function test_push_naming_every_server_owned_key_in_every_form_writes_none_of_them_without_the_keeper( string $operation ): void {
		// Arrange: on update, a pos-open order holding a captured row, attempts, a stamp and a line with a stock baseline.
		$order_keys = array_values( array_unique( array_merge( $this->server_money_meta_keys(), ( new \WC_Order() )->get_data_store()->get_internal_meta_keys() ) ) );
		$product    = $this->product();
		$order_id   = 0;
		$item_id    = 0;
		$before     = array();
		$line_rows  = array();
		if ( 'update' === $operation ) {
			$order_id = $this->make_order( 'pos-open', array( array( $product, 1 ) ) );
			Ledger::instance()->save( wc_get_order( $order_id ), array( $this->cash_payment( '5.00' ) + array( 'status' => 'captured' ) ), false );
			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_wcpos_gateway_attempts', array( wp_generate_uuid4() => 'cancelled' ) );
			$order->update_meta_data( '_wcpos_awaiting_customer', array( 'attempt_id' => wp_generate_uuid4() ) );
			$order->update_meta_data( '_wcpos_checkout_state', array( 'status' => 'failed' ) );
			$item_id = (int) array_key_first( $order->get_items() );
			$order->save();
			$item = \WC_Order_Factory::get_order_item( $item_id );
			$item->update_meta_data( '_reduced_stock', '1' );
			$item->save();
			$before    = $this->stored_meta_rows( $order_id, $order_keys );
			$line_rows = $this->stored_item_meta_rows( $item_id );
			$this->assertGreaterThanOrEqual( 5, count( $before ), 'the ledger, its index, the attempts, the stamp and the checkout state are stored' );
			$this->assertContains( '_reduced_stock', array_column( $line_rows, 1 ) );
		}
		$paid_before = 'update' === $operation ? wc_get_order( $order_id )->get_date_paid( 'edit' ) : null;
		$line        = array(
			'quantity'  => 1,
			'meta_data' => array_merge( $this->entries_naming_keys( array( '_reduced_stock', '_qty' ) ), $this->entries_naming_rows( $line_rows ) ),
		);
		$line       += 'update' === $operation ? array( 'id' => $item_id ) : array( 'product_id' => $product->get_id() );
		$payload     = $this->server_money_fields() + array(
			'customer_note' => 'The push was applied',
			'line_items'    => array( $line ),
			'meta_data'     => array_merge( $this->uuid_meta(), $this->entries_naming_keys( $order_keys ), $this->entries_naming_rows( $before ) ),
		);
		$completed   = 0;
		$counter     = static function () use ( &$completed ): void {
			++$completed;
		};
		$keeper      = array( Ledger::instance(), 'keep_closure_stamps' );
		remove_action( 'woocommerce_before_order_object_save', $keeper, 10 );
		add_action( 'woocommerce_payment_complete', $counter );

		// Act.
		try {
			$response = 'create' === $operation
				? $this->push_envelope( 'create', $payload )
				: $this->push_envelope( 'update', $payload, $this->order_revision( $order_id ) );
		} finally {
			add_action( 'woocommerce_before_order_object_save', $keeper, 10, 1 );
			remove_action( 'woocommerce_payment_complete', $counter );
		}

		// Assert.
		$this->assertSame( 'create' === $operation ? 201 : 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$order_id = 'create' === $operation ? (int) $response->get_data()['document']['id'] : $order_id;
		$order    = wc_get_order( $order_id );
		$item_id  = 'create' === $operation ? (int) array_key_first( $order->get_items() ) : $item_id;
		$stored   = $this->stored_meta_rows( $order_id, $order_keys );
		if ( 'create' === $operation ) {
			$this->assertSame( array(), $this->stored_meta_rows( $order_id, $this->server_money_meta_keys() ) );
			$this->assertNotContains( '7', array_column( $stored, 2 ), 'no data-store row holds a pushed value' );
			$this->assertSame( array( '_qty' ), array_values( array_intersect( array( '_qty', '_reduced_stock' ), array_column( $this->stored_item_meta_rows( $item_id ), 1 ) ) ) );
			$this->assertSame( 1, $order->get_item( $item_id )->get_quantity() );
		} else {
			$this->assertSame( $before, $stored );
			$this->assertSame( $line_rows, $this->stored_item_meta_rows( $item_id ) );
		}
		$this->assertEquals( $paid_before, $order->get_date_paid( 'edit' ) );
		$this->assertSame( '', $order->get_payment_method( 'edit' ) );
		$this->assertSame( '', $order->get_payment_method_title( 'edit' ) );
		$this->assertSame( '', $order->get_transaction_id( 'edit' ) );
		$this->assertSame( 'create' === $operation ? 'pending' : 'pos-open', $order->get_status() );
		$this->assertSame( 'The push was applied', $order->get_customer_note() );
		$this->assertSame( '', $order->get_meta( 'harmless' ) );
		$this->assertSame( 0, $completed );
	}
	/** A captured full-total row stamped by a closure: what a forged ledger would claim. */
	protected function forged_ledger( string $amount ): string {
		$row = $this->cash_payment( $amount ) + array(
			'status'     => 'captured',
			'closure_id' => wp_generate_uuid4(),
		);

		return (string) wp_json_encode(
			array(
				'schema'   => 1,
				'payments' => array( $row ),
			)
		);
	}

	/** Record a cash tender through the route and return the order's ledger rows read back from storage. */
	protected function record_cash_and_read( int $order_id, string $amount ): array {
		$response = $this->money_command( '/wcpos/v2/orders/' . $order_id . '/payments', $order_id, array( 'payment' => $this->cash_payment( $amount ) ) );
		$this->assertSame( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );

		return Ledger::instance()->read( wc_get_order( $order_id ) );
	}

	/** Spellings MySQL's `_ci` PAD SPACE collation matches to the ledger key and PHP does not. */
	public static function ledger_key_variants(): array {
		return array(
			'upper case'     => array( '_WCPOS_PAYMENTS' ),
			'mixed case'     => array( '_Wcpos_Payments' ),
			'trailing space' => array( '_wcpos_payments ' ),
		);
	}

	/** D13: a case variant of the ledger key on a push never reaches storage or the ledger. */
	public function test_a_case_variant_ledger_key_pushed_before_payment_is_never_read_as_the_ledger(): void {
		// Arrange: an open 10.00 sale with no ledger; the till pushes a forged, closure-stamped full payment.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$meta     = $this->uuid_meta();
		$meta[]   = array(
			'key'   => '_WCPOS_PAYMENTS',
			'value' => $this->forged_ledger( '10.00' ),
		);
		$pushed   = $this->push_envelope( 'update', array( 'meta_data' => $meta ), $this->order_revision( $order_id ) );
		$this->assertSame( 200, $pushed->get_status(), 'Response: ' . wp_json_encode( $pushed->get_data() ) );

		// Act.
		$rows = $this->record_cash_and_read( $order_id, '1.00' );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( '1.00', $rows[0]['amount'] );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pos-partial', $order->get_status() );
		$this->assertNull( $order->get_date_paid( 'edit' ) );
		$this->assertSame( '', $order->get_meta( '_WCPOS_PAYMENTS' ) );
	}

	/**
	 * D13 at the reader: a case variant already in storage (any writer) is not the ledger.
	 *
	 * @dataProvider ledger_key_variants
	 */
	public function test_a_case_variant_ledger_row_in_storage_is_never_read_as_the_ledger( string $key ): void {
		// Arrange.
		$order_id = $this->make_order( 'pos-open', array( array( $this->product(), 1 ) ) );
		$order    = wc_get_order( $order_id );
		$order->add_meta_data( $key, $this->forged_ledger( '10.00' ) );
		$order->save();

		// Act.
		$rows = $this->record_cash_and_read( $order_id, '1.00' );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( '1.00', $rows[0]['amount'] );
		$this->assertSame( 'pos-partial', wc_get_order( $order_id )->get_status() );
	}
}
