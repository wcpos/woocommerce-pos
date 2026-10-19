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
use WCPOS\WooCommercePOS\Services\Service_Groups;
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
		add_action( 'woocommerce_payment_complete', array( $writers, 'handle_payment_complete' ) );
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

	/** Payment completion freezes one numbered document without order-meta copies. */
	public function test_payment_complete_writes_sale_once_without_meta_or_i18n(): void {
		$order = $this->order();
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( (int) get_option( 'wcpos_fiscal_sequence_sale' ), $sale['number'] );
		$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
		$this->assertSame( (string) $sale['number'], $sale['payload']['fiscal']['receipt_number'] );
		$this->assertArrayNotHasKey( 'i18n', $sale['payload'] );
		foreach ( array( 'snapshot', 'sequence', 'snapshot_checksum', 'snapshot_created_at_gmt', 'submission_status', 'submission_status_updated_at' ) as $key ) {
			$this->assertSame( '', $order->get_meta( '_wcpos_receipt_' . $key, true ) );
		}
		$this->assertSame( $order->get_meta( '_wcpos_register' ), $sale['register_id'] );
		$this->assertSame( 12, $sale['store_id'] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$this->assertSame( array( $sale ), $store->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Building must succeed before a number is taken, without undoing payment. */
	public function test_failed_sale_build_consumes_no_number(): void {
		$order = $this->order();
		$before = get_option( 'wcpos_fiscal_sequence_sale' );
		$filter = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'woocommerce_pos_receipt_data', $filter );
		try {
			$order->payment_complete();
		} finally {
			remove_filter( 'woocommerce_pos_receipt_data', $filter );
		}

		$this->assertNull( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		$this->assertSame( $before, get_option( 'wcpos_fiscal_sequence_sale' ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/** Extension errors are contained and leave a visible recovery signal. */
	public function test_enrich_filter_throwing_error_is_contained(): void {
		$order = $this->order();
		$failed = array();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		$observe = static function ( $type ) use ( &$failed ) {
			$failed[] = $type;
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		add_action( 'woocommerce_pos_fiscal_record_failed', $observe );
		try {
			$order->payment_complete();
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
			remove_action( 'woocommerce_pos_fiscal_record_failed', $observe );
		}

		$this->assertNull( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'could not be written', implode( '\n', wp_list_pluck( $notes, 'content' ) ) );
		$this->assertSame( array( 'sale' ), $failed );
	}

	/** Enrichers receive the minted identity and the label-free document. */
	public function test_enrich_filter_sees_minted_number_and_no_i18n(): void {
		$order = $this->order();
		$seen = null;
		$enrich = static function ( array $snapshot, int $id ) use ( &$seen ) {
			$seen = array( $snapshot, $id );
			$snapshot['fiscal']['qr_payload'] = 'ENRICHED';
			return $snapshot;
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich, 10, 2 );
		try {
			$order->payment_complete();
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertSame( $sale['number'], $seen[0]['fiscal']['sequence'] );
		$this->assertSame( $order->get_id(), $seen[1] );
		$this->assertArrayNotHasKey( 'i18n', $seen[0] );
		$this->assertSame( 'ENRICHED', $sale['payload']['fiscal']['qr_payload'] );
	}

	/** Invalid extension returns cannot discard the sale. */
	public function test_enrich_filter_returning_non_array_is_ignored(): void {
		$order = $this->order();
		$enrich = static function () {
			return 'oops';
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$order->payment_complete();
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( '', $sale['payload']['fiscal']['qr_payload'] );
		$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
	}

	/** A storefront webhook arms the writer before completing a POS payment. */
	public function test_payment_complete_from_storefront_lane_writes_sale(): void {
		$writers = Fiscal_Record_Writers::instance();
		remove_action( 'woocommerce_payment_complete', array( $writers, 'handle_payment_complete' ) );
		$instance = new \ReflectionProperty( Fiscal_Record_Writers::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		Service_Groups::reset();
		Service_Groups::arm_order_group();
		try {
			$order = $this->order();
			$order->payment_complete();
			$this->assertIsArray( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		} finally {
			$instance->setValue( null, $writers );
			Service_Groups::reset();
		}
	}

	/** Online payment and refund write no records. */
	public function test_online_payment_and_refund_write_no_records(): void {
		$order = $this->order( false );
		$order->payment_complete();
		$this->assertFalse( get_option( 'wcpos_fiscal_sequence_sale' ) );
		$fresh = wc_get_order( $order->get_id() );
		foreach ( array( 'snapshot', 'sequence', 'snapshot_checksum', 'snapshot_created_at_gmt', 'submission_status', 'submission_status_updated_at' ) as $key ) {
			$this->assertSame( '', $fresh->get_meta( '_wcpos_receipt_' . $key, true ) );
		}
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

	/** The refund hash covers the stored dataset, with only its hash blanked. */
	public function test_refund_document_hash_covers_stored_bytes_without_i18n(): void {
		$order = $this->order();
		$order->payment_complete();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$store = new Fiscal_Record_Store();
		$row = $store->find_refund( $order->get_id(), $refund->get_id() );
		$this->assertIsArray( $row );
		$stored = $row['payload'];
		$this->assertArrayNotHasKey( 'i18n', $stored );
		$copy = $stored;
		$copy['fiscal']['hash'] = '';
		$this->assertSame( Fiscal_Record_Store::checksum( wp_json_encode( $copy ) ), $stored['fiscal']['hash'] );
		$this->assertSame( Fiscal_Record_Store::checksum( wp_json_encode( $stored ) ), $row['checksum'] );
	}

	/** Captured manual void records before and after rows. */
	public function test_captured_manual_void_records_before_and_after_rows(): void {
		$order = $this->order();
		$store = new Fiscal_Record_Store();
		// A sale anchor on an in-progress order: Ledger refuses voids on completed orders.
		Fiscal_Record_Writers::instance()->record_sale( $order );
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
			$this->assertNull( $records[0]['session_id'] );
			$this->assertNull( $records[0]['register_id'] );
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
		// Two captured rows: an authorization no longer counts (ADR 0040 D6).
		foreach ( array( 'captured', 'captured' ) as $status ) {
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
	/** The audit payload freezes all tenders and variance for the counted tenders. */
	public function test_late_sale_freezes_expected_delta_for_every_tender(): void {
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fields['counted'] = array(
			'cash' => '101',
			'card' => '30',
		);
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $fields );
		$order = $this->closure_ledger( $session );
		// Equal-second ordering: the sale row arrives in the closure's own second.
		global $wpdb;
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => $closure['received_at_gmt'] ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame(
			array(
				'cash' => '50.0000',
				'card' => '30.0000',
			),
			$records[0]['payload']['expected_delta']
		);
		$this->assertSame(
			array(
				'cash' => '-50.0000',
				'card' => '-30.0000',
			),
			$records[0]['payload']['variance_delta']
		);
	}

	/** Late sales have one correction, while replay of an included sale has none. */
	public function test_late_sale_is_replay_safe_and_does_not_mutate_closure(): void {
		$session = $this->closure_session();
		$store = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$closure = $store->create( $this->closure_fields( $session ) );
		$order = $this->closure_ledger( $session );
		$rows = Ledger::instance()->read( $order );
		$rows[0]['method_id'] = 'extension_cash_drawer';
		Ledger::instance()->save( $order, $rows, false );
		$writers = Fiscal_Record_Writers::instance();
		$writers->record_sale( $order );
		$writers->record_sale( $order );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $closure['id'], $records[0]['closure_id'] );
		$this->assertSame( '50.0000', $records[0]['payload']['expected_delta']['cash'] );
		$this->assertSame( '-50.0000', $records[0]['payload']['variance_delta']['cash'] );
		// The fixture binds two captured rows to the session: cash 50 (refunded 10) and card 30.
		$this->assertCount( 2, $records[0]['payload']['tender_rows'] );
		$this->assertSame( $closure, $store->get( $closure['id'] ) );
		$open = $this->closure_session( null, 'open' );
		$early = $this->closure_ledger( $open );
		$writers->record_sale( $early );
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
		$writers->record_sale( $early );
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

	/** Replaying a sale captured before close does not duplicate its frozen ledger. */
	public function test_repaired_preclosure_sale_is_not_a_late_sale(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		global $wpdb;
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => '2000-01-01 00:00:00' ), array( 'id' => $sale['id'] ) );
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );

		Fiscal_Record_Writers::instance()->record_sale( $order );

		$records = ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $records );
		$this->assertSame( 'sale', $records[0]['type'] );
		$this->assertSame( $closure, ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $closure['id'] ) );
		$order->delete( true );
	}
	/** Only the newly arrived leg belongs in the correction. */
	public function test_late_sale_freezes_only_the_legs_the_closure_did_not_count(): void {
		$session = $this->closure_session( null, 'open' );
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = array( $ledger->read( $order )[0] );
		$ledger->save( $order, $rows, false );
		$order->set_status( 'pos-partial' );
		$order->save();
		$session = ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$leg_b = wp_generate_uuid4();
		$rows[] = array_merge(
			$rows[0],
			array(
				'id' => $leg_b,
				'amount' => '20',
			)
		);
		$ledger->save( $order, $rows, false );
		Fiscal_Record_Writers::instance()->record_sale( $order, array( 'fiscal' => array() ), 876546 );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( array( $leg_b ), array_column( $records[0]['payload']['tender_rows'], 'id' ) );
		$this->assertSame( '20.0000', $records[0]['payload']['expected_delta']['cash'] );
	}

	/** Arrival clocks cannot turn counted legs into late sales. */
	public function test_sale_counted_by_the_closure_is_not_late_whatever_its_snapshot_clock(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		global $wpdb;
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		// The sale record's arrival time is the clock the old rule compared; push it past the close.
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $closure['received_at_gmt'] . ' UTC' ) + HOUR_IN_SECONDS ) ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order, array( 'fiscal' => array() ), 876547 );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
	}
}
