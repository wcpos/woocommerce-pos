<?php
/**
 * Fiscal record writer tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Service_Groups;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Register_Sale;

/** Fiscal lifecycle observers and immutable corrections. */
class Test_Fiscal_Record_Writers extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;
	use Money_Route_Envelope;

	/** Restore writer hooks between tests. */
	public function setUp(): void {
		parent::setUp();
		// WP restores hooks between tests, while service singletons survive.
		$writers = Fiscal_Record_Writers::instance();
		add_action( 'wcpos_payment_voided', array( $writers, 'handle_void' ), 10, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $writers, 'handle_cancellation' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $writers, 'handle_refund' ), 10, 2 );
		add_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10, 2 );
		add_action( 'wcpos_payment_corrected', array( $writers, 'handle_correction' ), 10, 4 );
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

	/**
	 * Complete the order as a money command at a register would (ADR 0040 rule 1c): the request
	 * context is the register origin, so the completion is stamped and its sale record written.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function pay( \WC_Order $order ): void {
		Register_Sale::complete( $order, array() );
	}

	/**
	 * Give a fixture order a completion entry without completing it, as the anchor its sale record names.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function seed_completion( \WC_Order $order ): void {
		Completion_Fixture::seed( $order );
	}

	/**
	 * Reopen a completed order in place (the push lane's status change, without the push).
	 *
	 * @param \WC_Order $order Order.
	 */
	private function reopen( \WC_Order $order ): \WC_Order {
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'pos-open' );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * The order's sale records, oldest first.
	 *
	 * @param int $order_id Order ID.
	 */
	private function sales( int $order_id ): array {
		return array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order_id,
					'type' => 'sale',
				)
			)
		);
	}

	/** Sale-day corrections name the latest completion without changing any sale or session. */
	public function test_sale_day_correction_appends_to_latest_sale(): void {
		$order = $this->order();
		$this->pay( $order );
		$this->pay( $this->reopen( $order ) );
		$sales = $this->sales( $order->get_id() );
		$actor = get_current_user_id();
		$from = '2026-11-24T09:00:00Z';
		$to = '2026-11-23T09:00:00Z';
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );

		$this->assertTrue( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, $actor, $from, $to, 'Correct sale day', '2026-11-24 10:11:12' ) );

		$this->assertContains( 'sale_day_correction', Fiscal_Record_Store::TYPES );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'sale_day_correction',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sales[1]['id'], $records[0]['corrects_record_id'] );
		$this->assertSame( $actor, $records[0]['approver_id'] );
		$this->assertSame( 12, $records[0]['store_id'] );
		$this->assertSame( $actor, $records[0]['cashier_id'] );
		$this->assertSame( '2026-11-24 10:11:12', $records[0]['payload']['changed_at_gmt'] );
		$this->assertSame(
			array(
				array(
					'field' => 'sale_day',
					'from' => $from,
					'to' => $to,
				),
			),
			$records[0]['payload']['changes']
		);
		$this->assertSame( 'Correct sale day', $records[0]['payload']['reason'] );
		$this->assertSame( $actor, $records[0]['payload']['changed_by'] );
		foreach ( array( 'register_id', 'session_id', 'closure_id', 'carried_by_closure_id' ) as $key ) {
			$this->assertNull( $records[0][ $key ], $key );
		}
		$this->assertSame( $sales, $this->sales( $order->get_id() ) );
	}

	/** Non-numeric store identifiers never become numeric record attribution. */
	public function test_sale_day_correction_non_numeric_store_is_not_coerced(): void {
		$order = $this->order();
		$this->pay( $order );
		$order->update_meta_data( '_pos_store', '12-store' );
		$order->save();
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'sale_day_correction',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertNull( $records[0]['store_id'] );
		$this->assertSame( get_current_user_id(), $records[0]['cashier_id'] );
	}

	/**
	 * A retry of the same request reuses its record; a later change appends another.
	 */
	public function test_sale_day_correction_retry_reuses_its_record_and_a_later_change_appends(): void {
		$order = $this->order();
		$this->pay( $order );
		$writers = Fiscal_Record_Writers::instance();
		$store   = new Fiscal_Record_Store();
		$query   = array(
			'order_id' => $order->get_id(),
			'type' => 'sale_day_correction',
		);
		$this->assertTrue( $writers->record_sale_day_correction( $order, 1, '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );
		// The order's meta was not saved (the write after the record failed): the same request again.
		$this->assertTrue( $writers->record_sale_day_correction( $order, 1, '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:20' ) );
		$this->assertCount( 1, $store->list( $query ) );
		$order->update_meta_data( '_wcpos_sale_day', '{"at_gmt":"2026-11-23T09:00:00Z","reason":"Correct sale day","by":1,"changed_at_gmt":"2026-11-24T10:11:12Z"}' );
		$order->save();
		$this->assertTrue( $writers->record_sale_day_correction( wc_get_order( $order->get_id() ), 1, '2026-11-23T09:00:00Z', '2026-11-22T09:00:00Z', 'Again', '2026-11-24 10:12:00' ) );
		$this->assertCount( 2, $store->list( $query ) );
	}

	/** Sale-day correction follows the existing attribution record contract. */
	public function test_sale_day_correction_without_sale_returns_true(): void {
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );
		$order = $this->order();
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Sale-day correction follows the existing attribution record contract. */
	public function test_sale_day_correction_reopened_legacy_without_sale_returns_true_without_record(): void {
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );
		// Arrange: completing outside a register leaves no history or fiscal sale.
		$order = $this->order();
		$order->set_date_paid( time() );
		$order->set_status( 'completed' );
		$order->save();
		$this->assertNull( Completion_History::of( $order )->latest() );
		$order = $this->reopen( $order );
		$this->assertTrue( Completion_History::of( $order )->latest()['legacy'] );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );

		// Act and assert.
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Sale-day correction follows the existing attribution record contract. */
	public function test_sale_day_correction_failed_insert_returns_false_without_recovery_note(): void {
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );
		$order = $this->order();
		$this->pay( $order );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$failed = array();
		$observe = static function ( $type ) use ( &$failed ): void {
			$failed[] = $type;
		};
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale_day_correction', $stuck, 10, 2 );
		add_action( 'wcpos_fiscal_record_failed', $observe );
		try {
			$result = Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale_day_correction', $stuck, 10 );
			remove_action( 'wcpos_fiscal_record_failed', $observe );
		}

		$this->assertFalse( $result );
		$this->assertSame( array( 'sale_day_correction' ), $failed );
		$this->assertEquals( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'sale_day_correction',
				)
			)
		);
	}

	/** Sale-day correction follows the existing attribution record contract. */
	public function test_sale_day_correction_latest_sale_insert_still_failing_returns_false_without_correction(): void {
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );
		// Arrange: the older completion has a sale, but the latest document is parked.
		$order = $this->order();
		$this->pay( $order );
		$order = $this->reopen( $order );
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
				$order = wc_get_order( $order->get_id() );
				$latest = Completion_History::of( $order )->latest();
				$this->assertSame( array( $latest['id'] ), array_keys( $this->parked( $order->get_id() ) ) );

				// Act and assert: recovery still cannot insert the latest sale.
				$this->assertFalse( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );
			}
		);
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertCount( 1, $this->parked( $order->get_id() ) );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'sale_day_correction',
				)
			)
		);
	}

	/** Sale-day correction follows the existing attribution record contract. */
	public function test_sale_day_correction_latest_parked_sale_recovers_and_corrects_that_sale(): void {
		$this->assertTrue( method_exists( Fiscal_Record_Writers::instance(), 'record_sale_day_correction' ) );
		// Arrange.
		$order = $this->order();
		$this->pay( $order );
		$order = $this->reopen( $order );
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
			}
		);
		$order = wc_get_order( $order->get_id() );
		$latest = Completion_History::of( $order )->latest();
		$this->assertSame( array( $latest['id'] ), array_keys( $this->parked( $order->get_id() ) ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );

		// Act.
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_sale_day_correction( $order, get_current_user_id(), '2026-11-24T09:00:00Z', '2026-11-23T09:00:00Z', 'Correct sale day', '2026-11-24 10:11:12' ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $latest['id'], $sales[1]['source_id'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'sale_day_correction',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sales[1]['id'], $records[0]['corrects_record_id'] );
	}

	/** Repeated corrections append to the latest sale without entering a session or closure. */
	public function test_reattribution_appends_changes_to_latest_sale_only(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );
		$this->pay( $this->reopen( $order ) );
		$store = new Fiscal_Record_Store();
		$sales = $this->sales( $order->get_id() );
		$actor_id = get_current_user_id();
		$writers = Fiscal_Record_Writers::instance();
		$this->assertContains( 'reattribution', Fiscal_Record_Store::TYPES );

		// Act: a second correction back to the original values is a new document.
		$this->assertTrue( $writers->record_reattribution( $order, $actor_id, 1, 2, '12', '13' ) );
		$this->assertTrue( $writers->record_reattribution( $order, $actor_id, 2, 1, '13', '12' ) );

		// Assert.
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'reattribution',
			)
		);
		$this->assertCount( 2, $records );
		$this->assertNotSame( $records[0]['source_id'], $records[1]['source_id'] );
		$this->assertSame(
			array(
				array(
					'field' => 'cashier',
					'from' => 1,
					'to' => 2,
				),
				array(
					'field' => 'store',
					'from' => '12',
					'to' => '13',
				),
			),
			$records[1]['payload']['changes']
		);
		foreach ( $records as $record ) {
			$this->assertSame( $sales[1]['id'], $record['corrects_record_id'] );
			foreach ( array( 'register_id', 'session_id', 'closure_id', 'carried_by_closure_id' ) as $field ) {
				$this->assertNull( $record[ $field ], $field );
			}
		}
		$this->assertSame( $sales, $this->sales( $order->get_id() ) );
		$this->assertSame(
			0,
			$store->count(
				array(
					'type' => 'reattribution',
					'session_id' => $order->get_meta( '_wcpos_session' ),
				)
			)
		);
	}

	/** Recovering an earlier sale last does not make it the completion a correction names. */
	public function test_reattribution_after_out_of_order_recovery_corrects_latest_completion_sale(): void {
		// Arrange: completion 1's sale is parked, then completion 2's sale lands first.
		$order = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$this->pay( $this->reopen( $order ) );
		$order = wc_get_order( $order->get_id() );
		$writers = Fiscal_Record_Writers::instance();
		$writers->recover( $order );
		$sales = $this->sales( $order->get_id() );
		$history = Completion_History::of( $order )->entries();
		$this->assertCount( 2, $sales );
		$this->assertSame( $history[1]['id'], $sales[0]['source_id'] );
		$this->assertSame( $history[0]['id'], $sales[1]['source_id'] );

		// Act.
		$this->assertTrue( $writers->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' ) );

		// Assert.
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'reattribution',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sales[0]['id'], $records[0]['corrects_record_id'] );
	}

	/** A missing latest sale must not redirect a correction to an older completion. */
	public function test_reattribution_latest_sale_insert_still_failing_returns_false_without_correction(): void {
		// Arrange: the older completion has a sale, but the latest document is parked.
		$order = $this->order();
		$this->pay( $order );
		$order = $this->reopen( $order );
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
				$order = wc_get_order( $order->get_id() );
				$latest = Completion_History::of( $order )->latest();
				$this->assertSame( array( $latest['id'] ), array_keys( $this->parked( $order->get_id() ) ) );

				// Act and assert: recovery still cannot insert the latest sale.
				$this->assertFalse( Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' ) );
			}
		);
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertCount( 1, $this->parked( $order->get_id() ) );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'reattribution',
				)
			)
		);
	}

	/** Recovery inserts the latest completion's parked sale before its attribution correction. */
	public function test_reattribution_latest_parked_sale_recovers_and_corrects_that_sale(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );
		$order = $this->reopen( $order );
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
			}
		);
		$order = wc_get_order( $order->get_id() );
		$latest = Completion_History::of( $order )->latest();
		$this->assertSame( array( $latest['id'] ), array_keys( $this->parked( $order->get_id() ) ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );

		// Act.
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $latest['id'], $sales[1]['source_id'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'reattribution',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sales[1]['id'], $records[0]['corrects_record_id'] );
	}

	/** Legacy orders without completion history still correct their newest sale row. */
	public function test_reattribution_without_completion_history_corrects_newest_sale(): void {
		// Arrange: legacy rows have no completion history on the order.
		$order = $this->order();
		$store = new Fiscal_Record_Store();
		foreach ( array( 'older', 'newest' ) as $source ) {
			$sale = $store->record(
				array(
					'type' => 'sale',
					'order_id' => $order->get_id(),
					'source_id' => $source,
					'payload' => array(),
				)
			);
			$this->assertIsArray( $sale );
		}
		$this->assertNull( Completion_History::of( $order )->latest() );

		// Act.
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' ) );

		// Assert.
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'reattribution',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
	}

	/** Orders without a sale may be reassigned without a fiscal correction. */
	public function test_reattribution_without_sale_returns_true(): void {
		$order = $this->order();
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, 'store-a', 'store-b' ) );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** A reopened legacy completion without a sale has nothing to correct. */
	public function test_reattribution_reopened_legacy_without_sale_returns_true_without_record(): void {
		// Arrange: completing outside a register leaves no history or fiscal sale.
		$order = $this->order();
		$order->set_date_paid( time() );
		$order->set_status( 'completed' );
		$order->save();
		$this->assertNull( Completion_History::of( $order )->latest() );
		$order = $this->reopen( $order );
		$this->assertTrue( Completion_History::of( $order )->latest()['legacy'] );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );

		// Act and assert.
		$this->assertTrue( Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' ) );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** A failed correction signals listeners but must not ask for sale recovery. */
	public function test_reattribution_failed_insert_returns_false_without_recovery_note(): void {
		$order = $this->order();
		$this->pay( $order );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$failed = array();
		$observe = static function ( $type ) use ( &$failed ): void {
			$failed[] = $type;
		};
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_reattribution', $stuck, 10, 2 );
		add_action( 'wcpos_fiscal_record_failed', $observe );
		try {
			$result = Fiscal_Record_Writers::instance()->record_reattribution( $order, get_current_user_id(), 1, 2, '12', '13' );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_reattribution', $stuck, 10 );
			remove_action( 'wcpos_fiscal_record_failed', $observe );
		}

		$this->assertFalse( $result );
		$this->assertSame( array( 'reattribution' ), $failed );
		$this->assertEquals( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'reattribution',
				)
			)
		);
	}

	/** Two completions insert two sale rows through record(), the second superseding the first. */
	public function test_two_completions_write_two_sale_rows_through_record(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );

		// Act: reopened unchanged and completed again.
		$this->pay( $this->reopen( $order ) );

		// Assert.
		$sales   = $this->sales( $order->get_id() );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 2, $sales );
		$this->assertNotSame( $sales[0]['number'], $sales[1]['number'] );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( $history[1]['id'], $sales[1]['source_id'] );
		$this->assertNull( $sales[0]['corrects_record_id'] );
		$this->assertSame( 1, $sales[0]['payload']['fiscal']['completion'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['supersedes'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['adjustment'] );
		$this->assertSame( $sales[0]['id'], $sales[1]['corrects_record_id'] );
		$this->assertSame( 2, $sales[1]['payload']['fiscal']['completion'] );
		$this->assertSame(
			array(
				'record_id' => $sales[0]['id'],
				'completion_id' => $history[0]['id'],
				'total' => '100.00',
			),
			$sales[1]['payload']['fiscal']['supersedes']
		);
		$this->assertSame( '0.00', $sales[1]['payload']['fiscal']['adjustment'] );
	}

	/** Completion 2 at another register names that register in its frozen document as well as its columns. */
	public function test_a_recompletion_at_another_register_names_it_in_the_payload_and_the_columns(): void {
		// Arrange: completed at register A (the till tuple), reopened. Free holds one register.
		$this->use_multi_register_repository();
		$registers = new \WCPOS\WooCommercePOS\Services\Register_Store();
		$a         = $registers->create(
			array(
				'name' => 'Register A',
				'store_id' => 12,
			)
		)['id'];
		$b         = $registers->create(
			array(
				'name' => 'Register B',
				'store_id' => 12,
			)
		)['id'];
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_register', $a );
		$order->save();
		$this->pay( $order );
		$reopened = $this->reopen( $order );

		// Act: closed at register B.
		Register_Sale::complete(
			$reopened,
			array(
				'register_id' => $b,
				'sale_time' => '2026-10-20T09:15:00+02:00',
				'sale_tz' => 'Europe/Berlin',
				'sale_counter' => '42',
			)
		);

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $a, $sales[0]['register_id'] );
		$this->assertSame( $a, $sales[0]['payload']['register']['id'] );
		$this->assertSame( $b, $sales[1]['register_id'] );
		$this->assertSame( $b, $sales[1]['payload']['register']['id'] );
		$this->assertSame( 'Register B', $sales[1]['payload']['register']['name'] );
		$this->assertSame( 'Europe/Berlin', $sales[1]['payload']['fiscal']['sale_tz'] );
		$this->assertSame( 42, $sales[1]['payload']['fiscal']['sale_counter'] );
		$this->assertSame( $a, wc_get_order( $order->get_id() )->get_meta( '_wcpos_register' ) );
	}

	/** Building completion 2's document leaves the order object the later listeners read untouched. */
	public function test_the_completion_copy_shares_no_meta_with_the_order(): void {
		// Arrange.
		$order    = $this->order();
		$till     = (string) $order->get_meta( '_wcpos_register' );
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$seen     = array();
		$listener = static function ( $completed ) use ( &$seen ): void {
			$seen[] = (string) $completed->get_meta( '_wcpos_register' );
		};
		add_action( 'wcpos_sale_completed', $listener, 20 );

		// Act: completed again at another register, after the sale writer (10) built its document.
		Register_Sale::complete( $reopened, array( 'register_id' => wp_generate_uuid4() ) );
		remove_action( 'wcpos_sale_completed', $listener, 20 );

		// Assert.
		$this->assertCount( 2, $this->sales( $order->get_id() ) );
		$this->assertSame( array( $till ), $seen );
	}

	/** A re-completion in another session is late against that session's closure, not the first sale's. */
	public function test_a_recompletion_in_a_closed_session_is_late_against_that_sessions_closure(): void {
		// Arrange: completed in session A (open, no closure); session B is already closed.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		$closure_b = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$take = static function ( \WC_Order $order, array $session, string $amount ): void {
			Register_Sale::cash(
				wc_get_order( $order->get_id() ),
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => $amount,
					'session_id' => $session['id'],
				),
				array(
					'register_id' => $session['register_id'],
					'session_id' => $session['id'],
				)
			);
		};
		$take( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();

		// Act: the extra 20.00 is taken in session B, after B's closure.
		$take( $order, $session_b, '20.00' );

		// Assert.
		$late = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 2, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->assertCount( 1, $late );
		$this->assertSame( $closure_b['id'], $late[0]['closure_id'] );
		$this->assertSame( '20.0000', $late[0]['payload']['expected_delta']['cash'] );
	}

	/** Each completion that lands after its session's closure writes its own late_sale. */
	public function test_each_late_completion_writes_its_own_late_sale(): void {
		// Arrange: sessions A and B are both already closed.
		$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$session_a = $this->closure_session();
		$session_b = $this->closure_session();
		$closure_a = $closures->create( $this->closure_fields( $session_a ) );
		$closure_b = $closures->create( $this->closure_fields( $session_b ) );
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$take = static function ( \WC_Order $order, array $session, string $amount ): void {
			Register_Sale::cash(
				wc_get_order( $order->get_id() ),
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => $amount,
					'session_id' => $session['id'],
				),
				array(
					'register_id' => $session['register_id'],
					'session_id' => $session['id'],
				)
			);
		};

		// Act: completion 1 in A, then reopened and completion 2 in B.
		$take( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		$take( $order, $session_b, '20.00' );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_History::of( $order )->entries();
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[1] );

		// Assert.
		$late  = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $late );
		$this->assertNotSame( $late[0]['source_id'], $late[1]['source_id'] );
		$this->assertSame( $closure_a['id'], $late[0]['closure_id'] );
		$this->assertSame( $sales[0]['id'], $late[0]['corrects_record_id'] );
		$this->assertSame( $closure_b['id'], $late[1]['closure_id'] );
		$this->assertSame( $sales[1]['id'], $late[1]['corrects_record_id'] );
		$this->assertSame( Fiscal_Record_Store::digest_source_id( 'late_sale:' . $sales[1]['id'] ), $late[1]['source_id'] );
	}

	/** A later late correction in the same closed session carries only the legs no earlier one carried. */
	public function test_a_later_late_sale_carries_only_new_legs(): void {
		// Arrange: session S is already closed.
		$session = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$order = $this->order();
		$order->update_meta_data( '_wcpos_session', $session['id'] );
		$order->save();
		$at   = array(
			'register_id' => $session['register_id'],
			'session_id' => $session['id'],
		);
		$cash = static function ( \WC_Order $order, string $amount ) use ( $session, $at ): void {
			Register_Sale::cash(
				$order,
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => $amount,
					'session_id' => $session['id'],
				),
				$at
			);
		};

		// Act: completion 1 (100.00), completion 2 adds 20.00, completion 3 adds nothing.
		$cash( $order, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		$cash( $order, '20.00' );
		$again = $this->reopen( $order );
		Register_Sale::complete( $again, $at );

		// Assert.
		$this->assertCount( 3, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$late = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
		$this->assertCount( 2, $late );
		$this->assertSame( array( '100.00' ), array_column( $late[0]['payload']['tender_rows'], 'amount' ) );
		$this->assertSame( array( '20.00' ), array_column( $late[1]['payload']['tender_rows'], 'amount' ) );
		$this->assertSame( '20.0000', $late[1]['payload']['expected_delta']['cash'] );
	}

	/** A refund names the standing completion's record; while that record is missing it names none, never n−1's. */
	public function test_a_refund_anchors_to_the_standing_completions_record(): void {
		// Arrange: completion 1 has a record; completion 2's insert fails.
		$order = $this->order();
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( wc_get_order( $order->get_id() ) );
			$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
			$this->assertCount( 1, $this->sales( $order->get_id() ) );

			// Act: a refund while completion 2's record is missing (the refund's drain runs and fails here).
			$first = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => 5,
					'refund_payment' => false,
				)
			);
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}

		// Assert: it names no sale, not completion 1's.
		$store = new Fiscal_Record_Store();
		$one   = $store->find_refund( $order->get_id(), $first->get_id() );
		$this->assertNull( $one['corrects_record_id'] );
		$this->assertNull( $one['payload']['fiscal']['corrects'] );
	}

	/** Once the standing completion's record is recovered (before any refund), a refund names it. */
	public function test_a_refund_after_recovery_names_the_recovered_record(): void {
		// Arrange: completion 2's insert fails, then a money command recovers it.
		$order = $this->order();
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();

		// Act.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);

		// Assert.
		$store = new Fiscal_Record_Store();
		$two   = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertIsArray( $two );
		$record = $store->find_refund( $order->get_id(), $refund->get_id() );
		$this->assertSame( $two['id'], $record['corrects_record_id'] );
		$this->assertSame( $two['payload']['fiscal']['immutable_id'], $record['payload']['fiscal']['corrects'] );
	}

	/**
	 * Take cash on the order in a session, as the till's money command does.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session row.
	 * @param string    $amount  Amount.
	 * @return array|\WP_Error The row.
	 */
	private function cash_in( \WC_Order $order, array $session, string $amount ) {
		return Register_Sale::cash(
			wc_get_order( $order->get_id() ),
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => $amount,
				'session_id' => $session['id'],
			),
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			)
		);
	}

	/** The late correction waits for its completion's own sale row, and lands when recovery writes it. */
	public function test_a_late_sale_waits_for_its_completions_sale_row(): void {
		// Arrange: completion 1 in open session A; session B is already closed.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$this->cash_in( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		// The sale sequence cannot advance, so record() answers null without throwing.
		$stuck = static fn( $value, $old ) => $old;

		// Act: completion 2 in B, whose sale insert fails.
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			$leg = $this->cash_in( $order, $session_b, '20.00' );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
		$store  = new Fiscal_Record_Store();
		$before = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		// The next money command on the order recovers the sale row.
		$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $leg['id'] . '/status' ) );

		// Assert.
		$this->assertSame( array(), $before );
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$two     = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertIsArray( $two );
		$late = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $late );
		$this->assertSame( $two['id'], $late[0]['corrects_record_id'] );
	}

	/** A completion whose sale row is missing is recovered by the refund from its completion-time document, never rebuilt. */
	public function test_a_missing_sale_is_recovered_after_a_refund_with_its_completion_time_document(): void {
		// Arrange: completion 1's sale insert fails.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
			$request->set_body_params(
				$this->envelope(
					$order,
					array(
						'payment' => array(
							'id' => wp_generate_uuid4(),
							'method_id' => 'pos_cash',
							'amount' => '100.00',
						),
					)
				)
			);
			$this->server->dispatch( $request );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: a refund, with no money command after it.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);

		// Assert: the refund drained the parked completion-time document (no refund in it) and names it.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( array(), $sales[0]['payload']['refunds'] );
		$this->assertEquals( 100, $sales[0]['payload']['totals']['total_incl'] );
		$this->assertSame( $sales[0]['id'], ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() )['corrects_record_id'] );
	}

	/** A wp-admin refund after a failed sale insert recovers the sale first and names it, with no second completion event. */
	public function test_a_wp_admin_refund_after_a_failed_sale_insert_names_the_recovered_sale(): void {
		// Arrange: completion 1's sale insert fails.
		$order     = $this->order();
		$announced = array();
		$listener  = static function ( $completed, $n ) use ( &$announced ): void {
			$announced[] = $n;
		};
		$enrich    = static function () {
			throw new \Error( 'boom' );
		};
		add_action( 'wcpos_sale_completed', $listener, 5, 2 );
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: a wp-admin refund, no money command in between.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);
		remove_action( 'wcpos_sale_completed', $listener, 5 );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $sales[0]['id'], ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() )['corrects_record_id'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$this->assertSame( array( 1 ), $announced );
	}

	/** A refund drains only under the order lock: held elsewhere, the drain is skipped and the next writer recovers. */
	public function test_a_refund_skips_the_drain_while_the_order_lock_is_held_elsewhere(): void {
		// Arrange: completion 1's sale insert fails; another request holds the order lock.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$elsewhere = new Order_Lock();
		$this->assertTrue( $elsewhere->acquire( $order->get_id() ) );

		// Act: a wp-admin refund while the lock is held.
		try {
			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => 5,
					'refund_payment' => false,
				)
			);
		} finally {
			$elsewhere->release( $order->get_id() );
		}

		// Assert: no drain, the refund names no sale, the document is still parked.
		$store = new Fiscal_Record_Store();
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertNull( $store->find_refund( $order->get_id(), $refund->get_id() )['corrects_record_id'] );
		$this->assertCount( 1, $this->parked( $order->get_id() ) );

		// Act: the refund writer again, with the lock free.
		Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );

		// Assert: the sale is recovered and no longer parked.
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** Recovery reads the parked map fresh, so a document parked after the caller loaded the order survives. */
	public function test_recover_reads_the_parked_map_fresh_so_a_document_parked_after_the_caller_loaded_the_order_survives(): void {
		// Arrange: completion 1's sale insert fails; the caller loads the order; completion 2's insert fails too.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
			$stale = wc_get_order( $order->get_id() );
			$this->pay( $this->reopen( $order ) );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertCount( 2, $this->parked( $order->get_id() ) );

		// Act: recovery from the copy loaded before completion 2 was parked.
		Fiscal_Record_Writers::instance()->recover( $stale );

		// Assert: both completions' documents are inserted and nothing stays parked.
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertSame( array( $history[0]['id'], $history[1]['id'] ), array_column( $this->sales( $order->get_id() ), 'source_id' ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** Recovery reads the history fresh too, so a completion parked after the caller loaded the order keeps its late correction. */
	public function test_recover_writes_the_late_sale_of_a_completion_parked_after_the_caller_loaded_the_order(): void {
		// Arrange: completion 1 in open session A and completion 2 in closed session B, both inserts
		// failing; the caller loaded the order between them.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		$closure_b = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$stale = null;
		$this->with_sale_inserts_failing(
			function () use ( $order, $session_a, $session_b, &$stale ): void {
				$this->cash_in( $order, $session_a, '100.00' );
				$stale    = wc_get_order( $order->get_id() );
				$reopened = $this->reopen( $order );
				$reopened->set_total( '120.00' );
				$reopened->save();
				$this->cash_in( $order, $session_b, '20.00' );
			}
		);
		$this->assertCount( 2, $this->parked( $order->get_id() ) );

		// Act: recovery from the copy loaded before completion 2.
		Fiscal_Record_Writers::instance()->recover( $stale );

		// Assert: both sales land, and completion 2's late correction names its own row and B's closure.
		$store   = new Fiscal_Record_Store();
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$two     = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertCount( 2, $this->sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$late = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $late );
		$this->assertSame( $two['id'], $late[0]['corrects_record_id'] );
		$this->assertSame( $closure_b['id'], $late[0]['closure_id'] );
	}

	/** A sale lookup that throws is contained by the refund writer, like any other fiscal failure. */
	public function test_a_refund_whose_sale_lookup_throws_fails_quietly(): void {
		// Arrange: a completed sale and its refund; then every fiscal-record query throws.
		$order = $this->order();
		$this->pay( $order );
		$refund  = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);
		$table   = ( new Fiscal_Record_Store() )->table_name();
		$throw   = static function ( string $sql ) use ( $table ): string {
			if ( false !== strpos( $sql, $table ) ) {
				throw new \RuntimeException( 'read failed' );
			}
			return $sql;
		};
		$failed  = array();
		$observe = static function ( $type ) use ( &$failed ): void {
			$failed[] = $type;
		};
		add_filter( 'query', $throw );
		add_action( 'wcpos_fiscal_record_failed', $observe );

		// Act.
		try {
			Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
		} finally {
			remove_filter( 'query', $throw );
			remove_action( 'wcpos_fiscal_record_failed', $observe );
		}

		// Assert: the throw reached fail_quietly(), not the refund request.
		$this->assertSame( array( 'refund' ), $failed );
	}

	/**
	 * Run $work while the sale sequence cannot advance, so record() answers null without throwing.
	 *
	 * @param callable $work Work.
	 */
	private function with_sale_inserts_failing( callable $work ): void {
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			$work();
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
	}

	/**
	 * The parked sale documents on the order, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	private function parked( int $order_id ): array {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( '_wcpos_fiscal_pending' === $meta->meta_key ) {
				return (array) json_decode( (string) maybe_unserialize( $meta->meta_value ), true );
			}
		}
		return array();
	}

	/** A failed insert parks the completion-time document; recovery inserts it, whatever the order says now. */
	public function test_a_failed_sale_insert_is_parked_and_recovered_with_its_completion_time_document(): void {
		// Arrange: completed, but the sale record cannot be inserted.
		$order = $this->order();
		$order->set_billing_address_1( 'Old Street 1' );
		$order->save();
		$row = null;
		$this->with_sale_inserts_failing(
			function () use ( $order, &$row ): void {
				$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
				$request->set_body_params(
					$this->envelope(
						$order,
						array(
							'payment' => array(
								'id' => wp_generate_uuid4(),
								'method_id' => 'pos_cash',
								'amount' => '100.00',
							),
						)
					)
				);
				$row = $this->server->dispatch( $request )->get_data()['payment'];
			}
		);
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertSame( array( $history[0]['id'] ), array_keys( $this->parked( $order->get_id() ) ) );
		// The paid order's address changes afterwards (the push's frozen-address path writes it).
		$edited = wc_get_order( $order->get_id() );
		$edited->set_billing_address_1( 'New Street 2' );
		$edited->save();

		// Act: the next money command.
		$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $row['id'] . '/status' ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( 'Old Street 1', $sales[0]['payload']['customer']['billing_address']['address_1'] );
		$this->assertSame( (string) $sales[0]['number'], $sales[0]['payload']['fiscal']['receipt_number'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A parked document survives a reopen and an edit, and is inserted unchanged. */
	public function test_a_parked_document_survives_a_reopen_and_is_inserted_unchanged(): void {
		// Arrange: completion 1's insert fails; the sale is reopened and its total edited.
		$order = $this->order();
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
			}
		);
		$history  = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();

		// Act.
		Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertEquals( 100, $sales[0]['payload']['totals']['total_incl'] );
		$this->assertSame( 1, $sales[0]['payload']['fiscal']['completion'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A late correction names the completion's session and register, not the order's write-once ones. */
	public function test_a_late_sale_carries_the_completions_provenance(): void {
		// Arrange: the order's write-once session is A (open); B is already closed.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->update_meta_data( '_wcpos_register', $session_a['register_id'] );
		$order->save();

		// Act: the sale completes in session B.
		$this->cash_in( $order, $session_b, '100.00' );

		// Assert.
		$late = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $late );
		$this->assertSame( $session_b['id'], $late[0]['session_id'] );
		$this->assertSame( $session_b['register_id'], $late[0]['register_id'] );
	}

	/** A completion that no longer stands is never built from the live order: parked or nothing. */
	public function test_record_sale_of_a_reopened_completion_with_nothing_parked_writes_nothing(): void {
		// Arrange: completed while the sale writer was not listening (no row, nothing parked), then reopened.
		$writers = Fiscal_Record_Writers::instance();
		$order   = $this->order();
		remove_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10 );
		try {
			$this->pay( $order );
		} finally {
			add_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10, 2 );
		}
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertSame( array(), $this->parked( $order->get_id() ) );

		// Act.
		$writers->record_sale( wc_get_order( $order->get_id() ), $history[0] );

		// Assert.
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
	}

	/** Replaying completion n returns row n and writes nothing. */
	public function test_replaying_completion_n_returns_row_n(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );
		$this->pay( $this->reopen( $order ) );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_History::of( $order )->entries();
		$before  = $this->sales( $order->get_id() );

		// Act.
		do_action( 'wcpos_sale_completed', $order, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replays the completion event.
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[0] );
		$replayed = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'sale',
				'order_id' => $order->get_id(),
				'source_id' => $history[0]['id'],
				'payload' => array( 'changed' => true ),
			)
		);

		// Assert.
		$this->assertSame( $before, $this->sales( $order->get_id() ) );
		$this->assertSame( $before[0], $replayed );
	}

	/** A failed sale insert is written by the next money command, with no second completion event. */
	public function test_a_failed_sale_insert_is_recovered_by_the_next_money_command_without_a_second_completion_event(): void {
		// Arrange: the completion's sale insert fails.
		$order     = $this->order();
		$announced = array();
		$listener  = static function ( $completed, $n ) use ( &$announced ): void {
			$announced[] = $n;
		};
		$enrich    = static function () {
			throw new \Error( 'boom' );
		};
		add_action( 'wcpos_sale_completed', $listener, 5, 2 );
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
			$request->set_body_params(
				$this->envelope(
					$order,
					array(
						'payment' => array(
							'id' => wp_generate_uuid4(),
							'method_id' => 'pos_cash',
							'amount' => '100.00',
						),
					)
				)
			);
			$recorded = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertSame( 200, $recorded->get_status() );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: the next money command on the order.
		$status = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $recorded->get_data()['payment']['id'] . '/status' ) );
		remove_action( 'wcpos_sale_completed', $listener, 5 );

		// Assert.
		$this->assertSame( 200, $status->get_status() );
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( Completion_History::of( wc_get_order( $order->get_id() ) )->entries()[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( array( 1 ), $announced );
	}

	/** An entry reopened before its row landed is not rebuilt from the edited order. */
	public function test_recovery_inserts_the_parked_document_of_an_entry_reopened_before_its_row_landed(): void {
		// Arrange.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$order = $this->reopen( $order );

		// Act.
		Fiscal_Record_Writers::instance()->recover( $order );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( Completion_History::of( $order )->entries()[0]['id'], $sales[0]['source_id'] );
	}

	/** A reopened completion whose record never landed is never rebuilt; completion 2 still names it. */
	public function test_a_reopened_completion_is_inserted_from_its_parked_document_and_completion_2_still_names_it(): void {
		// Arrange: completion 1's sale fails; reopened and edited to 80.00; completion 2's lands.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$this->pay( wc_get_order( $order->get_id() ) );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_History::of( $order )->entries();

		// Act: any caller asks for completion 1's record.
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[0] );

		// Assert: completion 1's parked document (100.00, as completed) lands after completion 2's record,
		// which named completion 1 without a record.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $history[1]['id'], $sales[0]['source_id'] );
		$this->assertNull( $sales[0]['corrects_record_id'] );
		$this->assertSame( $history[0]['id'], $sales[0]['payload']['fiscal']['supersedes']['completion_id'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['supersedes']['record_id'] );
		$this->assertSame( '-20.00', $sales[0]['payload']['fiscal']['adjustment'] );
		$this->assertSame( $history[0]['id'], $sales[1]['source_id'] );
		$this->assertEquals( 100, $sales[1]['payload']['totals']['total_incl'] );
		$this->assertSame( 1, $sales[1]['payload']['fiscal']['completion'] );
	}

	/** Each completion's cancellation is its own record (complete, cancel, reopen, complete, cancel). */
	public function test_cancel_after_each_completion_writes_its_own_cancellation(): void {
		// Arrange + Act.
		$order = $this->order();
		$this->pay( $order );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->pay( $this->reopen( $order ) );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		// Assert.
		$history       = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$sales         = $this->sales( $order->get_id() );
		$cancellations = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'cancellation',
				)
			)
		);
		$this->assertCount( 2, $history );
		$this->assertCount( 2, $cancellations );
		$this->assertSame( $history[0]['id'], $cancellations[0]['source_id'] );
		$this->assertSame( $sales[0]['id'], $cancellations[0]['corrects_record_id'] );
		$this->assertSame( $history[1]['id'], $cancellations[1]['source_id'] );
		$this->assertSame( $sales[1]['id'], $cancellations[1]['corrects_record_id'] );
	}

	/** An online order finished at the counter is a sale at that register. */
	public function test_an_online_order_completed_at_a_register_writes_a_sale(): void {
		// Arrange.
		$order = $this->order( false );

		// Act.
		Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100.00',
			)
		);

		// Assert.
		$this->assertFalse( wcpos_is_pos_order( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
	}

	/** A gateway that lands a paid status without payment_complete() still writes the sale. */
	public function test_a_status_only_gateway_completion_writes_a_sale(): void {
		// Arrange: the money is on the order as a captured row.
		$order = $this->order();
		Ledger_Seed::rows(
			$order,
			array(
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => '100.00',
					'status' => 'captured',
				),
			),
			false
		);

		// Act.
		wc_get_order( $order->get_id() )->update_status( 'processing' );
		Ledger::flush_all(); // The request ends: the completion the status hook deferred runs at shutdown.

		// Assert.
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
	}

	/** Payment completion freezes one numbered document without order-meta copies. */
	public function test_payment_complete_writes_sale_once_without_meta_or_i18n(): void {
		$order = $this->order();
		$this->pay( $order );
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
		do_action( 'wcpos_sale_completed', $order, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replays the completion event.
		$this->assertSame( array( $sale ), $store->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Building must succeed before a number is taken, without undoing payment. */
	public function test_failed_sale_build_consumes_no_number(): void {
		$order = $this->order();
		$before = get_option( 'wcpos_fiscal_sequence_sale' );
		$filter = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'wcpos_receipt_data', $filter );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_receipt_data', $filter );
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
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		add_action( 'wcpos_fiscal_record_failed', $observe );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
			remove_action( 'wcpos_fiscal_record_failed', $observe );
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
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich, 10, 2 );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
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
		add_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $enrich );
		}
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( '', $sale['payload']['fiscal']['qr_payload'] );
		$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
	}

	/** A storefront webhook arms the writer before completing a POS payment. */
	public function test_payment_complete_from_storefront_lane_writes_sale(): void {
		$writers = Fiscal_Record_Writers::instance();
		remove_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10 );
		$instance = new \ReflectionProperty( Fiscal_Record_Writers::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		Service_Groups::reset();
		Service_Groups::arm_order_group();
		try {
			// The webhook request has no money command in flight: the captured row is the register origin.
			$order = $this->order();
			Ledger_Seed::rows(
				$order,
				array(
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '100.00',
						'status' => 'captured',
					),
				),
				false
			);
			wc_get_order( $order->get_id() )->payment_complete();
			Ledger::flush_all(); // The webhook request ends: the completion its hooks deferred runs at shutdown.
			$this->assertIsArray( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		} finally {
			$instance->setValue( null, $writers );
			Service_Groups::reset();
		}
	}

	/** A storefront completion (no register origin) writes no sale, and its refund no record. */
	public function test_a_storefront_completion_writes_no_sale(): void {
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
		$this->pay( $order );
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
		// A sale anchor on an in-progress order (a reopened sale): Ledger refuses voids on completed orders.
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		Completion_Fixture::reopen( $order );
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
		unset( $row['commands'], $applied['commands'] ); // The command maps never enter a fiscal payload.
		$this->assertSame( $row, $records[0]['payload']['row'] );
		$this->assertSame( $applied, $records[0]['payload']['voided_row'] );
		$this->assertSame( 'Changed mind', $records[0]['payload']['reason'] );
		$this->assertSame( get_current_user_id(), $records[0]['payload']['actor_id'] );
		$this->assertNull( $records[0]['device_time'] );
		$this->assertNull( $records[0]['device_tz'] );
	}

	/** Pin 35: a void record's payload carries the rows without their command maps. */
	public function test_a_void_payload_carries_no_command_entries(): void {
		// Arrange: a cash row recorded and voided under commands, on a reopened sale.
		$order = $this->order();
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		Completion_Fixture::reopen( $order );
		$id  = wp_generate_uuid4();
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => $id,
				'method_id' => 'pos_cash',
				'amount' => '20.00',
			),
			array(),
			new Money_Command( $id, 'sha256:record', 'record' )
		);
		$this->assertArrayHasKey( $id, $row['commands'] );

		// Act.
		Ledger::instance()->void( $order, $id, 'Changed mind', new Money_Command( wp_generate_uuid4(), 'sha256:void', 'void' ) );

		// Assert.
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'void',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertArrayNotHasKey( 'commands', $records[0]['payload']['row'] );
		$this->assertArrayNotHasKey( 'commands', $records[0]['payload']['voided_row'] );
	}

	/** Pin 35: sale and late-sale payloads built from rows with command entries carry none. */
	public function test_sale_and_late_sale_payloads_carry_no_command_entries(): void {
		// Arrange: the closure fixture's rows each carry a command entry.
		$session = $this->closure_session();
		$fields  = $this->closure_fields( $session );
		$fields['counted'] = array(
			'cash' => '101',
			'card' => '30',
		);
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $fields );
		$order   = $this->closure_ledger( $session );
		$rows    = array_map(
			static function ( array $row ): array {
				$row['commands'] = array(
					wp_generate_uuid4() => array(
						'fp' => 'sha256:x',
						'kind' => 'capture',
						'ref' => null,
						'at_gmt' => gmdate( 'c' ),
					),
				);
				return $row;
			},
			Ledger::instance()->read( $order )
		);
		Ledger_Seed::rows( $order, $rows, false );
		global $wpdb;
		$this->seed_completion( $order );

		// Act.
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale  = $store->find_sale( $order->get_id() );
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => $closure['received_at_gmt'] ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order );

		// Assert.
		$late = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $late );
		$this->assertNotEmpty( $late[0]['payload']['tender_rows'] );
		foreach ( $late[0]['payload']['tender_rows'] as $tender ) {
			$this->assertArrayNotHasKey( 'commands', $tender );
		}
		$this->assertStringNotContainsString( '"commands"', (string) wp_json_encode( $store->find_sale( $order->get_id() )['payload'] ) );
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
			Ledger_Seed::rows( $order, array( $row ) );
			$before = did_action( 'wcpos_payment_voided' );
			$applied = Ledger::instance()->void( $order, $row['id'], 'Cancelled' );
			$this->assertSame( 'voided', $applied['status'] );
			$this->assertSame( $before + 1, did_action( 'wcpos_payment_voided' ) );
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
		$this->pay( $order );
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
		Completion_Fixture::sale( $order ); // A completed sale with no ledger rows: the admin refund allocates nothing.
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
		Ledger_Seed::rows( $order, $rows );
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
		$this->seed_completion( $order );
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
		Ledger_Seed::replace( $order, $rows, false );
		$writers = Fiscal_Record_Writers::instance();
		$this->seed_completion( $order );
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
		$this->seed_completion( $early );
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
		$this->seed_completion( $order );
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
		Ledger_Seed::replace( $order, $rows, false );
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
		Ledger_Seed::rows( $order, array( $rows[1] ), false ); // The counted leg keeps its closure stamp.
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
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
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		// The sale record's arrival time is the clock the old rule compared; push it past the close.
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $closure['received_at_gmt'] . ' UTC' ) + HOUR_IN_SECONDS ) ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order );
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

	/**
	 * A 10.00 card sale completed in a session, and its card row.
	 *
	 * @param array $session Session row.
	 * @return array{0: \WC_Order, 1: array}
	 */
	private function card_sale_in( array $session ): array {
		$order = $this->order();
		$order->set_total( '10.00' );
		$order->save();
		$row = Register_Sale::cash(
			$order,
			array(
				'method_id'   => 'pos_card',
				'amount'      => '10.00',
				'register_id' => $session['register_id'],
				'session_id'  => $session['id'],
			),
			array(
				'register_id' => $session['register_id'],
				'session_id'  => $session['id'],
			)
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		return array( wc_get_order( $order->get_id() ), $row );
	}

	/**
	 * Correct a row to cash and answer the original, R- and R+ as stored.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Row id.
	 * @return array{0: array, 1: array, 2: array}
	 */
	private function correct_to_cash( \WC_Order $order, string $id ): array {
		$plus = Ledger::instance()->correct( wc_get_order( $order->get_id() ), $id, 'pos_cash' );
		$this->assertIsArray( $plus );
		$rows  = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$minus = current( array_filter( $rows, static fn( $row ) => $id === ( $row['reverses'] ?? null ) ) );
		$this->assertIsArray( $minus );
		return array( (array) Ledger::instance()->find( wc_get_order( $order->get_id() ), $id ), $minus, $plus );
	}

	/**
	 * The order's late_sale records.
	 *
	 * @param int $order_id Order ID.
	 */
	private function late_sales( int $order_id ): array {
		return ( new Fiscal_Record_Store() )->list(
			array(
				'type'     => 'late_sale',
				'order_id' => $order_id,
			)
		);
	}

	/** A correction after its session's closure writes one late_sale that moves the money between tenders. */
	public function test_correction_in_closed_session_writes_one_tender_correction_late_sale(): void {
		// Arrange.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		$closure             = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$sales               = $this->sales( $order->get_id() );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );

		// Act.
		list( , $minus, $plus ) = $this->correct_to_cash( $order, $card['id'] );

		// Assert.
		$late = $this->late_sales( $order->get_id() );
		$this->assertCount( 1, $late );
		$this->assertSame( 'tender_correction', $late[0]['payload']['kind'] ?? null );
		$this->assertSame( array( $minus['id'], $plus['id'] ), array_column( $late[0]['payload']['tender_rows'], 'id' ) );
		$this->assertSame( '-10.0000', $late[0]['payload']['expected_delta']['card'] );
		$this->assertSame( '10.0000', $late[0]['payload']['expected_delta']['cash'] );
		$this->assertSame( $closure['id'], $late[0]['closure_id'] );
		$this->assertSame( $sales[0]['id'], $late[0]['corrects_record_id'] );
		$this->assertSame( Fiscal_Record_Store::digest_source_id( 'tender_correction:' . $plus['id'] ), $late[0]['source_id'] );
		$this->assertSame( $sales, $this->sales( $order->get_id() ) );
	}

	/** Running the correction writer again writes nothing twice. */
	public function test_correction_handled_twice_writes_one_late_sale(): void {
		// Arrange.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		list( $original, $minus, $plus ) = $this->correct_to_cash( $order, $card['id'] );
		$this->assertCount( 1, $this->late_sales( $order->get_id() ) );

		// Act.
		Fiscal_Record_Writers::instance()->handle_correction( wc_get_order( $order->get_id() ), $original, $minus, $plus );

		// Assert.
		$this->assertCount( 1, $this->late_sales( $order->get_id() ) );
	}

	/** A correction in a session still open is left to the close. */
	public function test_correction_in_open_session_writes_no_late_sale(): void {
		// Arrange.
		$session             = $this->closure_session( null, 'open' );
		list( $order, $card ) = $this->card_sale_in( $session );

		// Act.
		$this->correct_to_cash( $order, $card['id'] );

		// Assert.
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
	}

	/** The closure reads a correction's late_sale as a tender move: no sales, no refunds. */
	public function test_correction_late_sale_corrections_for_closure_nets_sales_and_refunds_to_zero(): void {
		// Arrange.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		$closure             = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$this->correct_to_cash( $order, $card['id'] );

		// Act.
		$corrections = array_values( array_filter( ( new Closure_Store() )->corrections_for( $closure['id'] ), static fn( $row ) => 'late_sale' === $row['type'] ) );

		// Assert.
		$this->assertCount( 1, $corrections );
		$figures = $corrections[0]['figures'];
		$this->assertSame( '-10.0000', $figures['expected_delta']['card'] );
		$this->assertSame( '10.0000', $figures['expected_delta']['cash'] );
		$this->assertSame( '0.0000', $figures['sales_delta'] );
		$this->assertSame( '0.0000', $figures['refunds_delta'] );
	}

	/** A correction a close's register lock turns away is retried, and the retry writes the record. */
	public function test_correction_while_register_is_held_schedules_retry_that_writes_the_late_sale(): void {
		// Arrange.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$name      = 'wcpos_register_' . strtolower( $session['register_id'] );
		$rewrite   = static fn( $sql ) => str_replace( "GET_LOCK( '" . $name . "', 10 )", "GET_LOCK( '" . $name . "', 0 )", $sql );
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$plus      = null;

		// Act: another connection holds the register; the writer's wait becomes none.
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			add_filter( 'query', $rewrite );
			try {
				list( , , $plus ) = $this->correct_to_cash( $order, $card['id'] );
			} finally {
				remove_filter( 'query', $rewrite );
			}
		} finally {
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$contender->close();
		}

		// Assert: nothing written, the retry scheduled.
		$retry = array( $order->get_id(), $plus['id'], 2 );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
		$this->assertNotFalse( wp_next_scheduled( 'wcpos_fiscal_correction_retry', $retry ) );

		// Act: the retry runs once the register is free.
		Fiscal_Record_Writers::instance()->retry_correction( ...$retry );

		// Assert.
		$late = $this->late_sales( $order->get_id() );
		$this->assertCount( 1, $late );
		$this->assertSame( 'tender_correction', $late[0]['payload']['kind'] ?? null );
		wp_clear_scheduled_hook( 'wcpos_fiscal_correction_retry', $retry );
	}

	/**
	 * Run $work while another connection holds the session's register; the writer's wait becomes none.
	 *
	 * @param array    $session Session row.
	 * @param callable $work    Work.
	 */
	private function with_register_held( array $session, callable $work ): void {
		$name      = 'wcpos_register_' . strtolower( $session['register_id'] );
		$rewrite   = static fn( $sql ) => str_replace( "GET_LOCK( '" . $name . "', 10 )", "GET_LOCK( '" . $name . "', 0 )", $sql );
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			add_filter( 'query', $rewrite );
			$work();
		} finally {
			remove_filter( 'query', $rewrite );
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$contender->close();
		}
	}

	/**
	 * A card sale in a closed session, corrected to cash while its register is held; its first cron retry cleared.
	 *
	 * @param bool $sale_insert_fails Whether the sale's own record insert fails too, so its document is parked.
	 * @return array{0: \WC_Order, 1: array, 2: array} The order, R+ and the session.
	 */
	private function correction_turned_away( bool $sale_insert_fails = false ): array {
		$session = $this->closure_session();
		$order   = null;
		$card    = null;
		$sale    = function () use ( $session, &$order, &$card ): void {
			list( $order, $card ) = $this->card_sale_in( $session );
		};
		$sale_insert_fails ? $this->with_sale_inserts_failing( $sale ) : $sale();
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$plus = null;
		$this->with_register_held(
			$session,
			function () use ( $order, $card, &$plus ): void {
				list( , , $plus ) = $this->correct_to_cash( $order, $card['id'] );
			}
		);
		wp_clear_scheduled_hook( 'wcpos_fiscal_correction_retry', array( $order->get_id(), $plus['id'], 2 ) );
		return array( wc_get_order( $order->get_id() ), $plus, $session );
	}

	/**
	 * The tender correction's parked entry on the order, or null.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $plus     R+.
	 */
	private function parked_correction( int $order_id, array $plus ): ?array {
		return $this->parked( $order_id )[ 'tender_correction:' . strtolower( $plus['id'] ) ] ?? null;
	}

	/**
	 * The order's late_sale records for one tender correction.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $plus     R+.
	 */
	private function correction_late_sales( int $order_id, array $plus ): array {
		$source = Fiscal_Record_Store::digest_source_id( 'tender_correction:' . $plus['id'] );
		return array_values( array_filter( $this->late_sales( $order_id ), static fn( $record ) => $source === $record['source_id'] ) );
	}

	/** A correction the register lock turns away parks its entry for the sweep (#2686 Q4). */
	public function test_correction_turned_away_by_a_held_register_parks_its_entry(): void {
		// Arrange and act: the correction lands while a close holds the register.
		list( $order, $plus, $session ) = $this->correction_turned_away();

		// Assert.
		$expected = array(
			'kind'       => 'tender_correction',
			'plus_id'    => $plus['id'],
			'session_id' => strtolower( $session['id'] ),
		);
		$this->assertSame( $expected, $this->parked_correction( $order->get_id(), $plus ) );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
	}

	/** The last retry of a turned-away correction leaves a stamped entry, one note and one failure signal. */
	public function test_correction_final_retry_with_the_register_held_leaves_a_findable_marker(): void {
		// Arrange.
		list( $order, $plus, $session ) = $this->correction_turned_away();
		$max     = ( new \ReflectionClassConstant( Fiscal_Record_Writers::class, 'LATE_RETRY_MAX_ATTEMPTS' ) )->getValue();
		$failed  = array();
		$observe = static function ( $type, $failed_order, $context ) use ( &$failed ): void {
			$failed[] = array( $type, $failed_order->get_id(), $context );
		};
		add_action( 'wcpos_fiscal_record_failed', $observe, 10, 3 );

		// Act.
		try {
			$this->with_register_held(
				$session,
				static function () use ( $order, $plus, $max ): void {
					Fiscal_Record_Writers::instance()->retry_correction( $order->get_id(), $plus['id'], $max );
				}
			);
		} finally {
			remove_action( 'wcpos_fiscal_record_failed', $observe, 10 );
		}

		// Assert.
		$this->assertArrayHasKey( 'attempted_at', (array) $this->parked_correction( $order->get_id(), $plus ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertCount( 1, array_filter( $notes, static fn( $note ) => false !== strpos( $note, 'tender correction record could not be written' ) ) );
		$source = Fiscal_Record_Store::digest_source_id( 'tender_correction:' . $plus['id'] );
		$this->assertSame( array( array( 'late_sale', $order->get_id(), array( 'source_id' => $source ) ) ), $failed );
		$this->assertFalse( wp_next_scheduled( 'wcpos_fiscal_correction_retry', array( $order->get_id(), $plus['id'], $max + 1 ) ) );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
	}

	/** The last retry's refused insert leaves a stamped entry and only write()'s own note and failure signal. */
	public function test_correction_final_retry_with_the_insert_refused_notes_once_and_stays_parked(): void {
		// Arrange.
		list( $order, $plus ) = $this->correction_turned_away();
		$max                  = ( new \ReflectionClassConstant( Fiscal_Record_Writers::class, 'LATE_RETRY_MAX_ATTEMPTS' ) )->getValue();
		$stuck                = static fn( $value, $old ) => $old;
		$failed               = array();
		$observe              = static function ( $type, $failed_order, $fields ) use ( &$failed ): void {
			$failed[] = array( $type, $failed_order->get_id(), $fields['source_id'] ?? null, $fields['payload']['kind'] ?? null );
		};
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10, 2 );
		add_action( 'wcpos_fiscal_record_failed', $observe, 10, 3 );

		// Act.
		try {
			Fiscal_Record_Writers::instance()->retry_correction( $order->get_id(), $plus['id'], $max );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10 );
			remove_action( 'wcpos_fiscal_record_failed', $observe, 10 );
		}

		// Assert.
		$notes  = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$source = Fiscal_Record_Store::digest_source_id( 'tender_correction:' . $plus['id'] );
		$this->assertCount( 1, array_filter( $notes, static fn( $note ) => false !== strpos( $note, 'could not be written' ) ) );
		$this->assertSame( array( array( 'late_sale', $order->get_id(), $source, 'tender_correction' ) ), $failed );
		$this->assertArrayHasKey( 'attempted_at', (array) $this->parked_correction( $order->get_id(), $plus ) );
		$this->assertFalse( wp_next_scheduled( 'wcpos_fiscal_correction_retry', array( $order->get_id(), $plus['id'], $max + 1 ) ) );
	}

	/** A last retry after the sweep already wrote the correction's late_sale adds no note and signals no failure. */
	public function test_correction_final_retry_after_the_sweep_wrote_it_adds_no_note_and_no_signal(): void {
		// Arrange: the sweep writes the turned-away correction.
		list( $order, $plus, $session ) = $this->correction_turned_away();
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $plus ) );
		$max     = ( new \ReflectionClassConstant( Fiscal_Record_Writers::class, 'LATE_RETRY_MAX_ATTEMPTS' ) )->getValue();
		$signals = 0;
		$observe = static function () use ( &$signals ): void {
			++$signals;
		};
		add_action( 'wcpos_fiscal_record_failed', $observe );

		// Act: the last cron retry runs while a close holds the register.
		try {
			$this->with_register_held(
				$session,
				static function () use ( $order, $plus, $max ): void {
					Fiscal_Record_Writers::instance()->retry_correction( $order->get_id(), $plus['id'], $max );
				}
			);
		} finally {
			remove_action( 'wcpos_fiscal_record_failed', $observe );
		}

		// Assert.
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertSame( array(), array_values( array_filter( $notes, static fn( $note ) => false !== strpos( $note, 'could not be written' ) ) ) );
		$this->assertSame( 0, $signals );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $plus ) );
	}

	/** The sweep writes a parked correction's late_sale once and drops the entry. */
	public function test_correction_sweep_writes_a_parked_correction_once_and_unparks_it(): void {
		// Arrange.
		list( $order, $plus ) = $this->correction_turned_away();

		// Act: the sweep's candidates, then its retry of the order.
		$owed = Fiscal_Record_Writers::orders_owed_a_sale( 100, 0 );
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertContains( $order->get_id(), $owed );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $plus ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertNotContains( Fiscal_Record_Writers::PENDING_META_KEY, wp_list_pluck( $fresh->get_data_store()->read_meta( $fresh ), 'meta_key' ) );
	}

	/** A closed-session correction whose late_sale never ran, with nothing parked, is written once by the sweep. */
	public function test_correction_sweep_without_a_park_writes_the_missing_late_sale_once(): void {
		// Arrange: the writer is not listening. The sweep is called directly here; a correction that was never
		// parked is reached only when the sweep visits the order for another reason.
		$session              = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$writers = Fiscal_Record_Writers::instance();
		remove_action( 'wcpos_payment_corrected', array( $writers, 'handle_correction' ), 10 );
		try {
			list( , , $plus ) = $this->correct_to_cash( $order, $card['id'] );
		} finally {
			add_action( 'wcpos_payment_corrected', array( $writers, 'handle_correction' ), 10, 4 );
		}
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );

		// Act: two sweeps.
		$writers->retry( wc_get_order( $order->get_id() ) );
		$first = count( $this->correction_late_sales( $order->get_id(), $plus ) );
		$writers->retry( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( 1, $first );
		$this->assertCount( 1, $this->late_sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** The sweep writes nothing for a correction in a session still open, and parks nothing. */
	public function test_correction_sweep_in_an_open_session_writes_nothing_and_parks_nothing(): void {
		// Arrange.
		$session              = $this->closure_session( null, 'open' );
		list( $order, $card ) = $this->card_sale_in( $session );
		$this->correct_to_cash( $order, $card['id'] );

		// Act.
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A parked sale document beside a parked correction: the sweep inserts the sale, carries each leg once and empties the map. */
	public function test_correction_sweep_beside_a_parked_sale_document_inserts_the_sale_carries_each_leg_once_and_empties_the_map(): void {
		// Arrange.
		list( $order, $plus ) = $this->correction_turned_away( true );
		$this->assertCount( 2, $this->parked( $order->get_id() ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$minus = current( array_filter( Ledger::instance()->read( $order ), static fn( $row ) => $plus['corrects'] === ( $row['reverses'] ?? null ) ) );

		// Act.
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );

		// Assert: across every late_sale on the order, R- and R+ are each carried exactly once.
		$legs = array();
		foreach ( $this->late_sales( $order->get_id() ) as $record ) {
			$legs = array_merge( $legs, array_column( (array) ( $record['payload']['tender_rows'] ?? array() ), 'id' ) );
		}
		$carried = array_count_values( $legs );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertSame( 1, $carried[ $minus['id'] ] ?? 0 );
		$this->assertSame( 1, $carried[ $plus['id'] ] ?? 0 );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A sweep whose late_sale insert fails keeps the correction parked, quietly; the next sweep writes it once. */
	public function test_correction_sweep_when_the_late_sale_insert_fails_keeps_the_entry_parked(): void {
		// Arrange.
		list( $order, $plus ) = $this->correction_turned_away();
		$writers              = Fiscal_Record_Writers::instance();
		$stuck                = static fn( $value, $old ) => $old;
		$failed_notes         = fn() => count( array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( $note ) => false !== strpos( $note, 'could not be written' ) ) );
		$notes_before         = $failed_notes();
		$signals              = 0;
		$observe              = static function () use ( &$signals ): void {
			++$signals;
		};

		// Act: two sweeps while the late_sale sequence cannot advance.
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10, 2 );
		add_action( 'wcpos_fiscal_record_failed', $observe );
		try {
			$writers->retry( wc_get_order( $order->get_id() ) );
			$writers->retry( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10 );
			remove_action( 'wcpos_fiscal_record_failed', $observe );
		}
		$notes_after = $failed_notes();
		$parked = $this->parked_correction( $order->get_id(), $plus );
		$failed = $this->late_sales( $order->get_id() );
		$writers->retry( wc_get_order( $order->get_id() ) );

		// Assert: the failing sweeps neither noted the order nor signalled a failure.
		$this->assertSame( $notes_before, $notes_after );
		$this->assertSame( 0, $signals );
		$this->assertArrayHasKey( 'attempted_at', (array) $parked );
		$this->assertSame( array(), $failed );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $plus ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A correction whose sweep write throws stays parked and does not starve the order's next correction. */
	public function test_correction_sweep_when_one_correction_throws_still_writes_the_next(): void {
		// Arrange: two corrections on one order, both turned away by a held register.
		list( $order, $first, $session ) = $this->correction_turned_away();
		$second                          = null;
		$this->with_register_held(
			$session,
			function () use ( $order, $first, &$second ): void {
				$second = Ledger::instance()->correct( wc_get_order( $order->get_id() ), $first['id'], 'pos_card' );
			}
		);
		$this->assertIsArray( $second );
		wp_clear_scheduled_hook( 'wcpos_fiscal_correction_retry', array( $order->get_id(), $second['id'], 2 ) );
		$this->assertNotNull( $this->parked_correction( $order->get_id(), $second ) );
		$writers = Fiscal_Record_Writers::instance();
		$thrown  = false;
		$once    = static function ( $value ) use ( &$thrown ) {
			if ( ! $thrown ) {
				$thrown = true;
				throw new \LogicException( 'The first late_sale insert throws.' );
			}
			return $value;
		};

		// Act: one sweep whose first late_sale insert throws, then a clean one.
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $once );
		try {
			$writers->retry( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $once );
		}
		$written = array( count( $this->correction_late_sales( $order->get_id(), $first ) ), count( $this->correction_late_sales( $order->get_id(), $second ) ) );
		$stamped = $this->parked_correction( $order->get_id(), $written[0] ? $second : $first );
		$writers->retry( wc_get_order( $order->get_id() ) );

		// Assert: the first sweep wrote one and kept the other parked; the second wrote the rest.
		$this->assertTrue( $thrown );
		$this->assertSame( 1, array_sum( $written ) );
		$this->assertArrayHasKey( 'attempted_at', (array) $stamped );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $first ) );
		$this->assertCount( 1, $this->correction_late_sales( $order->get_id(), $second ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** The sweep drops a parked entry whose correction is no longer in the ledger, and writes nothing. */
	public function test_correction_sweep_unparks_an_entry_whose_correction_is_gone(): void {
		// Arrange: an entry in park_correction()'s shape for an R+ the ledger never held.
		$order   = $this->order();
		$plus_id = wp_generate_uuid4();
		$entry   = array(
			'kind'       => 'tender_correction',
			'plus_id'    => $plus_id,
			'session_id' => strtolower( (string) $order->get_meta( '_wcpos_session', true ) ),
		);
		$order->update_meta_data( Fiscal_Record_Writers::PENDING_META_KEY, wp_json_encode( array( 'tender_correction:' . $plus_id => $entry ) ) );
		$order->save();
		$this->assertCount( 1, $this->parked( $order->get_id() ) );

		// Act.
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
	}

	/** A deferred correction whose legs a later completion's late_sale carried writes nothing on its retry. */
	public function test_correction_legs_carried_by_a_later_completion_late_sale_are_not_written_again(): void {
		// Arrange: the correction's late check is deferred by a held register.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$name      = 'wcpos_register_' . strtolower( $session['register_id'] );
		$rewrite   = static fn( $sql ) => str_replace( "GET_LOCK( '" . $name . "', 10 )", "GET_LOCK( '" . $name . "', 0 )", $sql );
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$minus     = null;
		$plus      = null;
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			add_filter( 'query', $rewrite );
			try {
				list( , $minus, $plus ) = $this->correct_to_cash( $order, $card['id'] );
			} finally {
				remove_filter( 'query', $rewrite );
			}
		} finally {
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$contender->close();
		}
		$retry = array( $order->get_id(), $plus['id'], 2 );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
		$this->assertNotFalse( wp_next_scheduled( 'wcpos_fiscal_correction_retry', $retry ) );

		// The order is reopened and completed again in the same, closed session: its late_sale carries both legs.
		Register_Sale::complete(
			$this->reopen( $order ),
			array(
				'register_id' => $session['register_id'],
				'session_id'  => $session['id'],
			)
		);
		$late = $this->late_sales( $order->get_id() );
		$this->assertCount( 1, $late );
		$this->assertNotSame( 'tender_correction', $late[0]['payload']['kind'] ?? null );
		$this->assertEqualsCanonicalizing( array( $minus['id'], $plus['id'] ), array_column( $late[0]['payload']['tender_rows'], 'id' ) );

		// Act.
		Fiscal_Record_Writers::instance()->retry_correction( ...$retry );

		// Assert: no tender_correction late_sale, and the move is counted once.
		$late = $this->late_sales( $order->get_id() );
		$this->assertSame( array(), array_filter( $late, static fn( $record ) => 'tender_correction' === ( $record['payload']['kind'] ?? null ) ) );
		$totals = array(
			'card' => 0.0,
			'cash' => 0.0,
		);
		foreach ( $late as $record ) {
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += (float) ( $record['payload']['expected_delta'][ $key ] ?? 0 );
			}
		}
		$this->assertSame( '-10.0000', sprintf( '%.4f', $totals['card'] ) );
		$this->assertSame( '10.0000', sprintf( '%.4f', $totals['cash'] ) );
		wp_clear_scheduled_hook( 'wcpos_fiscal_correction_retry', $retry );
	}

	/** A correction whose late_sale insert fails is retried, and the retry writes the record. */
	public function test_correction_insert_failure_schedules_retry_that_writes_the_late_sale(): void {
		// Arrange.
		$session             = $this->closure_session();
		list( $order, $card ) = $this->card_sale_in( $session );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$stuck = static fn( $value, $old ) => $old;
		$plus  = null;

		// Act: the late_sale sequence cannot advance, so the insert fails.
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10, 2 );
		try {
			list( , , $plus ) = $this->correct_to_cash( $order, $card['id'] );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_late_sale', $stuck, 10 );
		}

		// Assert: nothing written, the retry scheduled.
		$retry = array( $order->get_id(), $plus['id'], 2 );
		$this->assertSame( array(), $this->late_sales( $order->get_id() ) );
		$this->assertNotFalse( wp_next_scheduled( 'wcpos_fiscal_correction_retry', $retry ) );

		// Act: the retry runs once inserts work again.
		Fiscal_Record_Writers::instance()->retry_correction( ...$retry );

		// Assert.
		$late = $this->late_sales( $order->get_id() );
		$this->assertCount( 1, $late );
		$this->assertSame( 'tender_correction', $late[0]['payload']['kind'] ?? null );
		wp_clear_scheduled_hook( 'wcpos_fiscal_correction_retry', $retry );
	}

	/** A returned line in a net-positive sale keeps its signed quantity and unit price in the sale snapshot. */
	public function test_sale_record_snapshot_keeps_returned_line_signed_quantity(): void {
		// Arrange: 2 sold at 10.00 and 1 returned at 10.00, order total 10.00.
		$order = $this->order();
		$sold = new \WC_Order_Item_Product();
		$sold->set_name( 'Sold item' );
		$sold->set_quantity( 2 );
		$sold->set_subtotal( 20 );
		$sold->set_total( 20 );
		$order->add_item( $sold );
		$returned = new \WC_Order_Item_Product();
		$returned->set_name( 'Returned item' );
		$returned->set_quantity( -1 );
		$returned->set_subtotal( -10 );
		$returned->set_total( -10 );
		$order->add_item( $returned );
		$order->calculate_totals( false );
		$order->save();

		// Act.
		$this->pay( $order );

		// Assert.
		$lines = $this->sales( $order->get_id() )[0]['payload']['lines'];
		$this->assertEqualsWithDelta( 2.0, $lines[0]['qty'], 0.001 );
		$this->assertEqualsWithDelta( -1.0, $lines[1]['qty'], 0.001 );
		$this->assertEqualsWithDelta( 10.0, $lines[1]['unit_price_incl'], 0.001 );
	}
}
