<?php
/**
 * One command lifecycle (ADR 0040 rule 5): replay, conflict, revision, run, and the entry riding the write.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Command_Log;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WP_Error;
use WP_REST_Request;

/**
 * `Money_Command`, `Command_Log::run()` and `Command_Log::stage()` through their interfaces, on posts storage
 * (`Test_Command_Log_HPOS` re-runs them on the orders table).
 */
class Test_Command_Log extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	/**
	 * How many times the work ran.
	 *
	 * @var int
	 */
	private $runs = 0;

	/** Count from zero. */
	public function setUp(): void {
		parent::setUp();
		$this->runs = 0;
	}

	/** A route's envelope: `record` keyed by its row id, the others by `command_id`, with what they name. */
	public function test_parse_reads_each_kind_from_its_envelope(): void {
		// Arrange.
		$row     = strtoupper( wp_generate_uuid4() );
		$command = strtoupper( wp_generate_uuid4() );
		$attempt = strtoupper( wp_generate_uuid4() );
		$refund  = $this->request(
			array( 'uuid' => $row ),
			array(
				'command_id' => $command,
				'revision'   => 'sha256:r',
				'refund_id'  => '7',
				'amount'     => '1.00',
			)
		);
		$submit  = $this->request(
			array( 'method' => 'pos_cash' ),
			array(
				'command_id' => $command,
				'revision'   => 'sha256:r',
				'attempt_id' => $attempt,
				'values'     => array(),
			)
		);
		$payment = array(
			'id'     => $row,
			'amount' => '1.00',
		);
		$record  = $this->request(
			array(),
			array(
				'payment'  => $payment,
				'revision' => 'sha256:r',
			)
		);
		$no_id   = $this->request(
			array(),
			array(
				'payment'  => array( 'amount' => '1.00' ),
				'revision' => 'sha256:r',
			)
		);

		// Act.
		$refunded  = Money_Command::parse( 'refund', $refund );
		$submitted = Money_Command::parse( 'submit', $submit );
		$recorded  = Money_Command::parse( 'record', $record );

		// Assert.
		$this->assertSame( strtolower( $command ), $refunded->id() );
		$this->assertSame( strtolower( $row ), $refunded->target() );
		$this->assertSame( 7, $refunded->ref() );
		$this->assertSame( 'sha256:r', $refunded->revision() );
		$this->assertSame( Command_Log::fingerprint( 'refund', $row, $refund->get_body_params() ), $refunded->fp() );
		$this->assertSame( 'pos_cash', $submitted->target() );
		$this->assertSame( strtolower( $attempt ), $submitted->ref() );
		$this->assertSame( strtolower( $row ), $recorded->id() );
		$this->assertSame( '', $recorded->target() );
		$this->assertNull( $recorded->ref() );
		$this->assertSame( Command_Log::fingerprint( 'record', '', $record->get_body_params() ), $recorded->fp() );
		$this->assertNull( Money_Command::parse( 'record', $no_id ), 'A record with no row id is no command: its handler answers the 400.' );
	}

	/** A fresh id on the current revision runs once; the same command again answers what it answered. */
	public function test_run_with_a_fresh_id_and_current_revision_runs_once_and_keeps_the_entry(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed( $id, array( $this->row( '92.95' ) ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close', '', null, $this->current_revision( $id ) );
		$work    = function ( Money_Command $c ) use ( $id ) {
			++$this->runs;
			return Ledger::instance()->close( wc_get_order( $id ), $c );
		};

		// Act.
		$first = Command_Log::run( wc_get_order( $id ), $command, $work );
		$again = Command_Log::run( wc_get_order( $id ), $command, $work );

		// Assert.
		$this->assertSame( 1, $this->runs );
		$this->assertSame( 1, $first['completion']['n'] );
		$this->assertSame( $first['completion'], $again['completion'] );
		$this->assertSame( $command->fp(), $this->find( $id, $command->id() )['fp'] );
	}

	/**
	 * A command that already ran replays its stored outcome under any revision: the work never runs again, except
	 * a record, which answers through its handler from the stored row. Storage does not move.
	 *
	 * @dataProvider kinds
	 *
	 * @param string $kind Command kind.
	 * @param array  $keys The keys of its replayed answer (a record answers its row).
	 */
	public function test_run_with_the_same_id_and_body_replays_without_running_whatever_the_revision( string $kind, array $keys ): void {
		// Arrange: the command ran before; its entry is stored where its kind keeps it.
		$order   = $this->create_pos_order();
		$id      = $order->get_id();
		$row     = $this->row( '10.00' );
		$attempt = wp_generate_uuid4();
		$command = new Money_Command( 'record' === $kind ? $row['id'] : wp_generate_uuid4(), 'sha256:sent', $kind, in_array( $kind, Command_Log::ROW_KINDS, true ) ? $row['id'] : '', 'submit' === $kind ? $attempt : null, 'sha256:stale' );
		$entry   = Command_Log::entry( $command->fp(), $kind, $command->ref(), 'submit' === $kind ? 'sent' : null );
		if ( in_array( $kind, Command_Log::ORDER_KINDS, true ) ) {
			$this->seed( $id, array( $row ) );
			$this->remember( $id, $command->id(), $entry, array( $attempt => 'sent' ) );
		} else {
			$row['commands'] = array( $command->id() => $entry );
			$this->seed( $id, array( $row ) );
		}
		$before = $this->stored( $id );

		// Act.
		$answer = Command_Log::run( wc_get_order( $id ), $command, $this->record_work( $id, $row ) );

		// Assert.
		$this->assertSame( 'record' === $kind ? 1 : 0, $this->runs );
		$this->assertSame( $before, $this->stored( $id ) );
		if ( 'record' === $kind ) {
			$this->assertSame( $row['id'], $answer['id'], 'The stored row, as record() answers it.' );
		} else {
			$this->assertSame( $keys, array_keys( $answer ) );
		}
		if ( is_array( $answer['payment'] ?? null ) ) {
			$this->assertSame( $row['id'], $answer['payment']['id'] );
			$this->assertArrayNotHasKey( 'commands', $answer['payment'] );
		}
		if ( 'submit' === $kind ) {
			$this->assertSame( 'sent', $answer['outcome'] );
		}
	}

	/** The eight money commands and the keys of their replayed answer. */
	public function kinds(): array {
		return array(
			'record'  => array( 'record', array() ),
			'intent'  => array( 'intent', array( 'payment', 'order', 'handoff' ) ),
			'capture' => array( 'capture', array( 'payment', 'order' ) ),
			'void'    => array( 'void', array( 'payment', 'order' ) ),
			'refund'  => array( 'refund', array( 'payment' ) ),
			'submit'  => array( 'submit', array( 'outcome', 'payment', 'order' ) ),
			'cancel'  => array( 'cancel', array( 'order' ) ),
			'close'   => array( 'close', array( 'order', 'completion' ) ),
		);
	}

	/** The same id with another body is 422 naming the command (a record's also its row, without its map); nothing runs. */
	public function test_run_with_another_body_is_422_and_runs_nothing(): void {
		// Arrange: a row with no entry of its own, and a capture entry on it.
		$order   = $this->create_pos_order();
		$id      = $order->get_id();
		$row     = $this->row( '10.00' );
		$capture = wp_generate_uuid4();
		$row['commands'] = array( $capture => Command_Log::entry( 'sha256:one', 'capture' ) );
		$this->seed( $id, array( $row ) );
		$revision = $this->current_revision( $id );

		// Act.
		$record = Command_Log::run( wc_get_order( $id ), new Money_Command( $row['id'], 'sha256:record', 'record', '', null, $revision ), $this->record_work( $id, $row ) );
		$other  = Command_Log::run( wc_get_order( $id ), new Money_Command( $capture, 'sha256:two', 'capture', $row['id'], null, $revision ), $this->record_work( $id, $row ) );

		// Assert.
		$this->assertSame( 0, $this->runs );
		foreach ( array( $record, $other ) as $refusal ) {
			$this->assertWPError( $refusal );
			$this->assertSame( 'wcpos_command_conflict', $refusal->get_error_code() );
			$this->assertSame( 422, $refusal->get_error_data()['status'] );
		}
		$this->assertSame( $row['id'], $record->get_error_data()['command_id'] );
		$this->assertSame( 'record', $record->get_error_data()['kind'] );
		$this->assertSame( $row['id'], $record->get_error_data()['payment']['id'] );
		$this->assertArrayNotHasKey( 'commands', $record->get_error_data()['payment'] );
		$this->assertSame( 'capture', $other->get_error_data()['kind'] );
		$this->assertArrayNotHasKey( 'payment', $other->get_error_data() );
	}

	/** A command that has not run, built on another revision, is 409 with the current one; nothing runs. */
	public function test_run_with_a_fresh_id_and_stale_revision_is_409_and_runs_nothing(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed( $id, array( $this->row( '10.00' ) ) );

		// Act.
		$answer = Command_Log::run( wc_get_order( $id ), new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close', '', null, 'sha256:stale' ), $this->record_work( $id, $this->row( '1.00' ) ) );

		// Assert.
		$this->assertSame( 0, $this->runs );
		$this->assertWPError( $answer );
		$this->assertSame( 'wcpos_revision_stale', $answer->get_error_code() );
		$this->assertSame( 409, $answer->get_error_data()['status'] );
		$this->assertSame( $this->current_revision( $id ), $answer->get_error_data()['currentRevision'] );
	}

	/** A 2xx that changed nothing (an already-allocated refund under a new id) gets its entry in one save. */
	public function test_run_whose_work_answers_without_writing_keeps_the_entry_in_one_write(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '10.00' );
		$this->seed( $id, array( $row ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:refund', 'refund', $row['id'], 7, $this->current_revision( $id ) );
		$saves   = $this->count_saves( $id );

		// Act.
		$answer = Command_Log::run( wc_get_order( $id ), $command, fn() => array( 'payment' => Ledger::to_wire( $row ) ) );

		// Assert.
		$this->assertSame( 1, $saves() );
		$this->assertEquals( array( 'payment' => Ledger::to_wire( $row ) ), $answer );
		$entry = Ledger::instance()->find( $this->fresh( $id ), $row['id'] )['commands'][ $command->id() ];
		$this->assertSame( array( 'sha256:refund', 'refund', 7 ), array( $entry['fp'], $entry['kind'], $entry['ref'] ) );
	}

	/** `record` keeps its entry on the row it created, the row kinds on their target, the order kinds on the order. */
	public function test_write_places_row_kind_entries_on_the_row_and_order_kinds_on_the_order(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '10.00' );
		$this->seed( $id, array( $row ) );
		$capture = new Money_Command( wp_generate_uuid4(), 'sha256:capture', 'capture', $row['id'] );
		$record  = new Money_Command( $row['id'], 'sha256:record', 'record' );
		$cancel  = new Money_Command( wp_generate_uuid4(), 'sha256:cancel', 'cancel', 'pos_cash', 'attempt' );
		$answer  = static fn(): array => array( 'order' => array() );

		// Act.
		foreach ( array( $capture, $record, $cancel ) as $command ) {
			Ledger::instance()->write( $id, $answer, Ledger::MODE_HOLD, $command );
		}

		// Assert.
		$stored = Ledger::instance()->find( $this->fresh( $id ), $row['id'] );
		$this->assertSame( array( $capture->id(), $record->id() ), array_keys( $stored['commands'] ) );
		$this->assertSame( array( $cancel->id() ), array_keys( $this->map( $id ) ) );
		$this->assertSame( 'attempt', $this->map( $id )[ $cancel->id() ]['ref'] );
		$this->expectException( \LogicException::class );
		Ledger::instance()->write( $id, $answer, Ledger::MODE_HOLD, new Money_Command( wp_generate_uuid4(), 'sha256:void', 'void', wp_generate_uuid4() ) );
	}

	/** A change answering an error takes the command's entry out (row or order); one answering null leaves it as stored. */
	public function test_write_whose_change_answers_an_error_removes_the_entry_and_null_leaves_it(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$id      = $order->get_id();
		$row     = $this->row( '10.00' );
		$capture = new Money_Command( wp_generate_uuid4(), 'sha256:capture', 'capture', $row['id'] );
		$close   = new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close' );
		$row['commands'] = array( $capture->id() => Command_Log::entry( $capture->fp(), 'capture' ) );
		$this->seed( $id, array( $row ) );
		$this->remember( $id, $close->id(), Command_Log::entry( $close->fp(), 'close' ) );
		$kept  = $this->map( $id );
		$error = static fn(): WP_Error => new WP_Error( 'wcpos_provider_error', 'Declined.' );

		// Act.
		Ledger::instance()->write( $id, $error, Ledger::MODE_HOLD, $capture );
		Ledger::instance()->write( $id, static fn() => null, Ledger::MODE_HOLD, $close );
		$left = $this->map( $id );
		Ledger::instance()->write( $id, $error, Ledger::MODE_HOLD, $close );

		// Assert.
		$this->assertSame( array(), Ledger::instance()->find( $this->fresh( $id ), $row['id'] )['commands'] );
		$this->assertSame( $kept, $left );
		$this->assertSame( array(), $this->map( $id ) );
	}

	/** An entry that does not read back fails the write `rows`; the same command then lands it once and replays. */
	public function test_write_whose_entry_does_not_read_back_throws_rows_and_the_retry_lands_it_once(): void {
		// Arrange: every save loses the order's command map.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed( $id, array( $this->row( '10.00' ) ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:cancel', 'cancel', 'pos_cash', 'attempt', $this->current_revision( $id ) );
		$work    = function () {
			++$this->runs;
			return array( 'order' => array() );
		};
		$drop    = static function ( $saving ): void {
			$saving->delete_meta_data( Command_Log::META_KEY );
		};
		add_action( 'woocommerce_before_order_object_save', $drop, 1 );
		$caught = null;

		// Act.
		try {
			Command_Log::run( wc_get_order( $id ), $command, $work );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $drop, 1 );
		}
		$retry = new Money_Command( $command->id(), $command->fp(), 'cancel', 'pos_cash', 'attempt', $this->current_revision( $id ) );
		Command_Log::run( wc_get_order( $id ), $retry, $work );
		$replayed = Command_Log::run( wc_get_order( $id ), $retry, $work );

		// Assert.
		$this->assertSame( 'rows', $caught ? $caught->stage : null );
		$this->assertSame( 2, $this->runs, 'The refused run stored nothing, so its retry runs; the next one replays.' );
		$this->assertSame( array( 'order' ), array_keys( $replayed ) );
		$this->assertSame( $command->fp(), $this->map( $id )[ $command->id() ]['fp'] );
	}

	/** A close's entry is stored owing its completion and leaves S3 naming it, with no debt mark. */
	public function test_close_entry_lands_with_its_completion_ref_in_the_completion_save(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed( $id, array( $this->row( '92.95' ) ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close', '', null, $this->current_revision( $id ) );
		$owed    = null;
		$watch   = function () use ( $id, $command, &$owed ): void {
			$owed = $owed ?? $this->map( $id )[ $command->id() ] ?? null;
		};
		add_action( 'woocommerce_pre_payment_complete', $watch );

		// Act.
		$closed = Command_Log::run( wc_get_order( $id ), $command, fn( Money_Command $c ) => Ledger::instance()->close( wc_get_order( $id ), $c ) );
		remove_action( 'woocommerce_pre_payment_complete', $watch );

		// Assert.
		$standing = Completion_History::of( $this->fresh( $id ) )->standing();
		$entry    = $this->map( $id )[ $command->id() ];
		$this->assertSame( Command_Log::OWED, $owed['phase'] ?? null, 'S1 stored the entry owing the paid transition.' );
		$this->assertNull( $owed['ref'] );
		$this->assertSame( $standing['id'], $closed['completion']['id'] );
		$this->assertSame( $standing['id'], $entry['ref'] );
		$this->assertArrayNotHasKey( 'phase', $entry );
		$this->assertArrayNotHasKey( 'owed_at_n', $entry );
	}

	/**
	 * A submit replays the attempt's fate, `recorded` with its row only to the command that first answered it.
	 *
	 * @dataProvider submit_fates
	 *
	 * @param string|null $fate     The attempt's fate; null when the history evicted it.
	 * @param string      $answered What the command first answered.
	 * @param bool        $minted   Whether the attempt's row exists.
	 * @param string      $expected The replayed outcome, or `conflict`.
	 */
	public function test_submit_replay_follows_the_fate_table( ?string $fate, string $answered, bool $minted, string $expected ): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$id      = $order->get_id();
		$attempt = wp_generate_uuid4();
		$this->seed( $id, $minted ? array( $this->row( '10.00', 'captured', array( 'id' => $attempt ) ) ) : array() );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:submit', 'submit', 'pos_cash', $attempt, 'sha256:stale' );
		$this->remember( $id, $command->id(), Command_Log::entry( $command->fp(), 'submit', $attempt, $answered ), null === $fate ? array() : array( $attempt => $fate ) );

		// Act.
		$answer = Command_Log::run( wc_get_order( $id ), $command, $this->record_work( $id, $this->row( '1.00' ) ) );

		// Assert.
		$this->assertSame( 0, $this->runs );
		if ( 'conflict' === $expected ) {
			$this->assertWPError( $answer );
			$this->assertSame( 'wcpos_payment_conflict', $answer->get_error_code() );
			return;
		}
		$this->assertSame( $expected, $answer['outcome'] );
		$this->assertSame( 'recorded' === $expected ? $attempt : null, $answer['payment']['id'] ?? null );
	}

	/** The fate table. */
	public function submit_fates(): array {
		return array(
			'sent'                          => array( 'sent', 'sent', false, 'sent' ),
			'settled'                       => array( 'settled', 'settled', false, 'settled' ),
			'paid, to a command that sent'  => array( 'paid', 'sent', true, 'paid' ),
			'paid, to the one that recorded' => array( 'paid', 'recorded', true, 'recorded' ),
			'cancelled'                     => array( 'cancelled', 'sent', false, 'conflict' ),
			'superseded'                    => array( 'superseded', 'sent', false, 'conflict' ),
			'evicted from the history'      => array( null, 'sent', false, 'sent' ),
		);
	}

	/** A record's replay heals through a write, which saves nothing on a healthy order. */
	public function test_replay_heal_write_saves_nothing_on_a_healthy_order(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '10.00' );
		$row['commands'] = array( $row['id'] => Command_Log::entry( 'sha256:record', 'record' ) );
		$this->seed( $id, array( $row ), Ledger::MODE_AUTO ); // Projected: the partial sale its row says.
		$before = $this->stored( $id );
		$saves  = $this->count_saves( $id );

		// Act.
		$answer = Command_Log::run( wc_get_order( $id ), new Money_Command( $row['id'], 'sha256:record', 'record', '', null, 'sha256:stale' ), $this->record_work( $id, $row ) );

		// Assert.
		$this->assertSame( 0, $saves() );
		$this->assertSame( $before, $this->stored( $id ) );
		$this->assertSame( $row['id'], $answer['id'] );
	}

	/** A row as a route answers it carries no command map; the stored shape keeps it. */
	public function test_to_answer_omits_commands(): void {
		// Arrange.
		$row = array(
			'id'       => wp_generate_uuid4(),
			'commands' => array( wp_generate_uuid4() => Command_Log::entry( 'sha256:x', 'capture' ) ),
		);

		// Act.
		$answer = Ledger::to_answer( $row );

		// Assert.
		$this->assertArrayNotHasKey( 'commands', $answer );
		$this->assertEquals( new \stdClass(), $answer['provider_refs'] );
		$this->assertSame( $row['commands'], Ledger::to_wire( $row )['commands'] );
	}

	/**
	 * A request with route and body params.
	 *
	 * @param array $url  URL params.
	 * @param array $body Body params.
	 */
	private function request( array $url, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wcpos/v2/orders/1' );
		$request->set_url_params( $url );
		$request->set_body_params( $body );
		return $request;
	}

	/**
	 * The handler for a record of this row, counting its runs.
	 *
	 * @param int   $id  Order id.
	 * @param array $row The row.
	 */
	private function record_work( int $id, array $row ): callable {
		return function ( Money_Command $command ) use ( $id, $row ) {
			++$this->runs;
			return Ledger::instance()->record( wc_get_order( $id ), array_intersect_key( $row, array_flip( array( 'id', 'method_id', 'amount', 'currency' ) ) ), array(), $command );
		};
	}

	/**
	 * Store rows through a write: normalized, and by default nothing projected.
	 *
	 * @param int    $id   Order id.
	 * @param array  $rows Rows.
	 * @param string $mode Write mode.
	 */
	private function seed( int $id, array $rows, string $mode = Ledger::MODE_HOLD ): void {
		Ledger::instance()->write(
			$id,
			static function ( Ledger_Draft $draft ) use ( $rows ): void {
				foreach ( $rows as $row ) {
					$draft->put( $row );
				}
			},
			$mode
		);
	}

	/**
	 * Store an order-level entry, and attempt fates.
	 *
	 * @param int    $id         Order id.
	 * @param string $command_id Command id.
	 * @param array  $entry      Entry.
	 * @param array  $fates      Attempt fates.
	 */
	private function remember( int $id, string $command_id, array $entry, array $fates = array() ): void {
		$order = $this->fresh( $id );
		Command_Log::remember( $order, $command_id, $entry );
		if ( $fates ) {
			$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, $fates );
		}
		Ledger::save_meta( $order );
	}

	/**
	 * Count the order's saves from now.
	 *
	 * @param int $id Order id.
	 */
	private function count_saves( int $id ): callable {
		$count = 0;
		add_action(
			'woocommerce_after_order_object_save',
			static function ( $saved ) use ( $id, &$count ): void {
				$count += $saved->get_id() === $id ? 1 : 0;
			}
		);
		return static function () use ( &$count ): int {
			return $count;
		};
	}

	/**
	 * The rows and the order's command map as storage holds them.
	 *
	 * @param int $id Order id.
	 */
	private function stored( int $id ): array {
		$order = $this->fresh( $id );
		return array( $order->get_meta( Ledger::META_KEY ), $order->get_meta( Command_Log::META_KEY ) );
	}

	/**
	 * The order's command map.
	 *
	 * @param int $id Order id.
	 */
	private function map( int $id ): array {
		return (array) json_decode( (string) $this->fresh( $id )->get_meta( Command_Log::META_KEY ), true );
	}

	/**
	 * A command's stored entry.
	 *
	 * @param int    $id         Order id.
	 * @param string $command_id Command id.
	 */
	private function find( int $id, string $command_id ): ?array {
		$order = $this->fresh( $id );
		return Command_Log::find( $order, Ledger::instance()->read( $order ), $command_id );
	}

	/**
	 * The order as storage holds it.
	 *
	 * @param int $id Order id.
	 */
	private function fresh( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * A manual cash row.
	 *
	 * @param string $amount Amount.
	 * @param string $status Status.
	 * @param array  $extra  Fields.
	 */
	private function row( string $amount, string $status = 'captured', array $extra = array() ): array {
		return array_merge(
			array(
				'id'              => wp_generate_uuid4(),
				'method_id'       => 'pos_cash',
				'kind'            => 'cash',
				'capture_mode'    => 'manual',
				'amount'          => $amount,
				'currency'        => get_woocommerce_currency(),
				'status'          => $status,
				'captured_at_gmt' => 'captured' === $status ? gmdate( 'c' ) : null,
			),
			$extra
		);
	}

	/** An open POS order for 92.95. */
	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}
}
