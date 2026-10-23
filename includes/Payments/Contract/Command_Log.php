<?php
/**
 * The money commands an order has answered, for replay by command id.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_Error;

/**
 * ADR 0040 rule 5: every money command is idempotent by a till-minted command id for as long as
 * the row it touched exists. A command that acts on a row (`intent`, `capture`, `void`, `refund`)
 * keeps `{ fp, kind, ref, at_gmt }` in that row's `commands` map; one with no row of its own
 * (`submit`, `cancel`, `close`) keeps `{ fp, kind, ref, outcome, at_gmt }` in the order's
 * `_wcpos_commands` map. Neither is ever evicted: an evicted id would run its command again.
 * No table and no option (`.ai/rules/db-tables.mdc`); both maps are server-owned order meta.
 */
final class Command_Log {
	/** Commands kept on the row they act on. */
	public const ROW_KINDS = array( 'intent', 'capture', 'void', 'refund' );
	/** Commands kept on the order, because they may complete with no row. */
	public const ORDER_KINDS = array( 'submit', 'cancel', 'close' );
	/** The order-level map, in `Pos_Order_Audit::SERVER_MONEY_META_KEYS` so a push cannot write it. */
	public const META_KEY = '_wcpos_commands';
	/**
	 * An entry's `phase` while the write it rode stored its rows (S1) and still owes the paid transition
	 * (S2): the replay of that command completes the sale. The completion (S3) advances it. `owed_at_n`
	 * names the completion count the debt was taken at, so one that landed by any other path settles it.
	 */
	public const OWED = 'rows';

	/**
	 * The body fingerprint: route, target and the body as sent (keys sorted at every depth, values
	 * untouched, so `10` and `"10.00"` differ). The same id on another route or row is another body.
	 *
	 * @param string $kind   Command kind.
	 * @param string $target Payment uuid, method id, or '' for close.
	 * @param array  $body   Request body.
	 */
	public static function fingerprint( string $kind, string $target, array $body ): string {
		unset( $body['command_id'], $body['revision'] );
		$canonical = Revision::canonicalize(
			array(
				'kind'   => $kind,
				'target' => strtolower( $target ),
				'body'   => $body,
			)
		);
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}

	/**
	 * One stored outcome.
	 *
	 * @param string      $fp      Body fingerprint.
	 * @param string      $kind    Command kind.
	 * @param mixed       $ref     Refund id, attempt id or completion id; null when none.
	 * @param string|null $outcome A submit's wire outcome.
	 */
	public static function entry( string $fp, string $kind, $ref = null, ?string $outcome = null ): array {
		$entry = array(
			'fp'     => $fp,
			'kind'   => $kind,
			'ref'    => $ref,
			'at_gmt' => gmdate( 'c' ),
		);
		if ( in_array( $kind, self::ORDER_KINDS, true ) ) {
			$entry['outcome'] = $outcome;
		}
		return $entry;
	}

	/**
	 * Answer a money command by its id, under the route's order lock (ADR 0040 rules 5 and 6): a used id
	 * with another body is 422; a used id replays its stored outcome whatever the revision it carries (a
	 * retry carries the one from before its own effect); a fresh id built on a stale revision is 409;
	 * otherwise the work runs. A 2xx whose writes kept no entry (it changed nothing) gets one, so the id
	 * is never free for another body.
	 *
	 * @param WC_Order      $order   Order, read under the lock.
	 * @param Money_Command $command The command.
	 * @param callable      $work    fn( Money_Command ): the handler's answer.
	 * @return mixed A refusal, a replay, or the work's answer.
	 * @throws Ledger_Persistence_Exception When an entry does not reach storage: the retry replays.
	 */
	public static function run( WC_Order $order, Money_Command $command, callable $work ) {
		$ledger = Ledger::instance();
		$stored = self::find( $order, $ledger->read( $order ), $command->id() );
		if ( null !== $stored && $stored['fp'] !== $command->fp() ) {
			$data = array(
				'status'     => 422,
				'command_id' => $command->id(),
				'kind'       => $command->kind(),
			);
			if ( 'record' === $command->kind() && null !== $stored['payment_id'] ) {
				$data['payment'] = Ledger::to_answer( (array) $ledger->find( $order, $stored['payment_id'] ) );
			}
			return new WP_Error( 'wcpos_command_conflict', __( 'This command ID was already used with a different request.', 'woocommerce-pos' ), $data );
		}
		if ( null !== $stored ) {
			return self::replay( $order, $command, $stored, $work );
		}
		$stale = self::outdated( $order, $command->revision() );
		if ( null !== $stale ) {
			return $stale;
		}
		$answer = $work( $command );
		$fresh  = is_wp_error( $answer ) ? null : wc_get_order( $order->get_id() );
		if ( $fresh instanceof WC_Order ) {
			$fresh->read_meta_data( true );
			if ( $command->fp() !== ( self::find( $fresh, $ledger->read( $fresh ), $command->id() )['fp'] ?? null ) ) {
				$data = $answer instanceof \WP_REST_Response ? $answer->get_data() : $answer;
				$ledger->write( $fresh, static fn() => $data, Ledger::MODE_HOLD, $command );
			}
		}
		return $answer;
	}

	/**
	 * Stage a command's entry on a write's draft from what the write's change answered (`Ledger::write()`, S1,
	 * read back with the rows): an error removes it (a definite failure keeps none), null leaves storage as it
	 * is, anything else puts `{ fp, kind, ref, outcome?, at_gmt }`. `record` keeps it on the row it created, the
	 * row kinds on their target row, the order kinds in the order map. A stored entry keeps its time and debt.
	 *
	 * @param Ledger_Draft  $draft   The write's draft.
	 * @param Money_Command $command The command.
	 * @param mixed         $answer  The change's answer.
	 * @throws \LogicException When a row command answers with no row to keep its entry.
	 */
	public static function stage( Ledger_Draft $draft, Money_Command $command, $answer ): void {
		if ( null === $answer ) {
			return;
		}
		$id    = $command->id();
		$kind  = $command->kind();
		$order = in_array( $kind, self::ORDER_KINDS, true );
		$row   = $order ? null : $draft->row( 'record' === $kind ? $id : $command->target() );
		$held  = $order ? ( self::read( $draft->order() )[ $id ] ?? null ) : ( $row['commands'][ $id ] ?? null );
		if ( is_wp_error( $answer ) ) {
			if ( $order ) {
				self::forget( $draft->order(), $id );
			} elseif ( null !== $row ) {
				$draft->put( self::without_on_row( $row, $id ) );
			}
			return;
		}
		if ( ! $order && null === $row ) {
			throw new \LogicException( esc_html( sprintf( 'WCPOS %s command %s answered with no payment row to keep its entry.', $kind, $id ) ) );
		}
		$ref   = 'close' === $kind ? ( is_array( $answer ) ? ( $answer['completion']['id'] ?? null ) : null ) : $command->ref();
		$entry = self::entry( $command->fp(), $kind, $ref ?? ( $held['ref'] ?? null ), 'submit' === $kind && is_array( $answer ) ? ( $answer['outcome'] ?? null ) : null );
		$entry = array_merge( $entry, array_intersect_key( (array) $held, array_flip( array( 'at_gmt', 'phase', 'owed_at_n' ) ) ) );
		if ( $order ) {
			self::remember( $draft->order(), $id, $entry );
			return;
		}
		$row['commands'][ $id ] = $entry;
		$draft->put( $row );
	}

	/**
	 * A command's stored outcome, from storage. A record, a submit whose minted row has no completion yet, or an entry
	 * still owing the paid transition its write's rows covered, first heals through a write (it saves nothing on a
	 * healthy order and completes the sale only while a command owes it, or stamps a paid sale's missing completion). A record, and an intent still pending, answer
	 * through their handler: the record from its stored row, the intent by resuming at the provider (handlers
	 * key the call on the row id, so it hands back the same intent and the handoff the till lost).
	 *
	 * @param WC_Order      $order   Order.
	 * @param Money_Command $command The command.
	 * @param array         $entry   Its stored entry.
	 * @param callable      $work    The handler.
	 * @return mixed
	 */
	private static function replay( WC_Order $order, Money_Command $command, array $entry, callable $work ) {
		$ledger = Ledger::instance();
		$kind   = $command->kind();
		if ( 'record' === $kind && null === $ledger->refusal_error( (array) $ledger->find( $order, $command->id() ), $order ) ) {
			$drained = $ledger->drain_parked( $order, $command->id() ); // A confirmation its arrival stopped before draining.
			if ( is_wp_error( $drained ) ) {
				return $drained;
			}
		}
		// A submit heals only while its attempt's row stands with no completion (S3 lost after the mint): a write on a
		// sale with no row would project an unpaid settled status (`pending`) back into progress.
		$minted = 'submit' === $kind && null !== $ledger->find( $order, (string) $entry['ref'] ) && null === Completion_History::of( $order )->standing();
		if ( 'record' === $kind || $minted || self::owes( $order, $entry ) ) {
			// The command rides the heal so its completion names the origin its fingerprinted body carried.
			$order = $ledger->write( $order, null, 'close' === $kind ? Ledger::MODE_CLOSE : Ledger::MODE_AUTO, $command )->order;
		}
		$row = null === $entry['payment_id'] ? null : $ledger->find( $order, $entry['payment_id'] );
		if ( 'record' === $kind || ( 'intent' === $kind && 'pending' === ( $row['status'] ?? '' ) ) ) {
			return $work( $command );
		}
		$refusal = 'intent' === $kind ? $ledger->refusal_error( (array) $row, $order ) : null;
		if ( $refusal ) {
			return $refusal;
		}
		$payment = Ledger::to_answer( (array) $row );
		$summary = $ledger->summary( $order );
		switch ( $kind ) {
			case 'capture':
			case 'void':
				return array(
					'payment' => $payment,
					'order'   => $summary,
				);
			case 'intent':
				return array(
					'payment' => $payment,
					'order'   => $summary,
					'handoff' => new \stdClass(),
				);
			case 'refund':
				return array( 'payment' => $payment );
			case 'cancel':
				return array( 'order' => $summary );
			case 'close':
				$history   = Completion_History::of( $order );
				$completed = null === $entry['ref'] ? $history->standing() : $history->by_id( (string) $entry['ref'] );
				return array(
					'order'      => $summary,
					'completion' => null === $completed ? null : array(
						'n'  => (int) $completed['n'],
						'id' => (string) $completed['id'],
					),
				);
		}
		// Submit: the attempt's fate, except to the command that first answered `recorded` (D6).
		$attempt = (string) $entry['ref'];
		$fate    = Gateway_Submission::read_attempts( $order )[ $attempt ] ?? null;
		$minted   = $ledger->find( $order, $attempt );
		$recorded = $minted && ( 'paid' !== $fate || 'recorded' === $entry['outcome'] );
		if ( ! $recorded && in_array( $fate, array( 'cancelled', 'superseded' ), true ) ) {
			return new WP_Error( 'wcpos_payment_conflict', __( 'This attempt was cancelled or superseded at the till.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		return array(
			'outcome' => $recorded ? 'recorded' : ( in_array( $fate, array( 'sent', 'settled', 'paid' ), true ) ? $fate : $entry['outcome'] ),
			'payment' => $recorded ? Ledger::to_answer( $minted ) : null,
			'order'   => $summary,
		);
	}

	/**
	 * Refuse a fresh command built against another revision of the order; propagate a failed read.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $revision The revision the command was built against.
	 */
	private static function outdated( WC_Order $order, string $revision ): ?WP_Error {
		$current = Order_Serializer::current_state( $order->get_id() );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$facts = Revision::stale( $revision, $current['revision'], $current['document'] );
		if ( null === $facts ) {
			return null;
		}
		return new WP_Error(
			'wcpos_revision_stale',
			__( 'The order changed since this command was built; read it again.', 'woocommerce-pos' ),
			array(
				'status'          => 409,
				'currentRevision' => $facts['currentRevision'],
				'current'         => $facts['current'],
				'order'           => Ledger::instance()->summary( $order ),
			)
		);
	}

	/**
	 * The stored entry for a command id anywhere on the order: every row's map, then the order map,
	 * then a row with no entry of its own whose id it is (a gateway mint, webview, adopted or
	 * earlier row: kind `row`, fp null, so any command reusing that id is a conflict).
	 * The answer carries `payment_id`, the row that holds it (null for the order map).
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $rows       Payment rows.
	 * @param string   $command_id Command id.
	 */
	public static function find( WC_Order $order, array $rows, string $command_id ): ?array {
		$command_id = strtolower( $command_id );
		foreach ( $rows as $row ) {
			$entry = $row['commands'][ $command_id ] ?? null;
			if ( is_array( $entry ) ) {
				return array_merge( $entry, array( 'payment_id' => (string) $row['id'] ) );
			}
		}
		$entry = self::read( $order )[ $command_id ] ?? null;
		if ( is_array( $entry ) ) {
			return array_merge( $entry, array( 'payment_id' => null ) );
		}
		foreach ( $rows as $row ) {
			if ( strtolower( (string) ( $row['id'] ?? '' ) ) === $command_id ) {
				$entry               = self::entry( '', 'row' );
				$entry['fp']         = null;
				$entry['payment_id'] = $command_id;
				return $entry;
			}
		}
		return null;
	}

	/**
	 * The row with the command's entry, unless the row already holds that id.
	 *
	 * @param array  $row        Payment row.
	 * @param string $command_id Command id.
	 * @param array  $entry      Entry.
	 */
	public static function with_on_row( array $row, string $command_id, array $entry ): array {
		$commands = is_array( $row['commands'] ?? null ) ? $row['commands'] : array();
		if ( ! isset( $commands[ strtolower( $command_id ) ] ) ) {
			$commands[ strtolower( $command_id ) ] = $entry;
		}
		$row['commands'] = $commands;
		return $row;
	}

	/**
	 * The row without a command's entry (a command that failed keeps none).
	 *
	 * @param array  $row        Payment row.
	 * @param string $command_id Command id; '' removes nothing.
	 */
	public static function without_on_row( array $row, string $command_id ): array {
		unset( $row['commands'][ strtolower( $command_id ) ] );
		return $row;
	}

	/**
	 * Stage an order-level entry; the caller saves.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $command_id Command id.
	 * @param array    $entry      Entry.
	 */
	public static function remember( WC_Order $order, string $command_id, array $entry ): void {
		$map                             = self::read( $order );
		$map[ strtolower( $command_id ) ] = $entry;
		$order->update_meta_data( self::META_KEY, (string) wp_json_encode( (object) $map ) );
	}

	/**
	 * Unstage an order-level entry (a submit that definitely failed); the caller saves.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $command_id Command id.
	 */
	public static function forget( WC_Order $order, string $command_id ): void {
		$map = self::read( $order );
		if ( isset( $map[ strtolower( $command_id ) ] ) ) {
			unset( $map[ strtolower( $command_id ) ] );
			if ( $map ) {
				$order->update_meta_data( self::META_KEY, (string) wp_json_encode( (object) $map ) );
			} else {
				// No empty map is left behind: the order document, and so its revision, is as before the command.
				$order->delete_meta_data( self::META_KEY );
			}
		}
	}

	/**
	 * Mark the commands of the write that covers the sale as owing the paid transition: the write's own
	 * order-level entry, and every entry on each row the write changes (one storage already holds too,
	 * when its row's confirmation is what covers the sale). Returns the rows.
	 *
	 * @param Ledger_Draft       $draft   The write's draft.
	 * @param array              $rows    Settled rows.
	 * @param array              $stored  Stored rows.
	 * @param Money_Command|null $command The write's command.
	 */
	public static function owe( Ledger_Draft $draft, array $rows, array $stored, ?Money_Command $command = null ): array {
		$owed = array(
			'phase'     => self::OWED,
			'owed_at_n' => self::completions( $draft->order() ),
		);
		$entry = null === $command ? null : ( self::read( $draft->order() )[ $command->id() ] ?? null );
		if ( null !== $entry ) {
			self::remember( $draft->order(), $command->id(), array_merge( $entry, $owed ) );
		}
		$held = array_column( $stored, null, 'id' );
		foreach ( $rows as &$row ) {
			if ( ( $held[ $row['id'] ] ?? null ) !== json_decode( (string) wp_json_encode( Ledger::to_wire( $row ) ), true ) ) {
				$row['commands'] = array_map( static fn( $entry ) => array_merge( $entry, $owed ), $row['commands'] );
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Whether a command on the order still owes the paid transition: its entry says so and the order has
	 * as many completions as when the debt was taken (one more, by any path, settled it).
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	public static function owing( WC_Order $order, array $rows ): bool {
		$n = self::completions( $order );
		foreach ( self::entries( $order, $rows ) as $entry ) {
			if ( self::OWED === ( $entry['phase'] ?? null ) && isset( $entry['owed_at_n'] ) && (int) $entry['owed_at_n'] === $n ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this entry itself still owes the paid transition, at the order's current completion count.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $entry Command entry.
	 */
	public static function owes( WC_Order $order, array $entry ): bool {
		return self::OWED === ( $entry['phase'] ?? null ) && isset( $entry['owed_at_n'] ) && self::completions( $order ) === (int) $entry['owed_at_n'];
	}

	/**
	 * Whether a mark outlived the completion it owed: taken at a count below the order's latest.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	public static function stale( WC_Order $order, array $rows ): bool {
		$n = self::completions( $order );
		foreach ( self::entries( $order, $rows ) as $entry ) {
			if ( self::OWED === ( $entry['phase'] ?? null ) && (int) ( $entry['owed_at_n'] ?? -1 ) < $n ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Advance every owing entry once the completion stands: stage the order map (a close naming the
	 * completion it answered) and return the rows advanced; null when nothing owed.
	 *
	 * @param WC_Order $order         Order object; the map is staged on it, the caller saves.
	 * @param array    $rows          Stored rows.
	 * @param string   $completion_id The standing completion.
	 */
	public static function advance( WC_Order $order, array $rows, string $completion_id ): ?array {
		$map  = self::read( $order );
		$owed = false;
		foreach ( $map as $command_id => $entry ) {
			if ( self::OWED === ( $entry['phase'] ?? null ) ) {
				unset( $map[ $command_id ]['phase'], $map[ $command_id ]['owed_at_n'] );
				$map[ $command_id ]['ref'] = 'close' === ( $entry['kind'] ?? '' ) ? $completion_id : ( $entry['ref'] ?? null );
				$owed                      = true;
			}
		}
		if ( $owed ) {
			$order->update_meta_data( self::META_KEY, (string) wp_json_encode( (object) $map ) );
		}
		foreach ( $rows as &$row ) {
			foreach ( is_array( $row['commands'] ?? null ) ? $row['commands'] : array() as $command_id => $entry ) {
				if ( self::OWED === ( $entry['phase'] ?? null ) ) {
					unset( $row['commands'][ $command_id ]['phase'], $row['commands'][ $command_id ]['owed_at_n'] );
					$owed = true;
				}
			}
		}
		unset( $row );
		return $owed ? $rows : null;
	}

	/**
	 * Every entry on the order: the order map's, then each row's.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	private static function entries( WC_Order $order, array $rows ): array {
		$entries = array_values( self::read( $order ) );
		foreach ( $rows as $row ) {
			$entries = array_merge( $entries, array_values( array_filter( is_array( $row['commands'] ?? null ) ? $row['commands'] : array(), 'is_array' ) ) );
		}
		return $entries;
	}

	/**
	 * How many completions the order has had (the latest entry's number; 0 for none).
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function completions( WC_Order $order ): int {
		return (int) ( Completion_History::of( $order )->latest()['n'] ?? 0 );
	}

	/**
	 * The order-level map.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function read( WC_Order $order ): array {
		$raw     = $order->get_meta( self::META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $decoded ) ? array_filter( $decoded, 'is_array' ) : array();
	}
}
