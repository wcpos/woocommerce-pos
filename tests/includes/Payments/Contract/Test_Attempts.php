<?php
/**
 * The Attempt module, at its interface: entries, their cap, the awaiting state and provenance.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;

/**
 * `Attempts` and `Attempt_Log` through `Ledger::write()`, on posts storage (`Test_Attempts_HPOS` re-runs them on the
 * orders table).
 */
class Test_Attempts extends WCPOS_REST_Unit_Test_Case {
	private const REGISTER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

	/** An opened attempt is stored with its detail by the write that opens it: fate, method, cashier, origin, command. */
	public function test_open_stores_the_attempt_with_its_detail_in_the_opening_write(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:x', 'submit', '', $attempt, '', new Completion_Origin( array( 'register_id' => self::REGISTER ) ) );

		// Act.
		Ledger::instance()->write( $order, static fn( Ledger_Draft $d ) => Attempts::open( $d, $attempt, 'wcpos_sent_test', $command ), Ledger::MODE_HOLD );

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$entry  = Attempts::of( $stored )->get( $attempt );
		$this->assertSame( 'sent', $entry['fate'] );
		$this->assertSame( 'wcpos_sent_test', $entry['method_id'] );
		$this->assertSame( get_current_user_id(), $entry['cashier_id'] );
		$this->assertSame( array( 'register' => self::REGISTER ), $entry['origin'] );
		$this->assertSame( $command->id(), $entry['command_id'] );
		$this->assertNotNull( $entry['sent_at_gmt'] );
		$this->assertNull( $entry['awaiting'] );
		$this->assertSame( 1, json_decode( (string) $stored->get_meta( Attempts::META_KEY ), true )['schema'] );
		$this->assertSame( $attempt, Attempts::of( $stored )->unresolved()['id'] );
	}

	/** At the cap the oldest resolved entries fall off; an unresolved one never does, however old. */
	public function test_the_cap_evicts_the_oldest_resolved_entries_and_never_an_unresolved_one(): void {
		// Arrange: a stranded attempt first, then a full cap of resolved ones after it.
		$order    = $this->create_pos_order();
		$stranded = wp_generate_uuid4();
		$resolved = array();
		for ( $i = 0; $i < Attempts::CAP; ++$i ) {
			$resolved[] = wp_generate_uuid4();
		}
		Ledger_Seed::attempt( $order, $stranded, 'wcpos_sent_test', 'sent' );

		// Act.
		Ledger::instance()->write(
			$order,
			static function ( Ledger_Draft $d ) use ( $resolved ): void {
				foreach ( $resolved as $id ) {
					Attempts::open( $d, $id, 'wcpos_sent_test' );
					Attempts::resolve( $d, $id, 'cancelled' );
				}
			},
			Ledger::MODE_HOLD
		);

		// Assert.
		$fates = Attempts::of( $this->fresh( $order->get_id() ) )->fates();
		$this->assertCount( Attempts::CAP, $fates );
		$this->assertSame( 'sent', $fates[ $stranded ] );
		$this->assertArrayNotHasKey( $resolved[0], $fates );
		$this->assertSame( 'cancelled', $fates[ end( $resolved ) ] );
	}

	/** A `next` dev store's serialized id => fate map is read, and the next write stores it as entries. */
	public function test_the_legacy_map_is_read_and_rewritten_as_entries(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$sent  = wp_generate_uuid4();
		$paid  = wp_generate_uuid4();
		$order->update_meta_data(
			Attempts::META_KEY,
			array(
				$paid => 'paid',
				$sent => 'sent',
			)
		);
		$order->save_meta_data();

		// Act.
		$legacy = Attempts::of( $this->fresh( $order->get_id() ) );
		Ledger::instance()->write( $order->get_id(), static fn( Ledger_Draft $d ) => Attempts::resolve( $d, $sent, 'cancelled' ), Ledger::MODE_HOLD );

		// Assert.
		$this->assertSame(
			array(
				$paid => 'paid',
				$sent => 'sent',
			),
			$legacy->fates()
		);
		$this->assertSame( $sent, $legacy->unresolved()['id'] );
		$stored = $this->fresh( $order->get_id() );
		$this->assertIsString( $stored->get_meta( Attempts::META_KEY ) );
		$this->assertSame(
			array(
				$paid => 'paid',
				$sent => 'cancelled',
			),
			Attempts::of( $stored )->fates()
		);
	}

	/** Money that lands without completing clears what the attempt awaits; its entry keeps the submit's provenance. */
	public function test_provenance_survives_the_clear_of_what_the_attempt_awaits(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_sent_test', 'awaiting', array( 'register_id' => self::REGISTER ) );
		$this->assertSame( array( 'register' => self::REGISTER ), Attempts::of( $this->fresh( $order->get_id() ) )->awaiting()['provenance'], 'Precondition: it awaits the customer.' );

		// Act: part of the total is paid in cash.
		Ledger_Seed::rows( $order, array( $this->row( '10.00' ) ) );

		// Assert.
		$log = Attempts::of( $this->fresh( $order->get_id() ) );
		$this->assertNull( $log->awaiting() );
		$this->assertSame( 'sent', $log->get( $attempt )['fate'] );
		$this->assertSame( array( 'register' => self::REGISTER ), $log->get( $attempt )['origin'] );
	}

	/** A cash completion never buries a charge that may have landed: the stranded attempt stays unresolved, the awaited one is replaced. */
	public function test_cash_completion_after_a_stranded_attempt_keeps_it_unresolved_and_supersedes_an_awaiting_one(): void {
		// Arrange: an invoice awaiting the customer, then an attempt whose gateway threw after dispatch.
		$order    = $this->create_pos_order();
		$awaiting = wp_generate_uuid4();
		$stranded = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $awaiting, 'wcpos_sent_test' );
		Ledger_Seed::attempt( $order, $stranded, 'wcpos_paid_test', 'sent' );

		// Act: cash covers the total.
		$written = Ledger::instance()->write( $order->get_id(), fn( Ledger_Draft $d ) => $d->put( $this->row( '92.95' ) ) );

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$log    = Attempts::of( $stored );
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( 'superseded', $log->get( $awaiting )['fate'] );
		$this->assertSame( 'sent', $log->get( $stranded )['fate'] );
		$this->assertNull( $log->awaiting(), 'The completion awaits no one.' );
		$this->assertSame( $stranded, Ledger::instance()->sale_state( $stored )->in_motion()['attempt_id'] );
	}

	/** A row carrying the attempt's id pays it; the completion names the submit's register and awaits no one. */
	public function test_a_row_with_the_attempts_id_pays_it_and_its_completion_names_the_submits_register(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_sent_test', 'awaiting', array( 'register_id' => self::REGISTER ) );

		// Act.
		Ledger::instance()->write( $order->get_id(), fn( Ledger_Draft $d ) => $d->put( $this->row( '92.95', array( 'id' => $attempt ) ) ) );

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$this->assertSame( 'paid', Attempts::of( $stored )->get( $attempt )['fate'] );
		$this->assertNotNull( Attempts::of( $stored )->get( $attempt )['resolved_at_gmt'] );
		$this->assertNull( Attempts::of( $stored )->awaiting() );
		$this->assertSame( self::REGISTER, Completion_History::of( $stored )->standing()['register'] );
	}

	/** A cancelled order awaits no one: the clear runs after the status save, never inside its hooks. */
	public function test_a_cancelled_order_clears_what_its_attempt_awaits_after_the_status_save(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_sent_test' );

		// Act.
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$during = Attempts::of( $this->fresh( $order->get_id() ) )->awaiting();
		Ledger::flush_all(); // The request ends.

		// Assert.
		$this->assertNotNull( $during, 'Precondition: nothing saved inside the status hook.' );
		$log = Attempts::of( $this->fresh( $order->get_id() ) );
		$this->assertNull( $log->awaiting() );
		$this->assertSame( 'sent', $log->get( $attempt )['fate'] );
	}

	/** The deferred clear belongs to the cancellation it saw: a sale reopened and sent a new invoice before it runs keeps that invoice awaiting. */
	public function test_a_deferred_cancel_clear_leaves_a_new_invoice_sent_after_a_reopen_awaiting(): void {
		// Arrange: an invoice awaits the customer; the order is cancelled, and the clear waits for the request's end.
		$order = $this->create_pos_order();
		$first = wp_generate_uuid4();
		$again = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $first, 'wcpos_sent_test' );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		// Act: in the same request the sale is reopened and a new invoice sent (its write flushes the deferred clear).
		wc_get_order( $order->get_id() )->update_status( 'pos-open' );
		Ledger_Seed::attempt( $order->get_id(), $again, 'wcpos_sent_test' );
		Ledger::flush_all();

		// Assert.
		$log = Attempts::of( $this->fresh( $order->get_id() ) );
		$this->assertSame( $again, $log->awaiting()['attempt_id'] ?? null );
		$this->assertSame( 'superseded', $log->get( $first )['fate'] );
	}

	/**
	 * The order and its meta as storage holds them.
	 *
	 * @param int $id Order id.
	 */
	private function fresh( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * A captured cash row.
	 *
	 * @param string $amount Amount.
	 * @param array  $extra  Fields.
	 */
	private function row( string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'              => wp_generate_uuid4(),
				'method_id'       => 'pos_cash',
				'kind'            => 'cash',
				'capture_mode'    => 'manual',
				'amount'          => $amount,
				'currency'        => get_woocommerce_currency(),
				'status'          => 'captured',
				'captured_at_gmt' => gmdate( 'c' ),
			),
			$extra
		);
	}

	/** An open POS order. */
	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}
}
