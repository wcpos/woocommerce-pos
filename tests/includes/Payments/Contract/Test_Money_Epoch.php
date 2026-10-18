<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\{Money_Epoch, Ledger};

class Test_Money_Epoch extends \WP_UnitTestCase {
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
		$this->assertTrue( Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );
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
