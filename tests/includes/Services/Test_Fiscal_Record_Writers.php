<?php
/**
 * Fiscal record writer tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Fiscal lifecycle observers and immutable corrections. */
class Test_Fiscal_Record_Writers extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Restore writer hooks between tests. */
	public function setUp(): void {
		parent::setUp();
		// WP restores hooks between tests, while service singletons survive.
		$writers = Fiscal_Record_Writers::instance();
		add_action( 'woocommerce_pos_payment_voided', array( $writers, 'handle_void' ), 10, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $writers, 'handle_cancellation' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $writers, 'handle_refund' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( Receipt_Snapshot_Store::instance(), 'handle_payment_complete' ) );
		foreach ( Fiscal_Record_Store::TYPES as $type ) {
			delete_option( 'wcpos_fiscal_sequence_' . $type );
		}
	}

	/** Create an order.
	 *
	 * @param bool $pos Whether it originated at POS.
	 */
	private function order( bool $pos = true ): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( $pos ? 'woocommerce-pos' : 'checkout' );
		$order->set_status( $pos ? 'pos-open' : 'pending' );
		$order->set_total( '100.00' );
		foreach ( array(
			'_wcpos_register' => wp_generate_uuid4(),
			'_wcpos_session' => wp_generate_uuid4(),
			'_pos_store' => 12,
			'_pos_user' => get_current_user_id(),
			'_wcpos_sale_time' => '2026-09-11T10:00:00',
			'_wcpos_sale_tz' => 'Europe/Madrid',
		) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}

	/** Payment complete writes matching sale once. */
	public function test_payment_complete_writes_matching_sale_once(): void {
		$order = $this->order();
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( (int) $order->get_meta( '_wcpos_receipt_sequence' ), $sale['number'] );
		$this->assertSame( $order->get_meta( '_wcpos_receipt_snapshot_checksum' ), $sale['checksum'] );
		$this->assertSame( Receipt_Snapshot_Store::instance()->get_snapshot( $order->get_id() ), $sale['payload'] );
		$this->assertSame( $order->get_meta( '_wcpos_register' ), $sale['register_id'] );
		$this->assertSame( 12, $sale['store_id'] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$this->assertSame( array( $sale ), $store->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Existing snapshot is repaired without new receipt sequence. */
	public function test_existing_snapshot_is_repaired_without_new_receipt_sequence(): void {
		$order = $this->order();
		$payload = array(
			'fiscal' => array(
				'sequence' => 77,
				'immutable_id' => 'legacy:77',
			),
		);
		$order->update_meta_data( '_wcpos_receipt_snapshot', wp_json_encode( $payload ) );
		$order->update_meta_data( '_wcpos_receipt_sequence', '77' );
		$order->save();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertSame( 77, $sale['number'] );
		$this->assertSame( $payload, $sale['payload'] );
	}

	/** Online payment and refund write no records. */
	public function test_online_payment_and_refund_write_no_records(): void {
		$order = $this->order( false );
		$order->payment_complete();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'reason' => 'Return',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Captured manual void records before and after rows. */
	public function test_captured_manual_void_records_before_and_after_rows(): void {
		$order = $this->order();
		$store = new Fiscal_Record_Store();
		// A sale anchor on an in-progress order: Ledger refuses voids on completed orders.
		Receipt_Snapshot_Store::instance()->persist_snapshot( $order->get_id(), array( 'fiscal' => array() ) );
		$sale = $store->find_sale( $order->get_id() );
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '20.00',
			)
		);
		$this->assertIsArray( $row );
		$applied = Ledger::instance()->void( $order, $row['id'], 'Changed mind' );
		$this->assertSame( 'voided', $applied['status'] );
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'void',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
		$this->assertSame( $row, $records[0]['payload']['row'] );
		$this->assertSame( $applied, $records[0]['payload']['voided_row'] );
		$this->assertSame( 'Changed mind', $records[0]['payload']['reason'] );
		$this->assertSame( get_current_user_id(), $records[0]['payload']['actor_id'] );
		$this->assertNull( $records[0]['device_time'] );
		$this->assertNull( $records[0]['device_tz'] );
	}

	/** Pending and authorized voids fire action but write no records. */
	public function test_pending_and_authorized_voids_fire_action_but_write_no_records(): void {
		foreach ( array( 'pending', 'authorized' ) as $status ) {
			$order = $this->order();
			$row = array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '20.00',
				'status' => $status,
				'kind' => 'cash',
				'capture_mode' => 'manual',
			);
			Ledger::instance()->save( $order, array( $row ) );
			$before = did_action( 'woocommerce_pos_payment_voided' );
			$applied = Ledger::instance()->void( $order, $row['id'], 'Cancelled' );
			$this->assertSame( 'voided', $applied['status'] );
			$this->assertSame( $before + 1, did_action( 'woocommerce_pos_payment_voided' ) );
			$this->assertSame(
				array(),
				( new Fiscal_Record_Store() )->list(
					array(
						'order_id' => $order->get_id(),
						'type' => 'void',
					)
				)
			);
		}
	}

	/** Cancellation requires pos sale and is write once. */
	public function test_cancellation_requires_pos_sale_and_is_write_once(): void {
		$store = new Fiscal_Record_Store();
		$order = $this->order();
		$order->payment_complete();
		$sale = $store->find_sale( $order->get_id() );
		$order->update_status( 'cancelled' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_order_status_cancelled', $order->get_id(), $order );
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'cancellation',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
		$this->assertSame(
			array(
				'record_id' => $sale['id'],
				'number' => $sale['number'],
				'immutable_id' => $sale['payload']['fiscal']['immutable_id'],
				'checksum' => $sale['checksum'],
			),
			$records[0]['payload']['sale']
		);
		$cart = $this->order();
		$cart->update_status( 'cancelled' );
		$this->assertSame( array(), $store->list( array( 'order_id' => $cart->get_id() ) ) );
		$online = $this->order( false );
		$online->payment_complete();
		$online->update_status( 'cancelled' );
		$this->assertSame( array(), $store->list( array( 'order_id' => $online->get_id() ) ) );
	}

	/** Admin refunds get independent numbers and sale link. */
	public function test_admin_refunds_get_independent_numbers_and_sale_link(): void {
		$order = $this->order();
		$order->payment_complete();
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		foreach ( array( 1, 2 ) as $number ) {
			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => 10,
					'reason' => 'Return',
					'refund_payment' => false,
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
			do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
			$records = $store->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'refund',
				)
			);
			$this->assertCount( $number, $records );
			$this->assertSame( $number, $records[0]['number'] );
			$this->assertSame( $refund->get_id(), $records[0]['refund_id'] );
			$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
			$this->assertSame( 'refund', $records[0]['payload']['fiscal']['document_type'] );
			$this->assertEquals( 10, $records[0]['payload']['totals']['total_incl'] );
			$this->assertSame( 'unallocated', $records[0]['payload']['fiscal']['extra_fields']['allocation'] );
			$this->assertSame( array(), $records[0]['payload']['fiscal']['extra_fields']['allocations'] );
			$this->assertNull( $records[0]['device_time'] );
			$this->assertNull( $records[0]['device_tz'] );
		}
	}

	/** Refund seam preserves allocations. */
	public function test_refund_seam_preserves_allocations(): void {
		$order = $this->order();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( 5 );
		$allocations = array(
			array(
				'payment_id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '5.00',
			),
		);
		$refund->update_meta_data( '_wcpos_refund_allocations', $allocations );
		$refund->save();
		Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
		$payload = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0]['payload'];
		$this->assertSame( $allocations, $payload['fiscal']['extra_fields']['allocations'] );
		$this->assertSame( 'allocated', $payload['fiscal']['extra_fields']['allocation'] );
	}
	/** Admin refund freezes positive lines and single cash allocation. */
	public function test_admin_refund_freezes_positive_lines_and_single_cash_allocation(): void {
		$order = $this->order();
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Returned item' );
		$item->set_quantity( 2 );
		$item->set_subtotal( 100 );
		$item->set_total( 100 );
		$order->add_item( $item );
		$order->save();
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100.00',
			)
		);
		$this->assertIsArray( $row );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 25,
				'refund_payment' => false,
				'line_items' => array(
					$item->get_id() => array(
						'qty' => 1,
						'refund_total' => 25,
					),
				),
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$record = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0];
		$data = $record['payload'];
		$this->assertSame( 'refund', $data['fiscal']['document_type'] );
		$this->assertSame( '1', $data['fiscal']['receipt_number'] );
		$this->assertSame( $sale['payload']['fiscal']['immutable_id'], $data['fiscal']['corrects'] );
		$this->assertEquals( 25, $data['lines'][0]['line_total_excl'] );
		$this->assertEquals( 1, $data['lines'][0]['qty'] );
		$this->assertSame( 'allocated', $data['fiscal']['extra_fields']['allocation'] );
		$this->assertSame(
			array(
				array(
					'payment_id' => $row['id'],
					'method_id' => 'pos_cash',
					'amount' => '25.00',
				),
			),
			$data['fiscal']['extra_fields']['allocations']
		);
		$this->assertSame( $row['id'], $data['payments'][0]['payment_id'] );
		$this->assertEquals( 25, $data['payments'][0]['amount'] );
		$order->set_billing_first_name( 'Edited later' );
		$item->set_total( 80 );
		$item->save();
		$order->save();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
		$this->assertSame( $data, $store->get( $record['id'] )['payload'] );
	}

	/** Refund with two counting rows without meta is unallocated. */
	public function test_refund_with_two_counting_rows_without_meta_is_unallocated(): void {
		$order = $this->order();
		$rows = array();
		foreach ( array( 'authorized', 'captured' ) as $status ) {
			$rows[] = array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '50.00',
				'status' => $status,
			);
		}
		Ledger::instance()->save( $order, $rows );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$data = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0]['payload'];
		$this->assertSame( 'unallocated', $data['fiscal']['extra_fields']['allocation'] );
		$this->assertSame( array(), $data['fiscal']['extra_fields']['allocations'] );
		$this->assertSame( array(), $data['payments'] );
	}
	/** Late sales have one correction, while replay of an included sale has none. */
	public function test_late_sale_is_replay_safe_and_does_not_mutate_closure(): void {
		$session = $this->closure_session();
		$store = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$closure = $store->create( $this->closure_fields( $session ) );
		$order = $this->closure_ledger( $session );
		$writers = Fiscal_Record_Writers::instance();
		$writers->record_sale( $order, array( 'fiscal' => array() ), 876543 );
		$writers->record_sale( $order, array( 'fiscal' => array() ), 876543 );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $closure['id'], $records[0]['closure_id'] );
		$this->assertSame( '40.0000', $records[0]['payload']['expected_delta']['cash'] );
		$this->assertSame( '-40.0000', $records[0]['payload']['variance_delta']['cash'] );
		$this->assertCount( 4, $records[0]['payload']['tender_rows'] );
		$this->assertSame( $closure, $store->get( $closure['id'] ) );
		$open = $this->closure_session( null, 'open' );
		$early = $this->closure_ledger( $open );
		$writers->record_sale( $early, array(), 876544 );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $early->get_id(),
				)
			)
		);
		$sessions = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$counting = $sessions->transition(
			$open,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$store->create( $this->closure_fields( $counting ) );
		$writers->record_sale( $early, array(), 876544 );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $early->get_id(),
				)
			)
		);
		$order->delete( true );
		$early->delete( true );
	}
}
