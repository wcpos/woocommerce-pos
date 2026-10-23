<?php
/**
 * Writes the completion history of a sale.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;

/**
 * Appends one entry to `_wcpos_completions` each time the server completes a sale at a
 * register (ADR 0040 rule 1c): when, where and for what total. The pair (order id, n) is the
 * fiscal sale record's identity; money never reads it. A reopen marks the latest entry, so the
 * next completion appends; an unmarked latest entry means the sale is already complete for
 * this cycle, which is what makes every entry point idempotent. Readers ask Completion_History.
 */
final class Completion_Stamp {
	public const META_KEY              = '_wcpos_completions';
	public const COMPLETED_AT_META_KEY = '_wcpos_completed_at';

	/** Arm the one completion observer. */
	public static function register_hooks(): void {
		// Priority 5, before the passthrough's mint (status-changed and payment-complete 10): a reopen is marked now,
		// a completion outside the ledger is deferred to one write's S3. Nothing saves inside the hook for a completion.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 5, 4 );
	}

	/**
	 * Completion or reopen, observed on every status change.
	 *
	 * @param int           $id    Order ID.
	 * @param string        $from  Previous status.
	 * @param string        $to    New status.
	 * @param WC_Order|null $order Order object.
	 */
	public static function on_status_changed( $id, $from, $to, $order = null ): void {
		try {
			$id    = (int) $id;
			$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$completed = self::completed_statuses( $order );
			$reopened  = self::reopened_statuses();
			$into      = in_array( (string) $to, $completed, true );
			if ( $into && ! in_array( (string) $from, $completed, true ) ) {
				// The ledger's own write, and a submit's dispatch (its final write), complete in their own S3. Elsewhere only
				// a sale with a register origin completes: a sent submit's stamp, a captured row, a POS order's order-pay window.
				$origin = null !== Attempts::of( $order )->awaiting() || self::captured_ids( $order ) || ( Webview_Passthrough::is_paying( $id ) && wcpos_is_pos_order( $order ) );
				if ( $origin && ! Ledger::is_writing( $id ) && ! Gateway_Submission::is_submitting( $id ) ) {
					self::defer( $id, Webview_Passthrough::is_paying( $id ) );
				}
			} elseif ( ! $into && in_array( (string) $to, $reopened, true ) && ( ! in_array( (string) $from, $reopened, true ) || in_array( (string) $from, $completed, true ) ) ) {
				// A completed status that is also open (a merchant's POS status stored as on-hold) left for another open
				// status is a reopen of the standing completion too. A write's own reopen included.
				self::reopen( $order );
			}
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'WCPOS completion observer failed on order #%d: %s', (int) $id, $error->getMessage() ), array( 'order_id' => (int) $id ) );
		}
	}

	/**
	 * Complete a sale paid outside the ledger in one write's S3, after the order's other deferred work (a mint the
	 * completion must name) and once per flush: the paid attempt the stamp names is resolved to its row, and the
	 * write completes the sale if it is still paid with nothing standing.
	 *
	 * @param int  $order_id  Order ID.
	 * @param bool $order_pay Whether order-pay is paying the order (read once, where the transition was seen).
	 */
	public static function defer( int $order_id, bool $order_pay ): void {
		Ledger::defer(
			$order_id,
			static function () use ( $order_id, $order_pay ): void {
				$order = self::fresh( $order_id );
				if ( $order && null === Completion_History::of( $order )->standing() ) {
					Ledger::instance()->write(
						$order,
						static function ( Ledger_Draft $d ) use ( $order_pay ): bool {
							$d->completes_at( new Completion_Origin( array(), $order_pay ) );
							return Gateway_Submission::resolve_paid( $d );
						}
					);
				}
			},
			__( 'Recording the completion', 'woocommerce-pos' ),
			true
		);
	}

	/** Statuses a sale is reopened into (the observer marks the latest entry): the open ones, failed and cancelled. */
	private static function reopened_statuses(): array {
		return array_merge( Sale_State::OPEN_STATUSES, array( 'failed', 'cancelled' ) );
	}

	/**
	 * The standing completion, appended as completion n+1 when none stands (A1): its entry, its time and, once
	 * stored and read back (A3), `wcpos_sale_completed` under the order lock (A4). The caller decided that the
	 * sale completed (A5); S3 is the only production caller. The awaiting stamp goes in S3's cleanup.
	 *
	 * @param WC_Order          $order  Order object.
	 * @param Completion_Origin $origin Where the completion happened.
	 * @throws Ledger_Persistence_Exception With stage `completion` when the lock is taken or the entry is not stored.
	 */
	public static function append( WC_Order $order, Completion_Origin $origin ): array {
		$id   = $order->get_id();
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $id ) ) {
			throw Ledger_Persistence_Exception::at( 'completion', 'WCPOS completion could not take the order lock.' );
		}
		try {
			$fresh    = self::fresh( $id );
			$recorded = $fresh ? Completion_History::of( $fresh ) : null;
			if ( null === $recorded || null !== $recorded->standing() ) {
				return $recorded ? $recorded->standing() : array();
			}
			$history = $recorded->entries();
			if ( ! $history && ( new Fiscal_Record_Store() )->find_sale( $id ) ) {
				// Completed and reopened before the history existed: the earlier completion is entry 1.
				$history[] = self::synthesise( $fresh );
			}
			$n         = count( $history ) + 1;
			$from      = $origin->for_entry( $fresh, $n );
			$entry     = array(
				'id'              => wp_generate_uuid4(),
				'n'               => $n,
				'at_gmt'          => gmdate( 'c' ),
				'sale_time'       => $from['sale_time'],
				'sale_tz'         => $from['sale_tz'],
				'register'        => $from['register'],
				'session'         => $from['session'],
				'counter'         => $from['counter'],
				'total'           => Money::normalize( $fresh->get_total() ),
				'payment_ids'     => self::captured_ids( $fresh ),
				'reopened_at_gmt' => null,
			) + array_intersect_key( $from, array( 'cashier' => true ) );
			$history[] = $entry;
			if ( ! self::persist( $fresh, $history, $entry['at_gmt'] ) ) {
				/* translators: %d: completion number. */
				self::failed( $fresh, sprintf( __( 'WCPOS: completion %d could not be recorded; reconcile manually.', 'woocommerce-pos' ), $n ) );
				throw Ledger_Persistence_Exception::at( 'completion', esc_html( sprintf( 'WCPOS completion %d was not persisted.', $n ) ) );
			}
			// Fired under the order lock, so no other writer edits the sale before its record is built.
			try {
				/**
				 * Fires once per new completion entry, after it is persisted and while the order lock
				 * is held. The fiscal sale writer's trigger.
				 *
				 * @param WC_Order $order Order, read after the entry was stored.
				 * @param int      $n     Completion number.
				 */
				do_action( 'wcpos_sale_completed', $fresh, $n );
			} catch ( \Throwable $error ) {
				Logger::error( sprintf( 'WCPOS completion %d listener failed on order #%d: %s', $n, $id, $error->getMessage() ), array( 'order_id' => $id ) );
			}
			return $entry;
		} finally {
			$lock->release( $id );
		}
	}

	/**
	 * Mark the latest entry reopened, once (A2); synthesise the pre-deploy completion first when there is none.
	 * Synchronous and meta-only (H2), so the sale stops standing for the rest of the request. Never throws.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function reopen( WC_Order $order ): void {
		if ( Completion_History::of( $order )->is_empty() && ! self::legacy_candidate( $order ) ) {
			return;
		}
		$id   = $order->get_id();
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $id ) ) {
			self::failed( $order, __( 'WCPOS: the reopen could not be recorded on the completion history; reconcile manually.', 'woocommerce-pos' ) );
			return;
		}
		try {
			$fresh = self::fresh( $id );
			if ( ! $fresh ) {
				return;
			}
			$history = Completion_History::of( $fresh )->entries();
			if ( ! $history ) {
				if ( ! self::legacy_candidate( $fresh ) ) {
					return;
				}
				$history[] = self::synthesise( $fresh );
			} else {
				$last = count( $history ) - 1;
				if ( ! empty( $history[ $last ]['reopened_at_gmt'] ) ) {
					return;
				}
				$history[ $last ]['reopened_at_gmt'] = gmdate( 'c' );
			}
			if ( ! self::persist( $fresh, $history, null ) ) {
				self::failed( $fresh, __( 'WCPOS: the reopen could not be recorded on the completion history; reconcile manually.', 'woocommerce-pos' ) );
			}
		} finally {
			$lock->release( $id );
		}
	}

	/**
	 * A paid order with no history that completed at a register before the history existed.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function legacy_candidate( WC_Order $order ): bool {
		return null !== $order->get_date_paid( 'edit' )
			&& ( wcpos_is_pos_order( $order ) || self::captured_ids( $order ) || ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
	}

	/**
	 * Entry 1 for an order completed before this shipped, marked reopened. Its id is the digest of its
	 * fiscal sale row when there is one, so the fiscal writer finds that row as the predecessor.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function synthesise( WC_Order $order ): array {
		$sale  = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$paid  = $order->get_date_paid( 'edit' );
		$till  = Completion_Origin::till_tuple( $order );
		$legs  = Completion_Origin::latest_captured( $order );
		$total = $sale['payload']['totals']['total_incl'] ?? null;
		// Per field: the fiscal row, then the till tuple, then (register and session only) the latest
		// captured leg, so a row that never recorded a session does not blank the one the till sent.
		$pick = static fn( ...$values ) => array_values( array_filter( $values, static fn( $value ) => null !== $value && '' !== $value ) )[0] ?? null;
		return array(
			'id'              => $sale ? Fiscal_Record_Store::digest_source_id( 'sale:' . $sale['id'] ) : wp_generate_uuid4(),
			'n'               => 1,
			'legacy'          => true,
			'at_gmt'          => $sale ? gmdate( 'c', (int) strtotime( $sale['received_at_gmt'] . ' UTC' ) ) : ( $paid ? gmdate( 'c', $paid->getTimestamp() ) : gmdate( 'c' ) ),
			'sale_time'       => $pick( $sale['device_time'] ?? null, $till['sale_time'] ),
			'sale_tz'         => $pick( $sale['device_tz'] ?? null, $till['sale_tz'] ),
			'register'        => $pick( $sale['register_id'] ?? null, $till['register'], $legs['register_id'] ?? null ),
			'session'         => $pick( $sale['session_id'] ?? null, $till['session'], $legs['session_id'] ?? null ),
			'counter'         => $till['counter'],
			'total'           => is_numeric( $total ) ? Money::normalize( $total ) : Money::normalize( $order->get_total() ),
			'payment_ids'     => self::captured_ids( $order ),
			'reopened_at_gmt' => gmdate( 'c' ),
		);
	}

	/**
	 * Paid for the stamp: the status is in the completed set (WooCommerce's paid statuses plus the
	 * per-gateway POS status a merchant may have stored as a non-core status) and either `date_paid`
	 * is set or the status is one WooCommerce itself calls paid. A gateway that lands `processing`
	 * while the stored POS status is `completed` gets no `date_paid` from WooCommerce, yet took the
	 * money; an unpaid write under Paid_Date_Suppression lands a non-paid status with no `date_paid`
	 * and is never a completion.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function is_paid( WC_Order $order ): bool {
		$status = $order->get_status();
		return in_array( $status, self::completed_statuses( $order ), true )
			&& ( null !== $order->get_date_paid( 'edit' ) || in_array( $status, wc_get_is_paid_statuses(), true ) );
	}

	/**
	 * WooCommerce's paid statuses and the status payment_complete() lands this order on.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function completed_statuses( WC_Order $order ): array {
		$statuses   = wc_get_is_paid_statuses();
		$configured = (string) apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		if ( '' !== $configured ) {
			$statuses[] = 'wc-' === substr( $configured, 0, 3 ) ? substr( $configured, 3 ) : $configured;
		}
		return $statuses;
	}

	/**
	 * Captured row ids on the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string[]
	 */
	private static function captured_ids( WC_Order $order ): array {
		$ids = array();
		foreach ( Ledger::instance()->read( $order ) as $row ) {
			if ( 'captured' === ( $row['status'] ?? '' ) && is_string( $row['id'] ?? null ) ) {
				$ids[] = $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * A fresh read of the order and its meta.
	 *
	 * @param int $id Order ID.
	 */
	private static function fresh( int $id ): ?WC_Order {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * Store the history and read it back from storage.
	 *
	 * @param WC_Order    $order        Fresh order.
	 * @param array       $history      Entries, oldest first.
	 * @param string|null $completed_at The new completion's time, or null to leave it.
	 */
	private static function persist( WC_Order $order, array $history, ?string $completed_at ): bool {
		$json = wp_json_encode( array_values( $history ) );
		$order->update_meta_data( self::META_KEY, $json );
		if ( null !== $completed_at ) {
			$order->update_meta_data( self::COMPLETED_AT_META_KEY, $completed_at );
		}
		Ledger::save_meta( $order );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( self::META_KEY === $meta->meta_key && maybe_unserialize( $meta->meta_value ) === $json ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Log and note a completion the history could not record.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $message Note text.
	 */
	private static function failed( WC_Order $order, string $message ): void {
		Logger::error( sprintf( '%s Order #%d.', $message, $order->get_id() ), array( 'order_id' => $order->get_id() ) );
		try {
			$order->add_order_note( $message );
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'WCPOS could not add the completion note on order #%d: %s', $order->get_id(), $error->getMessage() ) );
		}
	}
}
