<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\{Money_Epoch, Ledger};

class Test_Money_Epoch extends \WP_UnitTestCase {
	/** @dataProvider offline_completion_cases */
	public function test_legacy_offline_completion_requires_epoch_zero_paid_evidence( string $assertion, bool $paid, string $status, int $epoch, bool $completed ): void {
		$order = new \WC_Order();
		$order->set_total( '50' );
		$order->set_payment_method( 'pos_cash' );
		$order->set_status( $status );
		$order->set_date_paid( $paid ? time() : null );
		$order->update_meta_data( '_pos_payment_asserted', $assertion );
		if ( $epoch > 0 ) {
			$order->update_meta_data( '_wcpos_money_epoch', $epoch );
		}
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );

		$this->assertSame( $completed, Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$refusal = Money_Epoch::reopen_refusal( $order );
		if ( $completed ) {
			$this->assertNull( $refusal );
		} else {
			$this->assertInstanceOf( \WP_Error::class, $refusal );
			$this->assertSame( 'not_completed', $refusal->get_error_data()['reason'] );
		}
	}

	public function offline_completion_cases(): array {
		return array(
			'completed offline sale' => array( 'offline', true, 'completed', 0, true ),
			'processing offline sale' => array( 'offline', true, 'processing', 0, true ),
			'no assertion' => array( '', true, 'completed', 0, false ),
			'wrong assertion' => array( 'online', true, 'completed', 0, false ),
			'no paid date' => array( 'offline', false, 'completed', 0, false ),
			'open sale' => array( 'offline', true, 'pos-open', 0, false ),
			'partial sale' => array( 'offline', true, 'pos-partial', 0, false ),
			'pending sale' => array( 'offline', true, 'pending', 0, false ),
			'failed sale' => array( 'offline', true, 'failed', 0, false ),
			'reopened sale' => array( 'offline', true, 'completed', 1, false ),
			'later epoch' => array( 'offline', true, 'completed', 2, false ),
		);
	}

	/** @dataProvider paid_attempt_checks */
	public function test_paid_attempt_without_current_counting_row_is_refused( bool $reopen ): void {
		$order = new \WC_Order();
		$order->set_total( '50' );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( 'paid-id' => array( 'state' => 'paid', 'epoch' => 1 ) ) );
		$row = array( 'id' => 'paid-id', 'status' => 'captured', 'amount' => '50', 'epoch' => 1 );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $row ) ) );
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$this->assertNull( Money_Epoch::reopen_refusal( $order ) );
		foreach ( array( array(), array( array_merge( $row, array( 'epoch' => 0 ) ) ), array( array_merge( $row, array( 'status' => 'voided' ) ) ) ) as $rows ) {
			$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => $rows ) );
			if ( $reopen ) {
				$error = Money_Epoch::reopen_refusal( $order );
				$this->assertInstanceOf( \WP_Error::class, $error );
				$this->assertSame( 'paid_without_row', $error->get_error_data()['reason'] );
			} else {
				$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
			}
		}
	}

	public function paid_attempt_checks(): array {
		return array( array( false ), array( true ) );
	}

	public function test_reopened_completion_requires_full_current_epoch_net_coverage(): void {
		$order = new \WC_Order();
		$order->set_total( '100' );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$old = array( 'id' => 'old', 'status' => 'captured', 'amount' => '100', 'epoch' => 0 );
		$current = array( 'id' => 'new', 'status' => 'captured', 'amount' => '1', 'epoch' => 1 );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $old, $current ) ) );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$current['amount'] = '100';
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $old, $current ) ) );
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$current['refunded_amount'] = '1';
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $old, $current ) ) );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
	}

	public function test_reopen_ignores_prior_epoch_live_rows_and_attempts(): void {
		$order = new \WC_Order();
		$order->set_total( '100' );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( 'old' => array( 'state' => 'sent', 'epoch' => 0 ) ) );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array(
			array( 'id' => 'old', 'status' => 'authorized', 'amount' => '100', 'epoch' => 0 ),
			array( 'id' => 'new', 'status' => 'captured', 'amount' => '100', 'epoch' => 1 ),
		) ) );
		$this->assertNull( Money_Epoch::reopen_refusal( $order ) );
	}

	public function test_prior_epoch_row_does_not_represent_current_settled_attempt(): void {
		$order = new \WC_Order();
		$order->set_total( '100' );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( 'same-id' => array( 'state' => 'settled', 'epoch' => 1 ) ) );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array(
			array( 'id' => 'same-id', 'status' => 'captured', 'amount' => '100', 'epoch' => 0 ),
		) ) );
		$refusal = Money_Epoch::reopen_refusal( $order );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'settled_without_row', $refusal->get_error_data()['reason'] );
	}

	public function test_reopen_malformed_legacy_row_id_is_not_attempt_representation(): void {
		$order = new \WC_Order();
		$order->set_total( '50' );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( '123' => 'settled' ) );
		foreach ( array( array( '123' ), 123, null, false ) as $id ) {
			$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( array( 'id' => $id, 'status' => 'captured', 'amount' => '50' ) ) ) );
			$refusal = Money_Epoch::reopen_refusal( $order );
			$this->assertInstanceOf( \WP_Error::class, $refusal );
			$this->assertSame( 'settled_without_row', $refusal->get_error_data()['reason'] );
		}
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( array( 'id' => '123', 'status' => 'captured', 'amount' => '50' ) ) ) );
		$this->assertNull( Money_Epoch::reopen_refusal( $order ) );
	}
	public function test_completion_proofs_are_epoch_scoped(): void {
		$this->assertTrue( class_exists( Money_Epoch::class ) );
		$order = new \WC_Order();
		$order->set_total( '50' );
		$this->assertSame( 0, Money_Epoch::current( $order ) );
		$this->assertNull( Money_Epoch::completion_stamp( $order ) );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( array( 'id' => 'cash', 'status' => 'captured', 'amount' => '50', 'refunded_amount' => '0' ) ) ) );
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->update_meta_data( '_wcpos_gateway_attempts', array( 'old' => 'settled', 'new' => array( 'state' => 'paid', 'epoch' => 1 ), 'bad' => 9 ) );
		$this->assertSame( array( 'old' => array( 'state' => 'settled', 'epoch' => 0 ), 'new' => array( 'state' => 'paid', 'epoch' => 1 ) ), Money_Epoch::attempt_entries( $order ) );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->delete_meta_data( '_wcpos_gateway_attempts' );
		$order->update_meta_data( '_wcpos_completed_at', '2026-10-18T12:00:00Z' );
		$order->update_meta_data( '_wcpos_completed_epoch', 1 );
		$this->assertSame( array( 'completed_at' => '2026-10-18T12:00:00Z', 'epoch' => 1, 'session' => null ), Money_Epoch::completion_stamp( $order ) );
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
	}

	public function test_refunded_rows_and_legacy_transaction_cannot_complete_reopened_sale(): void {
		$this->assertTrue( class_exists( Money_Epoch::class ) );
		$order = new \WC_Order();
		$order->set_total( '0' );
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( array( 'id' => 'cash', 'status' => 'authorized', 'amount' => '50', 'refunded_amount' => '50' ) ) ) );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->set_transaction_id( 'legacy' );
		$order->set_date_paid( time() );
		$order->set_status( 'pos-partial' );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->set_status( 'completed' );
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		$this->assertFalse( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
		foreach ( array( '', -1, 'bad', array(), '1.5' ) as $invalid ) {
			$order->update_meta_data( '_wcpos_money_epoch', $invalid );
			$this->assertSame( 0, Money_Epoch::current( $order ) );
		}
	}
}
