<?php
/**
 * Session figure contracts.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Shared tender rules and instance-local source reads. */
class Test_Session_Figures extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/**
	 * A snapshot digest must not be enumerable without this site's secret.
	 *
	 * @testWith ["ledger_rows", "version"]
	 *           ["refund_versions", "allocation_version"]
	 */
	public function test_snapshot_versions_differ_between_site_salts( string $method, string $key ): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '3' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$salt = 'first-site-secret';
		$filter = static function () use ( &$salt ) {
			return $salt;
		};
		add_filter( 'salt', $filter );
		try {
			$before = Session_Figures::for_session( $session )->$method();
			$this->assertSame( $before, Session_Figures::for_session( $session )->$method() );
			$salt = 'second-site-secret';
			$after = Session_Figures::for_session( $session )->$method();
			$this->assertNotSame( $before[0][ $key ], $after[0][ $key ] );
			unset( $before[0][ $key ], $after[0][ $key ] );
			$this->assertSame( $before[0], $after[0] );
		} finally {
			remove_filter( 'salt', $filter );
		}
	}

	/**
	 * Same-second financial edits must change the opaque snapshot version.
	 *
	 * @testWith [0, "amount", "55.0000"]
	 *           [1, "method", "voucher"]
	 *           [0, "refunded_amount", "15.0000"]
	 */
	public function test_ledger_rows_same_second_financial_change_changes_version( int $index, string $field, string $value ): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$before = Session_Figures::for_session( $session )->ledger_rows();
		$rows = Ledger::instance()->read( $order );

		// Act. Preserve the timestamp and status while changing a financial input.
		$rows[ $index ][ $field ] = $value;
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
		$after = Session_Figures::for_session( $session )->ledger_rows();

		// Assert.
		$this->assertSame( $before[ $index ]['updated_at_gmt'], $after[ $index ]['updated_at_gmt'] );
		$this->assertSame( 'captured', $after[ $index ]['status'] );
		$this->assertNotSame( $before[ $index ], $after[ $index ] );
		$this->assertArrayHasKey( 'version', $before[ $index ] );
		$this->assertNotSame( $before[ $index ]['version'], $after[ $index ]['version'] );
	}

	/** Legacy rows need not carry a timestamp; only captured rows enter the snapshot. */
	public function test_ledger_rows_missing_version_returns_null_without_warning(): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$rows = Ledger::instance()->read( $order );
		unset( $rows[0]['updated_at_gmt'], $rows[1]['status'] );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();

		// Act. PHPUnit converts warnings to exceptions.
		$versions = Session_Figures::for_session( $session )->ledger_rows();

		// Assert.
		$this->assertNotEmpty( $versions[0]['version'] );
		unset( $versions[0]['version'] );
		$this->assertSame(
			array(
				array(
					'id' => $rows[0]['id'],
					'updated_at_gmt' => null,
					'status' => 'captured',
				),
			),
			$versions
		);
	}

	/** All cash-kind gateways map to cash; method takes precedence over method_id. */
	public function test_tender_key_maps_every_cash_kind_to_the_drawer(): void {
		foreach ( array( 'pos_cash', 'cod', 'extension_cash' ) as $method ) {
			$this->assertSame(
				'cash',
				Session_Figures::tender_key(
					array(
						'method_id' => $method,
						'kind' => 'cash',
					)
				)
			);
		}
		$this->assertSame( 'card', Session_Figures::tender_key( array( 'method_id' => 'pos_card' ) ) );
		$this->assertSame( 'voucher', Session_Figures::tender_key( array( 'method_id' => 'voucher' ) ) );
		$this->assertSame(
			'voucher',
			Session_Figures::tender_key(
				array(
					'method' => 'voucher',
					'method_id' => 'pos_card',
				)
			)
		);
	}

	/** Raw legacy refund forms count by either kind or sign, not refunded_amount. */
	public function test_is_refund_tender_by_kind_or_sign(): void {
		$this->assertTrue( Session_Figures::is_refund_tender( array( 'amount' => '-5' ) ) );
		$this->assertTrue(
			Session_Figures::is_refund_tender(
				array(
					'amount' => '7',
					'kind' => 'refund',
				)
			)
		);
		$this->assertFalse(
			Session_Figures::is_refund_tender(
				array(
					'amount' => '5',
					'refunded_amount' => '5',
				)
			)
		);
	}

	/** Cash is seeded even when absent; remaining keys follow first appearance. */
	public function test_tender_totals_seeds_cash_first_and_keeps_appearance_order(): void {
		$this->assertSame( array( 'cash' => '0.0000' ), Session_Figures::tender_totals( array() ) );
		$rows = array();
		foreach ( array( array( 'voucher', '1.0001' ), array( 'pos_card', '2' ), array( 'voucher', '-0.0002' ) ) as list( $method, $amount ) ) {
			$rows[] = array(
				'method_id' => $method,
				'amount' => $amount,
			);
		}
		$this->assertSame(
			array(
				'cash' => '0.0000',
				'voucher' => '0.9999',
				'card' => '2.0000',
			),
			Session_Figures::tender_totals( $rows )
		);
	}

	/** Repeated figures use one lazy ledger read and one lazy movement read. */
	public function test_expected_reads_each_source_once_however_often_asked(): void {
		$session = $this->closure_session();
		$this->closure_ledger( $session );
		$reads = array(
			'orders' => 0,
			'movements' => 0,
		);
		$observe = static function ( $sql ) use ( &$reads ) {
			if ( false !== strpos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, Ledger::META_KEY ) ) {
				++$reads['orders'];
			}
			if ( 0 === strpos( $sql, 'SELECT movements FROM ' . ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->table_name() ) ) {
				++$reads['movements'];
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$figures = Session_Figures::for_session( $session );
			$this->assertSame( 0, $reads['orders'] );
			$this->assertSame( 0, $reads['movements'] );
			for ( $i = 0; $i < 2; ++$i ) {
				$this->assertSame(
					array(
						'cash' => '150.0000',
						'card' => '30.0000',
					),
					$figures->expected()
				);
				$this->assertSame( 1, $figures->sales_count() );
				$this->assertSame( 0, $figures->refund_count() );
				$this->assertSame( array(), $figures->movements() );
			}
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame(
			array(
				'orders' => 1,
				'movements' => 1,
			),
			$reads
		);
	}

	/**
	 * A POS order completed with cash recorded in the session, under that session's provenance.
	 *
	 * @param array $session Session row.
	 */
	private function completed_in( array $session ): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '40.00' );
		$order->save();
		Completion_Stamp::with_provenance(
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			),
			static function () use ( $order, $session ): void {
				Ledger::instance()->record(
					$order,
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '40.00',
						'session_id' => $session['id'],
					)
				);
			},
			$order->get_id()
		);
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Reopen the order and close it unchanged from a session.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session the re-completion happens in.
	 */
	private function recomplete_in( \WC_Order $order, array $session ): void {
		$order->set_status( 'pos-open' );
		$order->save();
		Completion_Stamp::with_provenance(
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			),
			static function () use ( $order ): void {
				wc_get_order( $order->get_id() )->payment_complete();
			},
			$order->get_id()
		);
	}

	/** A sale reopened and completed again unchanged in one session is two documents there. */
	public function test_sales_count_counts_a_zero_adjustment_recompletion_in_the_same_session_twice(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order   = $this->completed_in( $session );

		// Act.
		$this->recomplete_in( $order, $session );

		// Assert.
		$this->assertCount( 2, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->assertSame( 2, Session_Figures::for_session( $session )->sales_count() );
	}

	/** A re-completion in a later session without a new tender counts there once. */
	public function test_a_recompletion_in_a_new_session_counts_once_there(): void {
		// Arrange.
		$first  = $this->closure_session( null, 'open' );
		$second = $this->closure_session( null, 'open' );
		$order  = $this->completed_in( $first );

		// Act.
		$this->recomplete_in( $order, $second );

		// Assert.
		$this->assertSame( 1, Session_Figures::for_session( $first )->sales_count() );
		$this->assertSame( 1, Session_Figures::for_session( $second )->sales_count() );
	}

	/** An order completed before the history existed still counts once in its session. */
	public function test_a_pre_deploy_sale_without_history_still_counts_one(): void {
		// Arrange: captured session legs, no completion history.
		$session = $this->closure_session();
		$order   = $this->closure_ledger( $session );

		// Act.
		$count = Session_Figures::for_session( $session )->sales_count();

		// Assert.
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->assertSame( 1, $count );
	}

	/** A legacy sale row without a session does not blank the session the till tuple carries when the history is synthesised. */
	public function test_a_reopened_legacy_sale_with_a_sessionless_record_still_counts_one(): void {
		global $wpdb;
		// Arrange: captured legs in session A, paid before the history existed, a sale row with no session.
		$session = $this->closure_session();
		$order   = $this->closure_ledger( $session );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5 );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_completed' ), 15 );
		try {
			$paid = wc_get_order( $order->get_id() );
			$paid->set_date_paid( time() );
			$paid->set_status( 'completed' );
			$paid->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5, 4 );
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_completed' ), 15, 4 );
		}
		$store = new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store();
		$store->ensure_installed();
		$wpdb->insert(
			$store->table_name(),
			array(
				'type' => 'sale',
				'series' => '',
				'number' => 700,
				'order_id' => $order->get_id(),
				'payload' => '{}',
				'checksum' => str_repeat( 'a', 64 ),
				'received_at_gmt' => '2026-09-11 10:00:00',
			)
		);

		// Act: reopened, which synthesises the legacy entry.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();

		// Assert.
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( $session['id'], $history[0]['session'] );
		$this->assertSame( 1, Session_Figures::for_session( $session )->sales_count() );
	}

	/** Period totals separate sale legs, both raw refund forms, and bound refunds. */
	public function test_period_amounts_split_legs_and_refund_rows(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = $ledger->read( $order );
		foreach ( array( array( '-5', 'cash' ), array( '7', 'refund' ) ) as list( $amount, $kind ) ) {
			$rows[] = array_merge(
				$rows[0],
				array(
					'id' => wp_generate_uuid4(),
					'amount' => $amount,
					'kind' => $kind,
				)
			);
		}
		$ledger->save( $order, $rows, false );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '3' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$figures = Session_Figures::for_session( $session );
		$this->assertSame(
			array(
				'sales' => array( '50.00', '30.00' ),
				'refunds' => array( '5.00', '7.00', '3.00' ),
			),
			$figures->period_amounts()
		);
		$this->assertSame( 3, $figures->refund_count() );
	}
	/** Only a nonempty string matching this closure is counted. */
	public function test_counted_requires_this_closures_id(): void {
		$closure = array( 'id' => wp_generate_uuid4() );
		foreach ( array( null, '', wp_generate_uuid4() ) as $stamp ) {
			$this->assertFalse( Session_Figures::counted( $stamp, $closure ) );
		}
		$this->assertTrue( Session_Figures::counted( $closure['id'], $closure ) );
	}

	/** Late legs are captured, session-bound and not stamped by this closure. */
	public function test_late_legs_are_captured_session_legs_without_this_closures_stamp(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = $ledger->read( $order );
		$stale = clone $order;
		$closure = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
		);
		$ledger->stamp_closure( $order, array( $rows[0]['id'] ), $closure['id'] );
		$this->assertSame( array( $rows[1]['id'] ), array_column( Session_Figures::late_legs( $stale, $closure ), 'id' ) );
		$ledger->stamp_closure( $order, array( $rows[1]['id'] ), wp_generate_uuid4() );
		$this->assertSame( array( $rows[1]['id'] ), array_column( Session_Figures::late_legs( $stale, $closure ), 'id' ) );
	}

	/**
	 * A POS order whose ledger holds these rows, saved through the Ledger.
	 *
	 * @param array $rows Payment rows; each gets a fresh id.
	 */
	private function order_with_legs( array $rows ): \WC_Order {
		$order = new \WC_Order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '100' );
		$order->save();
		$base = array(
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'amount' => '10',
			'status' => 'captured',
		);
		Ledger::instance()->save( $order, array_map( static fn( $fields ) => array_merge( $base, $fields, array( 'id' => wp_generate_uuid4() ) ), $rows ), false );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * The session index values stored on an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function indexed_sessions( \WC_Order $order ): array {
		$values = array_map( static fn( $meta ) => $meta->value, wc_get_order( $order->get_id() )->get_meta( Ledger::SESSION_META_KEY, false ) );
		sort( $values );
		return $values;
	}

	/** A captured leg indexes its session, and the session read finds it there. */
	public function test_legs_captured_leg_is_found_through_the_session_index(): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->order_with_legs( array( array( 'session_id' => $session['id'] ) ) );

		// Act.
		$legs = Session_Figures::for_session( $session )->legs();

		// Assert.
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $order ) );
		$this->assertSame( array( $order->get_id() ), array_keys( $legs ) );
		$this->assertSame( array_column( Ledger::instance()->read( $order ), 'id' ), array_column( $legs[ $order->get_id() ], 'id' ) );
	}

	/** A partly paid order, not completed and never stamped with the session, is still a session's leg. */
	public function test_legs_partly_paid_order_without_a_completion_stamp_is_found(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '40.00' );
		$order->save();

		// Act.
		$recorded = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '15.00',
				'session_id' => $session['id'],
			)
		);
		$saved = wc_get_order( $order->get_id() );
		$legs = Session_Figures::for_session( $session )->legs();

		// Assert.
		$this->assertIsArray( $recorded, is_wp_error( $recorded ) ? $recorded->get_error_message() : '' );
		$this->assertSame( 'captured', $recorded['status'] );
		$this->assertFalse( $saved->is_paid() );
		$this->assertSame( '', $saved->get_meta( '_wcpos_session', true ) );
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $saved ) );
		$this->assertCount( 1, $legs[ $order->get_id() ] ?? array() );
	}

	/** An order paid again in a second session carries both sessions and is a leg in each. */
	public function test_legs_order_with_captured_legs_in_two_sessions_appears_in_both(): void {
		// Arrange.
		$first = $this->closure_session();
		$second = $this->closure_session();
		$expected = array( $first['id'], $second['id'] );
		sort( $expected );

		// Act.
		$order = $this->order_with_legs( array( array( 'session_id' => $first['id'] ), array( 'session_id' => $second['id'] ) ) );

		// Assert.
		$this->assertSame( $expected, $this->indexed_sessions( $order ) );
		foreach ( array( $first, $second ) as $session ) {
			$legs = Session_Figures::for_session( $session )->legs();
			$this->assertSame( array( $session['id'] ), array_column( $legs[ $order->get_id() ] ?? array(), 'session_id' ) );
		}
	}

	/**
	 * Money in motion is not a session leg until it is captured.
	 *
	 * @param string $status The uncaptured status the row starts in.
	 *
	 * @testWith ["pending"]
	 *           ["authorized"]
	 */
	public function test_legs_uncaptured_row_is_not_indexed_until_captured( string $status ): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->order_with_legs(
			array(
				array(
					'session_id' => $session['id'],
					'status' => $status,
				),
			)
		);
		$this->assertSame( array(), $this->indexed_sessions( $order ) );
		$this->assertSame( array(), Session_Figures::for_session( $session )->legs() );
		$rows = Ledger::instance()->read( $order );
		$rows[0]['status'] = 'captured';

		// Act.
		Ledger::instance()->save( $order, $rows, false );

		// Assert.
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $order ) );
		$this->assertSame( array( $rows[0]['id'] ), array_column( Session_Figures::for_session( $session )->legs()[ $order->get_id() ] ?? array(), 'id' ) );
	}
}
