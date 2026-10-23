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
	/** Indeterminate answers (an hour of ten-minute sweeps) before the resolver asks a human instead. */
	public const ASK_LIMIT = 6;
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
		'asks'            => 0,
		'attention_at_gmt' => null,
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

	/**
	 * A submit's own payment replaces the sends that await the customer: staged on the order the paying write
	 * carries (`Webview_Passthrough::mint()`), so no write completes the sale as the replaced invoice's.
	 *
	 * @param WC_Order $order   The order the mint carries into its write.
	 * @param string   $current The attempt that paid.
	 */
	public static function replace( WC_Order $order, string $current ): void {
		$patches = array_diff_key( self::clearing( $order ), array( strtolower( $current ) => true ) );
		foreach ( $patches as $id => $patch ) {
			$patches[ $id ]['fate'] = 'sent' === $patch['fate'] ? 'superseded' : $patch['fate'];
			Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d superseded by a payment; invoice cancellation is unavailable for gateway submissions.', $id, $order->get_id() ) );
		}
		if ( $patches ) {
			self::store( $order, $patches );
		}
	}

	/**
	 * Rule 7's resolver: what became of the attempt whose outcome is unknown, from its provider's answer, acted on under
	 * the order lock. `captured` mints its row (the attempt id as the row id) in a write that completes the sale at the
	 * attempt's origin; `failed` or `voided` cancels it; an indeterminate answer counts an ask; at the sweep (past its
	 * threshold) an unchanged answer, or the ask limit, flags it for a human with one note and leaves it `sent`. Nothing
	 * cancels an attempt the provider never answered.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $source `paid` asks only about the attempt that awaited the customer, who has paid; `sweep`,
	 *                         `replay`, `cancel` and `submit` only about one nobody awaits (`sweep` not about a flagged one).
	 * @return array|null The row it minted, as stored; null when it minted none.
	 * @throws Ledger_Persistence_Exception When its write does not reach storage.
	 */
	public static function reconcile( WC_Order $order, string $source ): ?array {
		$id   = $order->get_id();
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $id ) ) {
			return null; // Busy: the holder or the next sweep asks.
		}
		try {
			$fresh = wc_get_order( $id );
			if ( $fresh instanceof WC_Order ) {
				$fresh->read_meta_data( true );
			}
			$entry = $fresh instanceof WC_Order ? self::of( $fresh )->unresolved() : null;
			if ( null === $entry || ( 'paid' === $source ) !== ( null !== $entry['awaiting'] ) || ( 'sweep' === $source && null !== $entry['attention_at_gmt'] ) ) {
				return null;
			}
			$handler = Capture_Mode_Registry::instance()->resolve( 'gateway', Descriptor_Builder::instance()->get( $entry['method_id'] )['capture']['provider'] ?? null );
			$answer  = null === $handler ? null : $handler->status(
				array(
					'id'           => $entry['id'],
					'status'       => 'pending',
					'capture_mode' => 'gateway',
					'method_id'    => $entry['method_id'],
					'order_id'     => $id,
				)
			);
			$status  = is_array( $answer ) ? (string) ( $answer['status'] ?? '' ) : '';
			$flagged = false;
			$sweep   = 'sweep' === $source;
			$written = Ledger::instance()->write(
				$id,
				static function ( Ledger_Draft $d ) use ( $entry, $answer, $status, $sweep, &$flagged ): bool {
					$now = self::of( $d->order() )->get( $entry['id'] );
					// Resolved since it was read; a `paid` fate whose row is missing is an S1 that stored only part of itself.
					if ( 'sent' !== ( $now['fate'] ?? null ) && ! ( 'paid' === $now['fate'] && null === $d->row( $now['id'] ) ) ) {
						return false;
					}
					if ( 'captured' === $status ) {
						$cashier = (int) $now['cashier_id'];
						$row     = array(
							'id'            => $now['id'],
							'source'        => 'app',
							'capture_mode'  => 'gateway',
							'method_id'     => $now['method_id'],
							'provider_refs' => array( 'transaction_id' => $answer['provider_refs']['transaction_id'] ?? null ),
						);
						$d->put( Webview_Passthrough::row( $d->order(), $cashier > 0 ? $row + array( 'cashier_id' => $cashier ) : $row ) );
						$d->completes_at( Completion_Origin::of_attempt( $now ) );
						// The command that sent it recorded this row: its replay answers `recorded` (D6).
						$held = null === $now['command_id'] ? null : Command_Log::find( $d->order(), array(), (string) $now['command_id'] );
						if ( null !== $held ) {
							Command_Log::remember( $d->order(), (string) $now['command_id'], array( 'outcome' => 'recorded' ) + array_diff_key( $held, array( 'payment_id' => true ) ) );
						}
						return true;
					}
					if ( in_array( $status, array( 'failed', 'voided' ), true ) ) {
						self::resolve( $d, $now['id'], 'cancelled' );
						return true;
					}
					$asks    = (int) $now['asks'] + ( is_array( $answer ) ? 0 : 1 );
					$flagged = $sweep && null === $now['attention_at_gmt'] && ( is_array( $answer ) || $asks >= self::ASK_LIMIT );
					if ( ! $flagged && $asks === (int) $now['asks'] ) {
						return false;
					}
					$d->attempt( $now['id'], 'sent', array( 'asks' => $asks ) + ( $flagged ? array( 'attention_at_gmt' => gmdate( 'c' ) ) : array() ) );
					return true;
				},
				Ledger::MODE_HOLD
			);
			if ( $flagged ) {
				$title = Descriptor_Builder::instance()->get( $entry['method_id'] )['title'] ?? $entry['method_id'];
				/* translators: 1: attempt id, 2: payment method title. */
				$written->order->add_order_note( sprintf( __( 'WCPOS payment attempt %1$s: the outcome is unknown and %2$s cannot say whether money was taken. Check the payment with the provider, then cancel the attempt at the POS if nothing was charged.', 'woocommerce-pos' ), $entry['id'], $title ) );
			}
			return 'captured' === $status ? Ledger::instance()->find( $written->order, $entry['id'] ) : null;
		} finally {
			$lock->release( $id );
		}
	}

	/**
	 * A payment WooCommerce took whose row did not land (`Webview_Passthrough::mint()` failed) stays an unresolved
	 * attempt the sweep finds, so the resolver mints its row: opened `sent` under the row id the mint tried, or, when
	 * that attempt stands awaiting the customer, no longer awaiting them (they paid). One held write, best effort.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $id        The row id the mint tried.
	 * @param string   $method_id The payment's method.
	 */
	public static function strand( WC_Order $order, string $id, string $method_id ): void {
		try {
			Ledger::instance()->write(
				$order->get_id(),
				static function ( Ledger_Draft $d ) use ( $id, $method_id ): bool {
					$entry = self::of( $d->order() )->get( $id );
					if ( null === $entry ) {
						self::open( $d, $id, $method_id );
					} elseif ( 'sent' === $entry['fate'] && null !== $entry['awaiting'] ) {
						$d->attempt( $id, 'sent', array( 'awaiting' => null ) );
					}
					return (bool) $d->attempts();
				},
				Ledger::MODE_HOLD
			);
		} catch ( \Throwable $error ) {
			// The mint's own error already names the payment for a human.
			Logger::warning( sprintf( 'WCPOS could not keep the unrecorded payment %s on order #%d for the resolver: %s', $id, $order->get_id(), $error->getMessage() ) );
		}
	}

	/** Arm the terminal-status listener. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * An order that awaits the customer and reaches a terminal status: nothing awaits them on a cancelled, refunded or
	 * trashed order, so a deferred write clears it (nothing saves inside the hook): only for the attempts awaiting
	 * now, and only while the order still has this status when it runs (a sale reopened and sent a new invoice
	 * before the request ends keeps that invoice). A paid status keeps it: the completion resolves the attempt in its
	 * S1 and clears what it awaited in S3.
	 *
	 * @param int           $id    Order ID.
	 * @param string        $from  Previous status.
	 * @param string        $to    New status.
	 * @param WC_Order|null $order Order object.
	 */
	public static function on_status_changed( $id, $from, $to, $order = null ): void {
		$id    = (int) $id;
		$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
		$to    = (string) $to;
		$seen  = $order instanceof WC_Order ? array_keys( self::clearing( $order ) ) : array();
		if ( ! in_array( $to, array( 'cancelled', 'refunded', 'trash' ), true ) || ! $seen ) {
			return;
		}
		Ledger::defer(
			$id,
			static function () use ( $id, $to, $seen ): void {
				Ledger::instance()->write(
					$id,
					static function ( Ledger_Draft $d ) use ( $to, $seen ): bool {
						$patches = $d->order()->has_status( $to ) ? array_intersect_key( self::clearing( $d->order() ), array_flip( $seen ) ) : array();
						foreach ( $patches as $attempt => $patch ) {
							$d->attempt( (string) $attempt, $patch['fate'], $patch );
						}
						return (bool) $patches; // False stages nothing: no save.
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
