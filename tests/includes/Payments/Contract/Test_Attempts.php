<?php
/**
 * The Attempt module, at its interface: entries, their cap, the awaiting state and provenance.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;
use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/**
 * `Attempts` and `Attempt_Log` through `Ledger::write()`, on posts storage (`Test_Attempts_HPOS` re-runs them on the
 * orders table).
 */
class Test_Attempts extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	private const REGISTER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

	/** Put Free's gateway handler back after a test answered for the provider. */
	public function tearDown(): void {
		Capture_Mode_Registry::instance()->register( 'gateway', Gateway_Handler::class );
		Answering_Gateway_Handler::$answer = null;
		parent::tearDown();
	}

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

	/** Paid facts the rows do not record: the resolver mints the attempt's row once, and its write completes the sale once. */
	public function test_reconcile_of_a_paid_attempt_mints_its_row_and_completes_the_sale_once(): void {
		// Arrange: the gateway threw after taking the money; no row recorded it.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent', array( 'register_id' => self::REGISTER ) );
		$this->paid_outside( $order->get_id(), 'wcpos_paid_test' );

		// Act.
		$minted = Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' );
		$again  = Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' );
		Ledger::flush_all();

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$rows   = Ledger::instance()->read( $stored );
		$this->assertSame( $attempt, $minted['id'] ?? null );
		$this->assertNull( $again );
		$this->assertSame( array( $attempt ), array_column( $rows, 'id' ) );
		$this->assertSame( array( 'captured', '92.95', 'wcpos_paid_test', 'txn_lost' ), array( $rows[0]['status'], $rows[0]['amount'], $rows[0]['method_id'], $rows[0]['provider_refs']['transaction_id'] ) );
		$this->assertSame( 'paid', Attempts::of( $stored )->get( $attempt )['fate'] );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
		$this->assertSame( self::REGISTER, Completion_History::of( $stored )->standing()['register'] );
		$this->assertCount( 1, ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id(), 'type' => 'sale' ) ) );
	}

	/**
	 * A captured reconciliation whose S1 stored the attempt's `paid` fate but lost its row stays unresolved: a later
	 * request's sweep mints the row once, the sale completes once, and the attempt leaves the index.
	 */
	public function test_a_paid_attempt_whose_row_was_lost_is_recovered_by_a_later_sweep_once(): void {
		// Arrange: the gateway paid outside the ledger; the resolver's write stored the fate but not the row.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		$this->paid_outside( $order->get_id(), 'wcpos_paid_test' );
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'paid' );
		$half = $this->fresh( $order->get_id() );
		wp_cache_flush(); // A later request: nothing in memory from this one.

		// Act.
		add_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		try {
			( new Payments_Sweeper() )->run();
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		}

		// Assert.
		$this->assertSame( array(), Ledger::instance()->read( $half ), 'Precondition: the row is missing.' );
		$this->assertSame( $attempt, Attempts::of( $half )->unresolved()['id'] ?? null, 'A paid attempt with no row is unresolved.' );
		$stored = $this->fresh( $order->get_id() );
		$this->assertSame( array( $attempt ), array_column( Ledger::instance()->read( $stored ), 'id' ) );
		$this->assertSame( 'paid', Attempts::of( $stored )->get( $attempt )['fate'] );
		$this->assertNull( Attempts::of( $stored )->unresolved() );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
		$this->assertCount( 1, ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id(), 'type' => 'sale' ) ) );
		$this->assertSame( array(), $this->live_index( $order->get_id() ) );
	}

	/**
	 * The index is written last, from storage: a resolver write whose row never lands (both S1 tries) leaves the sweep's
	 * list as it was, so a later request's sweep re-mints the row once, the sale completes once, and the attempt leaves.
	 */
	public function test_a_resolver_write_that_lost_its_row_leaves_the_order_in_the_sweeps_list(): void {
		// Arrange: a stranded attempt the gateway paid outside the ledger; the resolver's row write never reaches storage.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent' );
		$this->paid_outside( $order->get_id(), 'wcpos_paid_test' );
		$lose = static fn( $sql ) => is_string( $sql ) && false !== strpos( $sql, Ledger::META_KEY ) && preg_match( '/^\s*(INSERT|UPDATE)/i', $sql ) ? 'SELECT 0' : $sql;
		add_filter( 'query', $lose );
		try {
			Attempts::reconcile( wc_get_order( $order->get_id() ), 'replay' );
			$this->fail( 'Precondition: the row write is lost.' );
		} catch ( Ledger_Persistence_Exception $lost ) {
			unset( $lost );
		} finally {
			remove_filter( 'query', $lose );
		}
		wp_cache_flush(); // A later request: nothing in memory from this one.
		$listed = $this->live_index( $order->get_id() );

		// Act.
		add_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		try {
			( new Payments_Sweeper() )->run();
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		}

		// Assert.
		$this->assertSame( array( $attempt ), $listed, 'The lost write left the sweep\'s list as it was.' );
		$stored = $this->fresh( $order->get_id() );
		$this->assertSame( array( $attempt ), array_column( Ledger::instance()->read( $stored ), 'id' ) );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
		$this->assertCount( 1, ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id(), 'type' => 'sale' ) ) );
		$this->assertSame( array(), $this->live_index( $order->get_id() ) );
	}

	/** A split-tender sale: the attempt the gateway paid is recorded for the remainder its counting rows leave, never the whole total again. */
	public function test_reconcile_mints_only_the_remainder_a_partial_cash_row_leaves(): void {
		// Arrange: cash pays part; a sent attempt; WooCommerce marks the order paid through the attempt's method.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::rows( $order, array( $this->row( '30.00' ) ) );
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent' );
		$this->paid_outside( $order->get_id(), 'wcpos_paid_test' );

		// Act.
		add_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		try {
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		}

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$minted = Ledger::instance()->find( $stored, $attempt );
		$this->assertSame( '62.95', $minted['amount'] ?? null );
		$this->assertSame( '92.95', Ledger::instance()->paid( Ledger::instance()->read( $stored ) ) );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
	}

	/** A provider that refused the attempt cancels it: the sale is no longer in motion. */
	public function test_reconcile_of_an_attempt_the_provider_failed_cancels_it(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent' );
		Capture_Mode_Registry::instance()->register( 'gateway', Answering_Gateway_Handler::class );
		Answering_Gateway_Handler::$answer = array( 'status' => 'failed' );

		// Act.
		$minted = Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' );

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$this->assertNull( $minted );
		$this->assertSame( 'cancelled', Attempts::of( $stored )->get( $attempt )['fate'] );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$this->assertNull( Ledger::instance()->sale_state( $stored )->in_motion() );
	}

	/** An indeterminate answer counts an ask and changes nothing else; at the limit a human is asked, once, and the attempt stays in motion. */
	public function test_reconcile_counts_indeterminate_asks_and_asks_for_attention_at_the_limit(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent' );
		Capture_Mode_Registry::instance()->register( 'gateway', Answering_Gateway_Handler::class );
		Answering_Gateway_Handler::$answer = new \WP_Error( 'wcpos_provider_error', 'Timeout', array( 'indeterminate' => true ) );

		// Act.
		Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' );
		$first = Attempts::of( $this->fresh( $order->get_id() ) )->get( $attempt );
		for ( $i = 1; $i < Attempts::ASK_LIMIT + 2; ++$i ) {
			Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' ); // A flagged attempt is not swept again.
		}

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$entry  = Attempts::of( $stored )->get( $attempt );
		$this->assertSame( array( 'sent', 1, null ), array( $first['fate'], $first['asks'], $first['attention_at_gmt'] ) );
		$this->assertSame( 'sent', $entry['fate'] );
		$this->assertSame( Attempts::ASK_LIMIT, $entry['asks'] );
		$this->assertNotNull( $entry['attention_at_gmt'] );
		$this->assertCount( 1, $this->notes_naming( $order->get_id(), $attempt ) );
		$this->assertSame( array(), Ledger::instance()->read( $stored ) );
		$this->assertSame( $attempt, Ledger::instance()->sale_state( $stored )->in_motion()['attempt_id'] );
	}

	/** A provider that cannot say (an unpaid order) asks a human with one note, from the sweep only; the attempt stays in motion, never cancelled, and close refuses. */
	public function test_reconcile_of_an_unanswered_attempt_notes_it_once_and_never_cancels_it(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();
		Ledger_Seed::attempt( $order, $attempt, 'wcpos_paid_test', 'sent' );

		// Act.
		Attempts::reconcile( wc_get_order( $order->get_id() ), 'replay' );
		$asked = Attempts::of( $this->fresh( $order->get_id() ) )->get( $attempt );
		Attempts::reconcile( wc_get_order( $order->get_id() ), 'sweep' );
		Attempts::reconcile( wc_get_order( $order->get_id() ), 'replay' );
		$close = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' );
		$close->set_body_params( $this->envelope( $order ) );
		$closed = $this->server->dispatch( $close );

		// Assert.
		$stored = $this->fresh( $order->get_id() );
		$entry  = Attempts::of( $stored )->get( $attempt );
		$this->assertNull( $asked['attention_at_gmt'], 'Only the sweep hands an attempt to a human.' );
		$this->assertSame( 'sent', $entry['fate'] );
		$this->assertNotNull( $entry['attention_at_gmt'] );
		$this->assertCount( 1, $this->notes_naming( $order->get_id(), $attempt ) );
		$this->assertSame( 409, $closed->get_status() );
		$this->assertSame( 'wcpos_payment_in_flight', $closed->get_data()['code'] );
		$this->assertSame( $attempt, $closed->get_data()['data']['attempt_id'] );
	}

	/** The sweep's index lists a sent attempt that awaits no one, never one awaiting the customer, and drops it once a human is asked. */
	public function test_the_live_index_lists_only_sent_unflagged_attempts_awaiting_no_one(): void {
		// Arrange.
		$stranded = $this->create_pos_order();
		$invoiced = $this->create_pos_order();
		$attempt  = wp_generate_uuid4();
		Ledger_Seed::attempt( $stranded, $attempt, 'wcpos_paid_test', 'sent' );
		Ledger_Seed::attempt( $invoiced, wp_generate_uuid4(), 'wcpos_sent_test' );
		$indexed = $this->live_index( $stranded->get_id() );

		// Act.
		Attempts::reconcile( wc_get_order( $stranded->get_id() ), 'sweep' );

		// Assert.
		$this->assertSame( array( $attempt ), $indexed );
		$this->assertSame( array(), $this->live_index( $invoiced->get_id() ) );
		$this->assertSame( array(), $this->live_index( $stranded->get_id() ), 'A flagged attempt leaves the index.' );
	}

	/**
	 * The order paid outside the ledger (the gateway's own save), with no row for it.
	 *
	 * @param int    $id     Order id.
	 * @param string $method Payment method.
	 */
	private function paid_outside( int $id, string $method ): void {
		$order = wc_get_order( $id );
		$order->set_payment_method( $method );
		$order->set_transaction_id( 'txn_lost' );
		$order->set_date_paid( time() );
		$order->set_status( 'processing' );
		$order->save();
	}

	/**
	 * Order notes that name an attempt.
	 *
	 * @param int    $id      Order id.
	 * @param string $attempt Attempt id.
	 */
	private function notes_naming( int $id, string $attempt ): array {
		return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $id ) ), static fn( $note ) => false !== strpos( $note->content, $attempt ) ) );
	}

	/**
	 * The sweep's live index as storage holds it.
	 *
	 * @param int $id Order id.
	 */
	private function live_index( int $id ): array {
		return array_values( array_map( static fn( $meta ) => $meta->value, $this->fresh( $id )->get_meta( Ledger::LIVE_LEG_META_KEY, false ) ) );
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

/** A gateway handler answering what the test says the provider answered. */
class Answering_Gateway_Handler extends Gateway_Handler {
	/**
	 * The provider's answer.
	 *
	 * @var array|\WP_Error|null
	 */
	public static $answer = null;

	/**
	 * Answer for the provider.
	 *
	 * @param array $row The attempt's pseudo row.
	 * @return array|\WP_Error
	 */
	public function status( array $row ) {
		return is_array( self::$answer ) ? array_merge( $row, self::$answer ) : self::$answer;
	}
}
