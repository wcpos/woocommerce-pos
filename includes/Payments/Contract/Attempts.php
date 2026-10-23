<?php
/**
 * The one writer of an order's gateway attempts.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;

/**
 * Owns `_wcpos_gateway_attempts` (ADR 0040 rule 7): every attempt a submit sent, its detail, its fate and the
 * awaiting-customer state, which is read from the entry, never stored apart. Everything stages on a write's draft
 * (`Ledger_Draft::attempt()`, which only this class calls) and lands in that write's S1, the map with the rows.
 */
final class Attempts {
	public const META_KEY = '_wcpos_gateway_attempts';
	/** Resolved entries the map keeps; older ones fall off (a till does not retry a week-old send). An unresolved one is never evicted. */
	public const CAP = 50;
	/** The stored value's schema. */
	private const SCHEMA = 1;
	/** A staged fate that removes the entry: the attempt never reached the gateway, so a retry dispatches it. */
	private const WITHDRAWN = 'withdrawn';
	/** An entry's fields. */
	private const DEFAULTS = array(
		'fate'            => 'sent',
		'method_id'       => '',
		'cashier_id'      => 0,
		'origin'          => array(),
		'command_id'      => null,
		'sent_at_gmt'     => null,
		'resolved_at_gmt' => null,
		'awaiting'        => null,
	);

	/**
	 * The order's attempts as its meta holds them.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function of( WC_Order $order ): Attempt_Log {
		$raw     = $order->get_meta( self::META_KEY );
		$map     = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$entries = is_array( $map['attempts'] ?? null ) ? $map['attempts'] : array();
		if ( is_array( $raw ) ) { // Delete at 2.0.0: `next` dev stores still hold the serialized id => fate map this replaced.
			$entries = array_map( static fn( string $fate ): array => array( 'fate' => $fate ), array_filter( $raw, 'is_string' ) );
		}
		$log = array();
		foreach ( $entries as $id => $entry ) {
			if ( is_array( $entry ) && is_string( $entry['fate'] ?? null ) ) {
				$log[ strtolower( (string) $id ) ] = array_merge( self::DEFAULTS, $entry, array( 'id' => strtolower( (string) $id ) ) );
			}
		}
		return new Attempt_Log( $log );
	}

	/**
	 * Stage a new attempt, `sent`, before external money can move.
	 *
	 * @param Ledger_Draft       $d         The write's draft.
	 * @param string             $id        Attempt id.
	 * @param string             $method_id The gateway's method id.
	 * @param Money_Command|null $command   The till's command: its id and its validated provenance stay on the entry.
	 */
	public static function open( Ledger_Draft $d, string $id, string $method_id, ?Money_Command $command = null ): void {
		$d->attempt(
			$id,
			'sent',
			array(
				'method_id'   => $method_id,
				'cashier_id'  => get_current_user_id(),
				'origin'      => null === $command ? array() : $command->origin()->provenance(),
				'command_id'  => null === $command ? null : $command->id(),
				'sent_at_gmt' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * The gateway sent the customer something to pay: the attempt awaits them, and earlier sends are replaced.
	 *
	 * @param Ledger_Draft $d           The write's draft.
	 * @param string       $id          Attempt id.
	 * @param string|null  $destination Where it went (an email address or phone number), if the form named one.
	 */
	public static function await( Ledger_Draft $d, string $id, ?string $destination ): void {
		self::supersede( $d, $id );
		$awaiting = array(
			'destination' => null === $destination ? null : mb_substr( sanitize_text_field( $destination ), 0, 254 ),
			'at_gmt'      => gmdate( 'c' ),
		);
		$d->attempt( $id, 'sent', array( 'awaiting' => $awaiting ) );
	}

	/**
	 * Stage an attempt's outcome; it awaits no one any more. A settled one also replaces earlier sends.
	 *
	 * @param Ledger_Draft $d    The write's draft.
	 * @param string       $id   Attempt id.
	 * @param string       $fate `paid`, `settled`, `cancelled` or `superseded`.
	 */
	public static function resolve( Ledger_Draft $d, string $id, string $fate ): void {
		if ( 'settled' === $fate ) {
			self::supersede( $d, $id );
		}
		$d->attempt( $id, $fate, array( 'awaiting' => null ) );
	}

	/**
	 * Remove an attempt nothing was dispatched for, so its retry dispatches.
	 *
	 * @param Ledger_Draft $d  The write's draft.
	 * @param string       $id Attempt id.
	 */
	public static function withdraw( Ledger_Draft $d, string $id ): void {
		if ( null !== self::of( $d->order() )->get( $id ) ) {
			$d->attempt( $id, self::WITHDRAWN );
		}
	}

	/**
	 * S1's rule, staged on the draft. Rows covering the total resolve every `sent` attempt that can be resolved: one
	 * whose id is a counting row is `paid`, one awaiting the customer is `superseded`, and one whose outcome is unknown
	 * stays `sent`, so a cash completion never buries a charge that may have landed. Money that lands without
	 * completing the sale means nothing awaits the customer any more. What a resolved entry awaited goes in S3.
	 *
	 * @param Ledger_Draft $d          The write's draft.
	 * @param array        $projection The write's projection.
	 */
	public static function settle( Ledger_Draft $d, array $projection ): void {
		$counting = array_filter( $d->rows(), static fn( array $row ): bool => in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) );
		$paid     = Money::minor( Ledger::instance()->paid( $counting ) );
		$covers   = $projection['completes'] || ( $paid > 0 && $paid >= Money::minor( $d->order()->get_total() ) );
		$lands    = null !== $projection['method'] && ! $projection['completes'];
		$paying   = array_map( static fn( array $row ): string => strtolower( (string) $row['id'] ), $counting );
		foreach ( self::merged( $d->order(), $d->attempts() ) as $id => $entry ) {
			if ( 'sent' !== $entry['fate'] ) {
				continue;
			}
			if ( $covers && in_array( (string) $id, $paying, true ) ) {
				$d->attempt( (string) $id, 'paid' );
			} elseif ( $covers && null !== $entry['awaiting'] ) {
				$d->attempt( (string) $id, 'superseded' );
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d superseded by the payments that cover it; invoice cancellation is unavailable for gateway submissions.', $id, $d->order()->get_id() ) );
			} elseif ( $lands && null !== $entry['awaiting'] ) {
				$d->attempt( (string) $id, 'sent', array( 'awaiting' => null ) );
			}
		}
	}

	/**
	 * Stage the draft's attempts on the order S1 saves, capped: only resolved entries fall off, oldest first.
	 *
	 * @param WC_Order $order   The order S1 saves.
	 * @param array    $patches The draft's staged attempts.
	 */
	public static function store( WC_Order $order, array $patches ): void {
		$entries = self::merged( $order, $patches );
		$over    = count( $entries ) - self::CAP;
		foreach ( $entries as $id => $entry ) {
			if ( $over > 0 && 'sent' !== $entry['fate'] ) {
				unset( $entries[ $id ] );
				--$over;
			}
		}
		if ( ! $entries ) {
			$order->delete_meta_data( self::META_KEY );
			return;
		}
		$order->update_meta_data(
			self::META_KEY,
			wp_json_encode(
				array(
					'schema'   => self::SCHEMA,
					'attempts' => $entries,
				)
			)
		);
	}

	/**
	 * S3's cleanup: the completed sale awaits no one. Staged on the order; the caller saves it.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Whether anything was staged.
	 */
	public static function unstamp( WC_Order $order ): bool {
		$patches = self::clearing( $order );
		if ( $patches ) {
			self::store( $order, $patches );
		}
		return (bool) $patches;
	}

	/** Arm the terminal-status listener. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * An order that awaits the customer and reaches a terminal status: nothing awaits them on a cancelled, refunded or
	 * trashed order, so a deferred write clears it (nothing saves inside the hook). A paid status keeps it: the
	 * completion resolves the attempt in its S1 and clears what it awaited in S3.
	 *
	 * @param int           $id    Order ID.
	 * @param string        $from  Previous status.
	 * @param string        $to    New status.
	 * @param WC_Order|null $order Order object.
	 */
	public static function on_status_changed( $id, $from, $to, $order = null ): void {
		$id    = (int) $id;
		$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
		if ( ! in_array( (string) $to, array( 'cancelled', 'refunded', 'trash' ), true ) || ! $order instanceof WC_Order || null === self::of( $order )->awaiting() ) {
			return;
		}
		Ledger::defer(
			$id,
			static function () use ( $id ): void {
				Ledger::instance()->write(
					$id,
					static function ( Ledger_Draft $d ): void {
						foreach ( self::clearing( $d->order() ) as $attempt => $patch ) {
							$d->attempt( $attempt, $patch['fate'], $patch );
						}
					},
					Ledger::MODE_HOLD
				);
			},
			__( 'Clearing the awaiting-customer state', 'woocommerce-pos' )
		);
	}

	/**
	 * Replace every other `sent` attempt: a new outcome for the sale was sent or settled.
	 *
	 * @param Ledger_Draft $d       The write's draft.
	 * @param string       $current The attempt this submission dispatched.
	 */
	private static function supersede( Ledger_Draft $d, string $current ): void {
		foreach ( self::of( $d->order() )->fates() as $id => $fate ) {
			if ( 'sent' === $fate && strtolower( $current ) !== $id ) {
				$d->attempt( (string) $id, 'superseded', array( 'awaiting' => null ) );
				// Gateway capture has no invoice-cancellation operation (unlike provider void).
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d superseded; invoice cancellation is unavailable for gateway submissions.', $id, $d->order()->get_id() ) );
			}
		}
	}

	/**
	 * The patches that clear what every entry awaits.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string, array>
	 */
	private static function clearing( WC_Order $order ): array {
		$patches = array();
		foreach ( self::of( $order )->entries() as $id => $entry ) {
			if ( null !== $entry['awaiting'] ) {
				$patches[ $id ] = array(
					'fate'     => $entry['fate'],
					'awaiting' => null,
				);
			}
		}
		return $patches;
	}

	/**
	 * The stored entries with the staged patches over them, in the order they were opened; a withdrawn one gone.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $patches Staged patches by attempt id.
	 * @return array<string, array>
	 */
	private static function merged( WC_Order $order, array $patches ): array {
		$entries = self::of( $order )->entries();
		foreach ( $patches as $id => $patch ) {
			$id    = (string) $id;
			$entry = array_merge( $entries[ $id ] ?? self::DEFAULTS + array( 'id' => $id ), $patch );
			if ( self::WITHDRAWN === $entry['fate'] ) {
				unset( $entries[ $id ] );
				continue;
			}
			if ( 'sent' !== $entry['fate'] && null === $entry['resolved_at_gmt'] ) {
				$entry['resolved_at_gmt'] = gmdate( 'c' );
			}
			$entries[ $id ] = $entry;
		}
		return $entries;
	}
}
