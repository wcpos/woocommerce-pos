<?php
/**
 * Session figure contracts.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Shared tender rules and instance-local source reads. */
class Test_Session_Figures extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

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
			if ( 0 === strpos( $sql, 'SELECT * FROM' ) && false !== strpos( $sql, 'wcpos_cash_movements' ) ) {
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
}
