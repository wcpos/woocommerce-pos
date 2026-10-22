<?php
/**
 * The completion history of a sale.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;

/**
 * Appends one entry to `_wcpos_completions` each time the server completes a sale at a
 * register (ADR 0040 rule 1c): when, where and for what total. The pair (order id, n) is the
 * fiscal sale record's identity; money never reads it. A reopen marks the latest entry, so the
 * next completion appends; an unmarked latest entry means the sale is already complete for
 * this cycle, which is what makes every entry point idempotent.
 */
final class Completion_Stamp {
	public const META_KEY              = '_wcpos_completions';
	public const COMPLETED_AT_META_KEY = '_wcpos_completed_at';

	/** Statuses a sale is reopened into (the observer marks the latest entry). */
	private const OPEN_STATUSES = array( 'pos-open', 'pos-partial', 'pending', 'on-hold', 'failed', 'cancelled' );

	/** Entry field => [ request body field, till meta key whose validation it borrows ]. */
	private const PROVENANCE_FIELDS = array(
		'register'  => array( 'register_id', '_wcpos_register' ),
		'session'   => array( 'session_id', '_wcpos_session' ),
		'sale_time' => array( 'sale_time', '_wcpos_sale_time' ),
		'sale_tz'   => array( 'sale_tz', '_wcpos_sale_tz' ),
		'counter'   => array( 'sale_counter', '_wcpos_sale_counter' ),
	);

	/**
	 * The money command running in this request: its order id and validated provenance; null outside one.
	 *
	 * @var array{order_id: int, provenance: array}|null
	 */
	private static $context = null;

	/**
	 * A sent submit's awaiting-customer stamp, read before the stamp is cleared: its provenance and
	 * the attempt, method and cashier its row is minted with when no gateway mints one.
	 *
	 * @var array<int, array{provenance: array, attempt_id: string, method_id: string, cashier_id: int}>
	 */
	private static $attempts = array();

	/**
	 * Orders whose payment_complete() is running: the passthrough mints their row at payment-complete 10.
	 *
	 * @var array<int, bool>
	 */
	private static $completing = array();

	/** Arm the completion entry points. */
	public static function register_hooks(): void {
		// Priority 5: memoise a sent submit's provenance before Gateway_Submission clears the stamp
		// (10), and mark a reopen.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 5, 4 );
		// Priority 15: stamp a completion after the webview's status-only mint (10), so the entry
		// names the row; still before every woocommerce_payment_complete listener.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_completed' ), 15, 4 );
		// Priority 15: after Webview_Passthrough has minted an order-pay row (10).
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 15, 1 );
		add_action( 'woocommerce_pre_payment_complete', array( __CLASS__, 'on_pre_payment_complete' ), 10, 1 );
	}

	/**
	 * Note that payment_complete() is running on the order (its status changes before its mint).
	 *
	 * @param int $id Order ID.
	 */
	public static function on_pre_payment_complete( $id ): void {
		self::$completing[ (int) $id ] = true;
		// WooCommerce's no-op branch (a status payment_complete() refuses) fires
		// woocommerce_payment_complete_order_status_{status} instead: clear the flag there too.
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			add_action( 'woocommerce_payment_complete_order_status_' . ( 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status ), array( __CLASS__, 'on_payment_completed' ), 15, 1 );
		}
	}

	/**
	 * The order's payment_complete() has finished, whichever branch it took.
	 *
	 * @param int $id Order ID.
	 */
	public static function on_payment_completed( $id ): void {
		unset( self::$completing[ (int) $id ] );
	}

	/**
	 * Run a money command with its provenance as the request context.
	 *
	 * @param array    $provenance Body `provenance` object (register_id, session_id, sale_time, sale_tz, sale_counter).
	 * @param callable $work       The command.
	 * @param int      $order_id   The order the command acts on: the only order it is a register origin for.
	 * @return mixed
	 */
	public static function with_provenance( array $provenance, callable $work, int $order_id ) {
		$previous      = self::$context;
		self::$context = array(
			'order_id'   => $order_id,
			'provenance' => self::validated( $provenance ),
		);
		try {
			return $work();
		} finally {
			self::$context = $previous;
		}
	}

	/**
	 * The validated provenance of the command in flight on this order, entry-shaped; empty otherwise.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function provenance( int $order_id ): array {
		return self::in_command( $order_id ) ? self::$context['provenance'] : array();
	}

	/**
	 * Whether a money command on this order is running in this request.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function in_command( int $order_id ): bool {
		return null !== self::$context && 0 !== $order_id && self::$context['order_id'] === $order_id;
	}

	/** Test seam: forget the request context and the memoised attempt provenance. */
	public static function reset(): void {
		self::$context    = null;
		self::$attempts   = array();
		self::$completing = array();
	}

	/**
	 * The sent attempt remembered for the order in this request (attempt_id, method_id, cashier_id,
	 * provenance), or null.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function attempt( int $order_id ): ?array {
		return self::$attempts[ $order_id ] ?? null;
	}

	/**
	 * Remember the provenance a sent submit left on the order before the stamp is cleared.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function memoise( WC_Order $order ): void {
		$stamp = Gateway_Submission::read_stamp( $order );
		if ( is_string( $stamp['attempt_id'] ?? null ) && wp_is_uuid( $stamp['attempt_id'] ) ) {
			self::$attempts[ $order->get_id() ] = array(
				'provenance' => is_array( $stamp['provenance'] ?? null ) ? self::validated( $stamp['provenance'], true ) : array(),
				'attempt_id' => strtolower( $stamp['attempt_id'] ),
				'method_id'  => (string) ( $stamp['method_id'] ?? '' ),
				'cashier_id' => (int) ( $stamp['cashier_id'] ?? 0 ),
			);
		}
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
			$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$completed = self::completed_statuses( $order );
			if ( in_array( (string) $to, $completed, true ) && ! in_array( (string) $from, $completed, true ) ) {
				self::memoise( $order );
			} elseif ( in_array( (string) $to, $completed, true ) ) {
				return; // Completed to completed (processing to a configured on-hold): the sale still stands.
			} elseif ( in_array( (string) $to, self::OPEN_STATUSES, true ) && ! in_array( (string) $from, self::OPEN_STATUSES, true ) ) {
				self::mark_reopened( $order );
			} elseif ( in_array( (string) $to, self::OPEN_STATUSES, true ) && in_array( (string) $from, $completed, true ) ) {
				// A completed status that is also open (a merchant's POS status stored as on-hold) is
				// left for another open status: that is a reopen of the standing completion too.
				self::mark_reopened( $order );
			}
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'WCPOS completion observer failed on order #%d: %s', (int) $id, $error->getMessage() ), array( 'order_id' => (int) $id ) );
		}
	}

	/**
	 * Stamp a transition into a completed status (priority 15, after the webview's status mint).
	 *
	 * @param int           $id    Order ID.
	 * @param string        $from  Previous status.
	 * @param string        $to    New status.
	 * @param WC_Order|null $order Order object.
	 */
	public static function on_completed( $id, $from, $to, $order = null ): void {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$completed = self::completed_statuses( $order );
			if ( in_array( (string) $to, $completed, true ) && ! in_array( (string) $from, $completed, true ) ) {
				self::stamp( $order );
			}
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'WCPOS completion observer failed on order #%d: %s', (int) $id, $error->getMessage() ), array( 'order_id' => (int) $id ) );
		}
	}

	/**
	 * A completion whose register origin appeared only at payment-complete (an asynchronous order-pay mint).
	 *
	 * @param int $id Order ID.
	 */
	public static function on_payment_complete( $id ): void {
		self::on_payment_completed( $id );
		try {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof WC_Order ) {
				self::stamp( $order );
			}
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'WCPOS completion observer failed on order #%d: %s', (int) $id, $error->getMessage() ), array( 'order_id' => (int) $id ) );
		}
	}

	/**
	 * Append the next entry when the order is paid at a register and not already complete this cycle.
	 * Never throws; a failure is logged and noted on the order for a human.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function stamp( WC_Order $order ): void {
		$id = $order->get_id();
		if ( ! self::is_paid( $order ) || ! self::has_origin( $order ) ) {
			return;
		}
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $id ) ) {
			self::failed( $order, __( 'WCPOS: completion could not be recorded; reconcile manually.', 'woocommerce-pos' ) );
			return;
		}
		try {
			$fresh = self::fresh( $id );
			if ( $fresh && self::is_paid( $fresh ) && self::mints_attempt( $fresh ) ) {
				$fresh = self::fresh( $id );
			}
			// The window test reads the fresh order: a mint earlier in this save is not on the caller's object.
			if ( ! $fresh || ! self::is_paid( $fresh ) || ! self::has_origin( $fresh ) || self::awaits_mint( $fresh ) ) {
				return;
			}
			$history = self::read( $fresh );
			$latest  = end( $history );
			if ( is_array( $latest ) && empty( $latest['reopened_at_gmt'] ) ) {
				return; // Already complete for this cycle.
			}
			if ( ! $history && ( new Fiscal_Record_Store() )->find_sale( $id ) ) {
				// Completed and reopened before this shipped: the earlier completion is entry 1.
				$history[] = self::synthesise( $fresh );
			}
			$n          = count( $history ) + 1;
			$provenance = self::resolve_provenance( $fresh, $n );
			$entry      = array(
				'id'              => wp_generate_uuid4(),
				'n'               => $n,
				'at_gmt'          => gmdate( 'c' ),
				'sale_time'       => $provenance['sale_time'],
				'sale_tz'         => $provenance['sale_tz'],
				'register'        => $provenance['register'],
				'session'         => $provenance['session'],
				'counter'         => $provenance['counter'],
				'total'           => Money::normalize( $fresh->get_total() ),
				'payment_ids'     => self::captured_ids( $fresh ),
				'reopened_at_gmt' => null,
			);
			$history[] = $entry;
			if ( ! self::persist( $fresh, $history, $entry['at_gmt'] ) ) {
				/* translators: %d: completion number. */
				self::failed( $fresh, sprintf( __( 'WCPOS: completion %d could not be recorded; reconcile manually.', 'woocommerce-pos' ), $n ) );
				return;
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
				do_action( 'wcpos_sale_completed', $fresh, $n ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract action.
			} catch ( \Throwable $error ) {
				Logger::error( sprintf( 'WCPOS completion %d listener failed on order #%d: %s', $n, $id, $error->getMessage() ), array( 'order_id' => $id ) );
			}
		} finally {
			$lock->release( $id );
		}
	}

	/**
	 * The order's completion history, oldest first.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function read( WC_Order $order ): array {
		$raw     = $order->get_meta( self::META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	/**
	 * The latest completion entry while it stands; null when the sale has none or was reopened.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function standing( WC_Order $order ): ?array {
		$history = self::read( $order );
		$latest  = end( $history );
		return is_array( $latest ) && empty( $latest['reopened_at_gmt'] ) ? $latest : null;
	}

	/**
	 * The order as this completion saw it: an in-memory copy whose till tuple carries the entry's
	 * register, session, sale time, zone and counter (an absent value stays absent), so a receipt or
	 * fiscal document built from it names the register that completed the sale, not the first one.
	 * Never saved.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $completion Completion entry.
	 */
	public static function as_completed( WC_Order $order, array $completion ): WC_Order {
		$copy = clone $order;
		// WC_Abstract_Order::__clone() is empty, so a clone shares its meta objects with the order:
		// give the copy its own before changing any of them.
		$copy->read_meta_data( true );
		foreach ( array(
			'register'  => '_wcpos_register',
			'session'   => '_wcpos_session',
			'sale_time' => '_wcpos_sale_time',
			'sale_tz'   => '_wcpos_sale_tz',
			'counter'   => '_wcpos_sale_counter',
		) as $field => $key ) {
			if ( null === ( $completion[ $field ] ?? null ) ) {
				$copy->delete_meta_data( $key );
			} else {
				$copy->update_meta_data( $key, (string) $completion[ $field ] );
			}
		}
		return $copy;
	}

	/**
	 * Whether the sale's completion stands: its latest entry is not marked reopened, or, before the
	 * history existed, it is paid in a completed status. A standing sale takes no new tender, whatever
	 * WooCommerce's is_editable() says of its status (a merchant's POS status stored as on-hold).
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function stands( WC_Order $order ): bool {
		$history = self::read( $order );
		$latest  = end( $history );
		if ( is_array( $latest ) ) {
			return empty( $latest['reopened_at_gmt'] );
		}
		return null !== $order->get_date_paid( 'edit' ) && in_array( $order->get_status(), self::completed_statuses( $order ), true );
	}

	/**
	 * Whether a captured row id was counted by any completion (it then leaves only through a refund).
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $payment_id Payment row id.
	 */
	public static function counted( WC_Order $order, string $payment_id ): bool {
		foreach ( self::read( $order ) as $entry ) {
			if ( in_array( strtolower( $payment_id ), array_map( 'strtolower', (array) ( $entry['payment_ids'] ?? array() ) ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark the latest entry reopened; synthesise the pre-deploy completion first when there is none.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function mark_reopened( WC_Order $order ): void {
		if ( ! self::read( $order ) && ! self::legacy_candidate( $order ) ) {
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
			$history = self::read( $fresh );
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
		$till  = self::till_tuple( $order );
		$legs  = self::latest_captured( $order );
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
	private static function is_paid( WC_Order $order ): bool {
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
	 * Whether this completion happened at a register: a money command in this request, the ledger
	 * deriving, a gateway submit, a POS order's order-pay window, a sent submit's provenance, or a
	 * captured ledger row. A storefront payment has none and is never stamped.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function has_origin( WC_Order $order ): bool {
		$id = $order->get_id();
		return self::in_command( $id )
			|| Ledger::is_deriving( $id )
			|| Gateway_Submission::is_submitting( $id )
			|| ( Webview_Passthrough::is_paying( $id ) && wcpos_is_pos_order( $order ) )
			|| isset( self::$attempts[ $id ] )
			|| self::captured_ids( $order );
	}

	/**
	 * A sent attempt the merchant (or a provider) marked paid without payment_complete() has no row:
	 * nothing else will mint one outside a window. Mint it as the submit does for a paying gateway,
	 * the attempt id as the row id, so the completion names the payment; Ledger::save() marks the
	 * attempt paid. Inside payment_complete() the passthrough mints at payment-complete 10 instead.
	 *
	 * @param WC_Order $order Fresh order, paid, read under the lock.
	 * @return bool Whether a row was minted.
	 */
	private static function mints_attempt( WC_Order $order ): bool {
		$id      = $order->get_id();
		$attempt = self::$attempts[ $id ] ?? null;
		if ( null === $attempt || isset( self::$completing[ $id ] ) || Gateway_Submission::is_submitting( $id ) || Webview_Passthrough::is_paying( $id ) || self::captured_ids( $order ) ) {
			return false;
		}
		$transaction = $order->get_transaction_id();
		try {
			$row = Webview_Passthrough::mint(
				$order,
				array(
					'id'            => $attempt['attempt_id'],
					'source'        => 'app',
					'capture_mode'  => 'gateway',
					'method_id'     => '' !== $attempt['method_id'] ? $attempt['method_id'] : $order->get_payment_method(),
					'provider_refs' => array( 'transaction_id' => '' !== $transaction ? $transaction : null ),
					'cashier_id'    => $attempt['cashier_id'],
				)
			);
		} catch ( \Throwable $error ) {
			$row = null;
		}
		if ( null === $row ) {
			Logger::error( sprintf( 'WCPOS attempt %s on order #%d was marked paid but its row could not be minted; the completion waits for it.', $attempt['attempt_id'], $id ), array( 'order_id' => $id ) );
			return false;
		}
		return true;
	}

	/**
	 * Inside a submit or order-pay window, or for a sent attempt a later callback completes, the
	 * tender's row is minted after the status lands: the stamp waits for it (the submit stamps after
	 * its mint, the webview at payment-complete 15 or status-change 15), so the entry and its sale
	 * record name the payment.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function awaits_mint( WC_Order $order ): bool {
		$id = $order->get_id();
		return ( Gateway_Submission::is_submitting( $id ) || Webview_Passthrough::is_paying( $id ) || isset( self::$attempts[ $id ] ) ) && ! self::captured_ids( $order );
	}

	/**
	 * Provenance for completion n, from the first source that names any of it: the command's body,
	 * then a sent submit's stored provenance, then (completion 1 and the order-pay webview only) the
	 * write-once till tuple. Failing all three, the register and session of the latest captured row,
	 * the register that last took money. Nothing is invented: an absent value stays null.
	 *
	 * @param WC_Order $order Order object.
	 * @param int      $n     Completion number.
	 */
	private static function resolve_provenance( WC_Order $order, int $n ): array {
		$empty   = array_fill_keys( array_keys( self::PROVENANCE_FIELDS ), null );
		$sources = array( self::provenance( $order->get_id() ), self::$attempts[ $order->get_id() ]['provenance'] ?? array() );
		if ( 1 === $n || Webview_Passthrough::is_paying( $order->get_id() ) ) {
			$sources[] = array_filter( self::till_tuple( $order ), static fn( $value ) => null !== $value );
		}
		foreach ( $sources as $source ) {
			if ( $source ) {
				return array_merge( $empty, $source );
			}
		}
		$latest            = self::latest_captured( $order );
		$empty['register'] = $latest['register_id'] ?? null;
		$empty['session']  = $latest['session_id'] ?? null;
		return $empty;
	}

	/**
	 * The most recently captured row on the order, if any: the register that last took money.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function latest_captured( WC_Order $order ): ?array {
		$latest = null;
		foreach ( Ledger::instance()->read( $order ) as $row ) {
			if ( 'captured' === ( $row['status'] ?? '' ) && ( null === $latest || strcmp( (string) ( $row['captured_at_gmt'] ?? '' ), (string) ( $latest['captured_at_gmt'] ?? '' ) ) >= 0 ) ) {
				$latest = $row;
			}
		}
		return $latest;
	}

	/**
	 * The write-once till tuple, entry-shaped.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function till_tuple( WC_Order $order ): array {
		$values = array();
		foreach ( self::PROVENANCE_FIELDS as $field => $keys ) {
			$values[ $field ] = $order->get_meta( $keys[1], true );
		}
		return array_merge( array_fill_keys( array_keys( self::PROVENANCE_FIELDS ), null ), self::validated( $values, true ) );
	}

	/**
	 * Keep each provenance field only when it passes the till meta's own validation; drop the rest.
	 *
	 * @param array $provenance Body-shaped provenance, or entry-shaped when $entry_shaped.
	 * @param bool  $entry_shaped Whether the keys are already the entry's.
	 */
	private static function validated( array $provenance, bool $entry_shaped = false ): array {
		$valid = array();
		foreach ( self::PROVENANCE_FIELDS as $field => $keys ) {
			$value = $provenance[ $entry_shaped ? $field : $keys[0] ] ?? null;
			if ( Pos_Order_Audit::is_valid_till_value( $keys[1], $value ) ) {
				$valid[ $field ] = in_array( $field, array( 'register', 'session' ), true ) ? strtolower( (string) $value ) : (string) $value;
			}
		}
		return $valid;
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
		Ledger::saving( $order->get_id(), fn() => $order->save_meta_data() );
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
