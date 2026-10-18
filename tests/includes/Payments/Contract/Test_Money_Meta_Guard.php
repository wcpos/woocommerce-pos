<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\{Money_Meta_Guard, Order_Lock};
use WCPOS\WooCommercePOS\Sync\{Meta_Normalizer, Order_Serializer};
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

class Test_Money_Meta_Guard extends Sync_REST_Store_Test_Case {
	private const REC = '9b1c2d3e-5f60-4a7b-8c9d-0e1f2a3b4c5d';
	private const AT = '2026-10-18T12:00:00Z';

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}
	private function meta( array $values ): array {
		$result = array();
		foreach ( $values as $key => $value ) {
			$result[] = array( 'key' => $key, 'value' => $value );
		}
		return $result;
	}
	private function order( array $meta = array(), string $total = '50' ): \WC_Order {
		$order = wc_create_order();
		$item = new \WC_Order_Item_Fee();
		$item->set_name( 'Sale' );
		$item->set_tax_status( 'none' );
		$item->set_total( $total );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->set_status( 'pos-open' );
		$order->update_meta_data( '_woocommerce_pos_uuid', self::REC );
		$order->save();
		// HPOS may nest an order save here when date_modified crosses a second boundary.
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		Money_Meta_Guard::authorised( $order->get_id(), static fn() => $order->save_meta_data() );
		return $order;
	}
	private function push( ?\WC_Order $order, array $payload, array $envelope = array(), bool $modern = true ) {
		$revision = null;
		if ( $order ) {
			$read = new \WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
			$read->set_param( 'dp', '6' );
			$data = Meta_Normalizer::normalize( rest_do_request( $read )->get_data() );
			$revision = Order_Serializer::canonical_revision( Order_Serializer::add_pos_links( $data, wc_get_order( $order->get_id() ) ) );
		}
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $modern ) {
			$request->set_header( 'X-WCPOS-Client', 'ios/2.0.0 payments=1.3' );
		}
		$request->set_body( wp_json_encode( array_merge( array( 'mutationId' => wp_generate_uuid4(), 'operation' => $order ? 'update' : 'create', 'collection' => 'orders', 'recordId' => self::REC, 'baseRevision' => $revision, 'payload' => $payload ), $envelope ) ) );
		return $this->server->dispatch( $request );
	}
	private function cash( string $amount = '50', string $status = 'captured' ): array {
		return array( 'schema' => 1, 'payments' => array( array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'amount' => $amount, 'refunded_amount' => '0', 'status' => $status ) ) );
	}
	private function reopen(): array {
		return array( 'status' => 'pos-open', 'meta_data' => $this->meta( array( '_wcpos_money_epoch' => 1, '_wcpos_completed_at' => null, '_wcpos_reopened_at' => self::AT ) ) );
	}
	private function ack_meta( $response ): array {
		$this->assertLessThan( 300, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return array_column( $response->get_data()['document']['meta_data'], 'value', 'key' );
	}

	public function test_forged_update_preserves_stored_money_meta(): void {
		$stored = array( '_wcpos_money_owner' => 'server', '_wcpos_money_epoch' => 0, '_wcpos_completed_at' => self::AT, '_wcpos_gateway_attempts' => array( 'old' => 'sent' ) );
		$order = $this->order( $stored );
		$meta = $this->ack_meta( $this->push( $order, array( 'meta_data' => $this->meta( array_fill_keys( array_keys( $stored ), '' ) ) ) ) );
		foreach ( $stored as $key => $value ) {
			$this->assertEquals( $value, $meta[ $key ] );
		}
	}
	public function test_fixture_seed_survives_hpos_modified_timestamp_refresh(): void {
		$age = static function ( $order ) use ( &$age ): void {
			if ( $order->get_meta( '_woocommerce_pos_uuid' ) ) {
				remove_action( 'woocommerce_after_order_object_save', $age );
				$order->set_date_modified( time() - 2 );
				$order->apply_changes(); // Force HPOS's nested save during the fixture's meta seed.
			}
		};
		add_action( 'woocommerce_after_order_object_save', $age );
		try {
			$order = $this->order( array( '_wcpos_completed_at' => self::AT, '_wcpos_money_owner' => 'server' ) );
			$this->assertSame( 'server', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_owner' ) );
			$payload = $this->reopen();
			$payload['meta_data'][0]['value'] = 2;
			$meta = $this->ack_meta( $this->push( $order, $payload ) );
			$this->assertSame( 'server', $meta['_wcpos_money_owner'] );
			$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $age );
		}
	}
	public function test_update_prepares_once_and_preserves_post_update_context(): void {
		$order = $this->order();
		$order->set_billing_email( 'before@example.com' );
		$order->save();
		$prepares = 0;
		$count = static function ( $allowed ) use ( &$prepares ) {
			++$prepares;
			return $allowed;
		};
		add_filter( 'woocommerce_pos_order_store_reassignment_allowed', $count );
		try {
			$this->ack_meta( $this->push( $order, array( 'billing' => array( 'email' => '' ), 'meta_data' => $this->meta( array( '_pos_store' => '9' ) ) ) ) );
		} finally {
			remove_filter( 'woocommerce_pos_order_store_reassignment_allowed', $count );
		}
		$this->assertSame( 1, $prepares );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '9', $saved->get_meta( '_pos_store' ) );
		$this->assertSame( '', $saved->get_billing_email() );
	}
	public function test_save_without_meta_changes_skips_guard_stored_read(): void {
		global $wpdb;
		$order = $this->order( array( '_wcpos_money_owner' => 'server' ) );
		$reads = 0;
		$count = static function ( $query ) use ( &$reads, $wpdb ) {
			if ( 0 === strpos( $query, 'SELECT' ) && ( false !== strpos( $query, $wpdb->postmeta ) || false !== strpos( $query, $wpdb->prefix . 'wc_orders_meta' ) ) ) {
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					if ( Money_Meta_Guard::class === ( $frame['class'] ?? '' ) && 'keep_stored' === $frame['function'] ) {
						++$reads;
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			$order->set_customer_note( 'No meta changes' );
			$order->save();
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertSame( 0, $reads );
	}
	public function test_save_with_protected_tombstone_restores_stored_value(): void {
		$order = $this->order( array( '_wcpos_money_owner' => 'server' ) );
		$order->delete_meta_data( '_wcpos_money_owner' );
		$order->save();
		$this->assertSame( 'server', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_owner' ) );
	}
	public function test_forged_create_is_stripped(): void {
		$meta = $this->ack_meta( $this->push( null, array( 'meta_data' => $this->meta( array( '_wcpos_money_owner' => 'forged', '_wcpos_money_epoch' => 9, '_wcpos_gateway_attempts' => array( 'x' => 'settled' ) ) ) ) ) );
		$this->assertArrayNotHasKey( '_wcpos_money_owner', $meta );
		$this->assertArrayNotHasKey( '_wcpos_money_epoch', $meta );
		$this->assertArrayNotHasKey( '_wcpos_gateway_attempts', $meta );
	}
	public function test_authorised_write_and_delete_are_scoped_and_unwind(): void {
		$this->assertTrue( class_exists( Money_Meta_Guard::class ) );
		$order = $this->order();
		Money_Meta_Guard::authorised( $order->get_id(), function () use ( $order ) {
			$order->update_meta_data( '_wcpos_money_epoch', 1 );
			$order->save();
		} );
		$this->assertSame( 1, (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_epoch' ) );
		$order->delete_meta_data( '_wcpos_money_epoch' );
		$order->save();
		$this->assertSame( 1, (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_epoch' ) );
	}
	public function test_order_lock_refuses_then_released_update_applies(): void {
		$order = $this->order();
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$response = $this->push( $order, array( 'customer_note' => 'locked' ) );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
		} finally {
			$lock->release( $order->get_id() );
		}
		$this->ack_meta( $this->push( $order, array( 'customer_note' => 'released' ) ) );
		$this->assertSame( 'released', wc_get_order( $order->get_id() )->get_customer_note() );
	}
	public function test_overrule_stamps_ack_and_notes_once_and_reopen_clears_it(): void {
		$order = $this->order( array( '_wcpos_completed_at' => self::AT, '_wcpos_payments' => $this->cash( '50.07' ) ), '50.07' );
		$meta = $this->ack_meta( $this->push( $order, array(), array( 'tillAggregate' => array( 'total' => '50.00' ) ) ) );
		$this->assertSame( 'server', $meta['_wcpos_money_owner'] ?? null );
		$this->ack_meta( $this->push( $order, array(), array( 'tillAggregate' => array( 'total' => '50.00' ) ) ) );
		$notes = array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ) => false !== strpos( $note->content, "store's totals replaced" ) );
		$this->assertCount( 1, $notes );
		$meta = $this->ack_meta( $this->push( $order, $this->reopen(), array( 'expectedEpoch' => 0 ) ) );
		$this->assertSame( 1, (int) $meta['_wcpos_money_epoch'] );
		$this->assertArrayNotHasKey( '_wcpos_money_owner', $meta );
		$this->assertArrayNotHasKey( '_wcpos_completed_at', $meta );
		$this->assertSame( self::AT, $meta['_wcpos_reopened_at'] );
	}
	public function test_completion_requires_coverage_and_accepts_offline_cash(): void {
		$order = $this->order();
		$payload = array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $this->cash( '40' ), '_wcpos_completed_at' => self::AT ) ) );
		$meta = $this->ack_meta( $this->push( $order, $payload ) );
		$this->assertArrayNotHasKey( '_wcpos_completed_at', $meta );
		$payload['meta_data'] = $this->meta( array( '_wcpos_payments' => $this->cash(), '_wcpos_completed_at' => self::AT ) );
		$meta = $this->ack_meta( $this->push( $order, $payload ) );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
		$this->assertSame( 0, (int) $meta['_wcpos_completed_epoch'] );
	}
	public function test_reopened_completion_requires_this_mutation_to_edit_and_records_provenance(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1, '_wcpos_payments' => $this->cash() ) );
		$payload = array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => self::AT, '_wcpos_sale_counter' => 7 ) ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertArrayNotHasKey( '_wcpos_completed_at', $meta );
		$payload['fee_lines'] = array( array( 'id' => array_key_first( $order->get_items( 'fee' ) ), 'total' => '40' ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( 1, (int) $meta['_wcpos_completed_epoch'] );
		$this->assertSame( 7, $meta['_wcpos_epoch_provenance'][1]['sale_counter'] );
		$this->assertNull( $meta['_wcpos_epoch_provenance'][1]['register_id'] );
	}
	/** @dataProvider refused_reopens */
	public function test_reopen_refusal_writes_nothing( string $reason, array $meta ): void {
		$order = $this->order( $meta );
		$response = $this->push( $order, $this->reopen(), array( 'expectedEpoch' => 0 ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
		$this->assertSame( $reason, $response->get_data()['data']['reason'] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_reopened_at' ) );
	}
	public function refused_reopens(): array {
		return array(
			array( 'not_completed', array() ),
			array( 'settled_without_row', array( '_wcpos_gateway_attempts' => array( 'x' => 'settled' ) ) ),
			array( 'live_leg', array( '_wcpos_completed_at' => self::AT, '_wcpos_payments' => array( 'schema' => 1, 'payments' => array( array( 'id' => 'x', 'status' => 'pending', 'amount' => '50' ) ) ) ) ),
			array( 'attempt_unresolved', array( '_wcpos_completed_at' => self::AT, '_wcpos_gateway_attempts' => array( 'x' => 'indeterminate' ) ) ),
		);
	}
	public function test_reverse_gate_and_expected_epoch(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1 ) );
		$payload = array( 'fee_lines' => array( array( 'id' => array_key_first( $order->get_items( 'fee' ) ), 'total' => '40' ) ) );
		$response = $this->push( $order, $payload, array(), false );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'money_epoch', $response->get_data()['data']['reason'] );
		$this->ack_meta( $this->push( $order, array( 'customer_note' => 'allowed' ), array(), false ) );
		$response = $this->push( $order, $payload );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'expected_epoch_missing', $response->get_data()['data']['reason'] );
		$response = $this->push( $order, $payload, array( 'expectedEpoch' => 0 ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpos_money_epoch_stale', $response->get_data()['code'] );
		$this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( '40.00', wc_get_order( $order->get_id() )->get_total() );
	}
	public function test_legacy_owner_update_cannot_reassert_line_money(): void {
		$order = $this->order( array( '_wcpos_money_owner' => 'server' ) );
		$payload = array( 'fee_lines' => array( array( 'id' => array_key_first( $order->get_items( 'fee' ) ), 'total' => '40' ) ) );
		$this->ack_meta( $this->push( $order, $payload, array(), false ) );
		$this->assertSame( '50.00', wc_get_order( $order->get_id() )->get_total() );
	}
	public function test_pre13_payment_route_on_reopened_sale_is_refused(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1 ) );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_body_params( array( 'payment' => array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'amount' => '50' ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'money_epoch', $response->get_data()['data']['reason'] );
	}
	public function test_free_create_completes_but_born_twice_does_not_apply_new_intent(): void {
		$meta = $this->ack_meta( $this->push( null, array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => self::AT, '_wcpos_completed_session' => 'session' ) ) ) ) );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
		$this->assertSame( 0, (int) $meta['_wcpos_completed_epoch'] );
		$meta = $this->ack_meta( $this->push( null, array(), array( 'tillAggregate' => array( 'total' => '99' ) ) ) );
		$this->assertArrayNotHasKey( '_wcpos_money_owner', $meta );
	}
	public function test_forged_epoch_jump_is_dropped_and_plain_reopen_keeps_epoch(): void {
		$order = $this->order( array( '_wcpos_completed_at' => self::AT, '_wcpos_money_owner' => 'server' ) );
		$payload = $this->reopen();
		$payload['meta_data'][0]['value'] = 2;
		$meta = $this->ack_meta( $this->push( $order, $payload ) );
		$this->assertArrayNotHasKey( '_wcpos_money_epoch', $meta );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
		$this->assertSame( 'server', $meta['_wcpos_money_owner'] );
		$meta = $this->ack_meta( $this->push( $order, array( 'status' => 'pos-open' ) ) );
		$this->assertArrayNotHasKey( '_wcpos_money_epoch', $meta );
	}
	public function test_guard_covers_core_wc_route_and_authorisation_unwinds_after_exception(): void {
		$order = $this->order( array( '_wcpos_money_owner' => 'server' ) );
		$request = new \WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params( array( 'meta_data' => $this->meta( array( '_wcpos_money_owner' => '', '_wcpos_money_epoch' => 8 ) ) ) );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( 'server', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_owner' ) );
		try {
			Money_Meta_Guard::authorised( $order->get_id(), static function () { throw new \RuntimeException( 'test' ); } );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'test', $error->getMessage() );
		}
		$order->update_meta_data( '_wcpos_money_epoch', 4 );
		$order->save();
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_epoch' ) );
	}
	/** @dataProvider payment_write_paths */
	public function test_reverse_gate_covers_every_payment_write_route( string $path ): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1 ) );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . $path );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['data']['epoch'] );
	}
	public function payment_write_paths(): array {
		return array_map( static fn( $path ) => array( $path ), array( '/payments', '/payments/11111111-1111-4111-8111-111111111111/intent', '/payments/11111111-1111-4111-8111-111111111111/capture', '/payments/11111111-1111-4111-8111-111111111111/refund', '/payments/11111111-1111-4111-8111-111111111111/void', '/payment-methods/pos_cash/submit', '/payment-methods/pos_cash/cancel' ) );
	}
	public function test_wire_aggregate_object_is_not_an_array_and_opaque_fields_are_accepted(): void {
		$order = $this->order();
		$this->ack_meta( $this->push( $order, array(), array( 'tillAggregate' => (object) array() ) ) );
		$this->ack_meta( $this->push( $order, array(), array( 'tillAggregate' => array( 'tax_lines' => array(), 'total' => array( 'opaque' ) ) ) ) );
		$this->assertSame( 400, $this->push( $order, array(), array( 'tillAggregate' => array() ) )->get_status() );
	}

	public function test_reduced_total_cannot_complete_against_money_removed_by_this_write(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1, '_wcpos_payments' => $this->cash() ) );
		$payload = array(
			'fee_lines' => array( array( 'id' => array_key_first( $order->get_items( 'fee' ) ), 'total' => '40' ) ),
			'meta_data' => $this->meta( array( '_wcpos_completed_at' => self::AT, '_wcpos_payments' => array( 'schema' => 1, 'payments' => array() ) ) ),
		);
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertArrayNotHasKey( '_wcpos_completed_at', $meta );
	}
	public function test_unrelated_failed_row_does_not_make_a_rowless_settlement_reopenable(): void {
		$order = $this->order( array( '_wcpos_payments' => $this->cash( '50', 'failed' ), '_wcpos_gateway_attempts' => array( 'settled-attempt' => 'settled' ) ) );
		$response = $this->push( $order, $this->reopen(), array( 'expectedEpoch' => 0 ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'settled_without_row', $response->get_data()['data']['reason'] );
	}

	/** @dataProvider forged_row_fields */
	public function test_resent_row_cannot_forge_epoch_or_refund_rollup( int $epoch, string $refund ): void {
		$cash = $this->cash();
		$order = $this->order( array( '_wcpos_money_epoch' => 1, '_wcpos_payments' => $cash ) );
		$cash['payments'][0]['epoch'] = $epoch;
		$cash['payments'][0]['refunded_amount'] = $refund;
		$this->ack_meta( $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $cash, '_wcpos_completed_at' => self::AT ) ) ), array( 'expectedEpoch' => 1 ) ) );
		$saved = wc_get_order( $order->get_id() );
		$row = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( $saved )[0];
		$this->assertSame( 0, $row['epoch'] ?? null );
		$this->assertSame( '0', $row['refunded_amount'] );
		$this->assertSame( '', $saved->get_meta( '_wcpos_completed_at' ) );
	}
	public function forged_row_fields(): array {
		return array( array( 1, '0' ), array( 0, '10' ) );
	}
	public function test_new_offline_row_uses_server_epoch_and_completes(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1 ) );
		$cash = $this->cash();
		$cash['payments'][0]['epoch'] = 99;
		$meta = $this->ack_meta( $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $cash, '_wcpos_money_epoch' => 99, '_wcpos_completed_at' => self::AT ) ) ), array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( 1, \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( wc_get_order( $order->get_id() ) )[0]['epoch'] );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
	}
	public function test_completion_stamp_cannot_launder_rowless_settlement(): void {
		$order = $this->order( array( '_wcpos_gateway_attempts' => array( 'gateway' => 'settled' ) ) );
		$meta = $this->ack_meta( $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => self::AT ) ) ) ) );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
		$response = $this->push( $order, $this->reopen(), array( 'expectedEpoch' => 0 ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'settled_without_row', $response->get_data()['data']['reason'] );
	}
	public function test_payment_lock_precedes_revision_conflict_read(): void {
		$order = $this->order();
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$response = $this->push( $order, array(), array( 'baseRevision' => 'stale' ) );
			$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_data()['code'] );
			$this->assertArrayNotHasKey( 'current', $response->get_data() );
		} finally {
			$lock->release( $order->get_id() );
		}
	}
	public function test_payment_saved_under_lock_invalidates_old_push_revision(): void {
		$order = $this->order();
		$ack = $this->push( $order, array() )->get_data();
		$lock = Order_Lock::instance();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$row = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->record( wc_get_order( $order->get_id() ), array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'amount' => '50' ) );
			$this->assertIsArray( $row );
		} finally {
			$lock->release( $order->get_id() );
		}
		$response = $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_payments' => array( 'schema' => 1, 'payments' => array() ) ) ) ), array( 'baseRevision' => $ack['currentRevision'] ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $response->get_data()['code'] );
		$this->assertCount( 1, \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}
	public function test_route_record_on_reopened_order_has_current_epoch(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1 ) );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_header( 'X-WCPOS-Client', 'ios/2.0.0 payments=1.3' );
		$request->set_body_params( array( 'payment' => array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'amount' => '50' ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $response->get_data()['payment']['epoch'] ?? null );
		$this->assertTrue( \WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch::is_completed_in_current_epoch( wc_get_order( $order->get_id() ), \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance() ) );
	}
	/** @dataProvider stamp_epochs */
	public function test_completion_only_write_checks_epoch_and_preserves_stamp( int $expected ): void {
		$order = $this->order( array( '_wcpos_money_epoch' => 1, '_wcpos_completed_epoch' => 1, '_wcpos_completed_at' => self::AT, '_wcpos_completed_session' => 'original' ) );
		$response = $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => '2026-10-17T12:00:00Z', '_wcpos_completed_session' => 'stale' ) ) ), array( 'expectedEpoch' => $expected ) );
		$this->assertSame( $expected ? 200 : 422, $response->get_status() );
		if ( ! $expected ) {
			$this->assertSame( 'wcpos_money_epoch_stale', $response->get_data()['code'] );
		}
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( self::AT, $saved->get_meta( '_wcpos_completed_at' ) );
		$this->assertSame( 'original', $saved->get_meta( '_wcpos_completed_session' ) );
	}
	public function stamp_epochs(): array {
		return array( array( 0 ), array( 1 ) );
	}

	/** @dataProvider set_paid_clients */
	public function test_set_paid_on_reopened_order_requires_matching_epoch( bool $modern, int $status, string $code ): void {
		$order = $this->order( array( '_wcpos_money_epoch' => '1' ) );
		$response = $this->push( $order, array( 'set_paid' => true ), array( 'expectedEpoch' => 0 ), $modern );
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertNull( wc_get_order( $order->get_id() )->get_date_paid() );
	}
	public function set_paid_clients(): array {
		return array( array( false, 426, 'wcpos_update_required' ), array( true, 422, 'wcpos_money_epoch_stale' ) );
	}
	public function test_epoch_provenance_validates_register_and_fills_only_null_fields(): void {
		$order = $this->order( array( '_wcpos_money_epoch' => '1' ) );
		$payload = array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $this->cash(), '_wcpos_completed_at' => self::AT, '_wcpos_register' => 'malformed' ) ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertNull( $meta['_wcpos_epoch_provenance'][1]['register_id'] );
		$payload = array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => '2026-10-19T12:00:00Z', '_wcpos_register' => self::REC ) ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( self::REC, $meta['_wcpos_epoch_provenance'][1]['register_id'] );
		$this->assertSame( self::AT, $meta['_wcpos_epoch_provenance'][1]['completed_at'] );
		$payload['meta_data'] = $this->meta( array( '_wcpos_completed_at' => self::AT, '_wcpos_register' => wp_generate_uuid4() ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( self::REC, $meta['_wcpos_epoch_provenance'][1]['register_id'] );
	}
	public function test_epoch_provenance_uses_saved_register_not_refused_client_value(): void {
		$cash = $this->cash();
		$cash['payments'][0]['epoch'] = 1;
		$order = $this->order( array( '_wcpos_money_epoch' => '1', '_wcpos_payments' => $cash, '_wcpos_register' => self::REC ) );
		$order->set_status( 'completed' );
		$order->save();
		$meta = $this->ack_meta( $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_completed_at' => self::AT, '_wcpos_register' => wp_generate_uuid4() ) ) ), array( 'expectedEpoch' => 1 ) ) );
		$this->assertSame( self::REC, $meta['_wcpos_epoch_provenance'][1]['register_id'] );
	}

	/** @dataProvider guard_lock_changes */
	public function test_money_guard_holds_lock_only_for_protected_changes( string $key, bool $locked ): void {
		$order = $this->order( array( '_wcpos_money_owner' => 'server', 'ordinary' => 'old' ) );
		if ( $key ) {
			$order->delete_meta_data( $key );
		}
		$observed = null;
		$probe = new Order_Lock();
		$check = static function ( $saving ) use ( $probe, &$observed ) {
			$observed = ! $probe->acquire( $saving->get_id() );
			$probe->release( $saving->get_id() );
		};
		add_action( 'woocommerce_before_order_object_save', $check, 11 );
		try {
			$order->save();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $check, 11 );
		}
		$this->assertSame( $locked, $observed );
		$this->assertTrue( $probe->acquire( $order->get_id() ) );
		$probe->release( $order->get_id() );
		$this->assertSame( 'server', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_owner' ) );
	}
	public function guard_lock_changes(): array {
		return array( array( '_wcpos_money_owner', true ), array( 'ordinary', false ), array( '', false ) );
	}

	public function test_legacy_ledger_method_edit_on_reopened_sale_is_refused(): void {
		$cash = $this->cash();
		$order = $this->order( array( '_wcpos_money_epoch' => '1', '_wcpos_payments' => $cash ) );
		$cash['payments'][0]['method_id'] = 'pos_card';
		$response = $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $cash ) ) ), array(), false );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'money_epoch', $response->get_data()['data']['reason'] );
	}
	public function test_reopen_without_explicit_completion_tombstone_keeps_epoch(): void {
		$order = $this->order( array( '_wcpos_payments' => $this->cash(), '_wcpos_completed_at' => self::AT ) );
		$payload = array( 'status' => 'pos-open', 'meta_data' => $this->meta( array( '_wcpos_money_epoch' => 1, '_wcpos_reopened_at' => self::AT ) ) );
		$meta = $this->ack_meta( $this->push( $order, $payload, array( 'expectedEpoch' => 0 ) ) );
		$this->assertArrayNotHasKey( '_wcpos_money_epoch', $meta );
		$this->assertArrayNotHasKey( '_wcpos_reopened_at', $meta );
		$this->assertSame( self::AT, $meta['_wcpos_completed_at'] );
	}
	public function test_malformed_line_push_returns_wc_validation_error(): void {
		$order = $this->order( array( '_wcpos_money_owner' => 'server' ) );
		$response = $this->push( $order, array( 'line_items' => array( 'x' ) ), array(), false );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/** @dataProvider malformed_ledger_operations */
	public function test_malformed_ledger_push_keeps_only_valid_rows( bool $create ): void {
		$order = $create ? null : $this->order();
		$cash = $this->cash();
		$id = $cash['payments'][0]['id'];
		$cash['payments'] = array_merge( array( 'x', null, array(), array( 'id' => array() ), array( 'id' => '  ' ) ), $cash['payments'] );
		$response = $this->push( $order, array( 'meta_data' => $this->meta( array( '_wcpos_payments' => $cash ) ) ) );
		$meta = $this->ack_meta( $response );
		$this->assertSame( $create ? 201 : 200, $response->get_status() );
		$this->assertSame( array( $id ), array_column( $meta['_wcpos_payments']['payments'], 'id' ) );
	}
	public function malformed_ledger_operations(): array {
		return array( array( true ), array( false ) );
	}
	/** @dataProvider payment_identity_edits */
	public function test_legacy_payment_identity_edit_on_reopened_sale_is_refused( string $field, string $value ): void {
		$order = $this->order( array( '_wcpos_money_epoch' => '1' ) );
		$response = $this->push( $order, array( $field => $value ), array(), false );
		$this->assertSame( 426, $response->get_status() );
		$this->assertSame( 'money_epoch', $response->get_data()['data']['reason'] );
	}
	public function payment_identity_edits(): array {
		return array( array( 'currency', 'EUR' ), array( 'payment_method', 'pos_cash' ), array( 'payment_method_title', 'Cash' ), array( 'transaction_id', 'txn' ), array( 'date_paid', '2026-10-18T12:00:00' ), array( 'date_paid_gmt', '2026-10-18T12:00:00' ), array( 'status', 'completed' ) );
	}
	public function test_sent_attempt_blocks_reopen_until_cancel_route_resolves_it(): void {
		$attempt = wp_generate_uuid4();
		$order = $this->order( array( '_wcpos_completed_at' => self::AT, '_wcpos_gateway_attempts' => array( $attempt => 'sent' ), '_wcpos_awaiting_customer' => array( 'method_id' => 'pos_cash', 'attempt_id' => $attempt ) ) );
		$response = $this->push( $order, $this->reopen(), array( 'expectedEpoch' => 0 ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'attempt_unresolved', $response->get_data()['data']['reason'] );
		$cancel = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/pos_cash/cancel' );
		$cancel->set_body_params( array( 'attempt_id' => $attempt ) );
		$this->assertSame( 200, $this->server->dispatch( $cancel )->get_status() );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $stored->get_meta( '_wcpos_gateway_attempts' )[ $attempt ]['state'] );
		$meta = $this->ack_meta( $this->push( $stored, $this->reopen(), array( 'expectedEpoch' => 0 ) ) );
		$this->assertSame( 1, (int) $meta['_wcpos_money_epoch'] );
	}

}
