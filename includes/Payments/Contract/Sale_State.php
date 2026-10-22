<?php
/**
 * What may happen to a Sale now, and what money is in motion on it.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

use WC_Order;

/**
 * A snapshot of one order, read once by {@see Ledger::sale_state()}: its payment rows, its gateway
 * attempts and awaiting-customer stamp, whether its completion stands, `is_editable()`, its status
 * and `date_paid`. The push lane and the money-command routes each translate it into their own
 * refusals (ADR 0040 rules 3 and 4).
 *
 * The snapshot is valid until the order is next saved: take it on the locked read and never reuse
 * it across a save.
 */
final class Sale_State {
	/** The statuses a sale is open in: it takes lines, tender and a close. */
	public const OPEN_STATUSES = array( 'pos-open', 'pos-partial', 'pending', 'on-hold' );

	/**
	 * The order read; kept for has_money()'s lazy refunds query.
	 *
	 * @var WC_Order
	 */
	private WC_Order $order;

	/**
	 * Payment rows.
	 *
	 * @var array
	 */
	private array $rows;

	/**
	 * Gateway attempt id => state.
	 *
	 * @var array<string, string>
	 */
	private array $attempts;

	/**
	 * The awaiting-customer stamp.
	 *
	 * @var array|null
	 */
	private ?array $stamp;

	/**
	 * Whether the latest completion stands.
	 *
	 * @var bool
	 */
	private bool $stands;

	/**
	 * WooCommerce's is_editable().
	 *
	 * @var bool
	 */
	private bool $editable;

	/**
	 * Order status, without the `wc-` prefix.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Whether `date_paid` is set.
	 *
	 * @var bool
	 */
	private bool $dated_paid;

	/**
	 * Memoised has_money().
	 *
	 * @var bool|null
	 */
	private ?bool $has_money = null;

	/**
	 * Read the order once.
	 *
	 * @internal Built by {@see Ledger::sale_state()}.
	 *
	 * @param WC_Order $order The order, read under the caller's order lock.
	 * @param array    $rows  Its payment rows, as {@see Ledger::read()} returns them.
	 */
	public function __construct( WC_Order $order, array $rows ) {
		$this->order      = $order;
		$this->rows       = $rows;
		$this->attempts   = Gateway_Submission::read_attempts( $order );
		$this->stamp      = Gateway_Submission::read_stamp( $order );
		$this->stands     = Completion_Stamp::stands( $order );
		$this->editable   = $order->is_editable();
		$this->status     = (string) $order->get_status();
		$this->dated_paid = null !== $order->get_date_paid( 'edit' );
	}

	/** Whether the latest completion stands: it has not been reopened. */
	public function stands(): bool {
		return $this->stands;
	}

	/**
	 * Whether the sale's lines are locked: WooCommerce's is_editable() refuses them, or a completion
	 * stands on a status that is_editable() allows (a merchant's POS status stored as on-hold).
	 */
	public function locked(): bool {
		return ! $this->editable || $this->stands;
	}

	/** Whether the sale is in an open status. */
	public function open(): bool {
		return in_array( $this->status, self::OPEN_STATUSES, true );
	}

	/**
	 * Whether moving to the status reopens the sale: a locked sale into another open status (D3).
	 *
	 * @param string $to The requested status.
	 */
	public function reopens( string $to ): bool {
		return in_array( $to, self::OPEN_STATUSES, true ) && $to !== $this->status && $this->locked();
	}

	/**
	 * Whether a new tender row may be accepted (spec §4.2): the completion does not stand and the
	 * sale is editable, in progress, or awaiting a sent submit.
	 */
	public function takes_tender(): bool {
		return ! $this->stands && ( $this->editable || in_array( $this->status, Ledger::IN_PROGRESS_STATUSES, true ) || null !== $this->stamp );
	}

	/** Whether the sale was paid: its completion stands, or `date_paid` is set. */
	public function already_paid(): bool {
		return $this->stands || $this->dated_paid;
	}

	/**
	 * The payment or attempt still in motion, or null: the first `pending` or `authorized` row, or a
	 * row with a `pending` refund allocation; else the `sent` gateway attempt, or the awaiting stamp's
	 * attempt. Captured, failed and voided rows are never in motion.
	 *
	 * @return array{payment_id: ?string, attempt_id: mixed}|null `payment_id` is set for a row; for an
	 *         attempt it is null and `attempt_id` is the attempt (null when the stamp names none).
	 */
	public function in_motion(): ?array {
		foreach ( $this->rows as $row ) {
			$pending_refund = array_filter( is_array( $row['refunds'] ?? null ) ? $row['refunds'] : array(), static fn( $allocation ) => 'pending' === ( $allocation['status'] ?? '' ) );
			if ( in_array( $row['status'] ?? '', array( 'pending', 'authorized' ), true ) || $pending_refund ) {
				return array(
					'payment_id' => (string) ( $row['id'] ?? '' ),
					'attempt_id' => null,
				);
			}
		}
		$sent = array_search( 'sent', $this->attempts, true );
		if ( false !== $sent || null !== $this->stamp ) {
			return array(
				'payment_id' => null,
				'attempt_id' => false !== $sent ? $sent : ( $this->stamp['attempt_id'] ?? null ),
			);
		}
		return null;
	}

	/**
	 * Whether any money exists on the order: a ledger row, a gateway attempt or the awaiting-customer
	 * stamp, or WooCommerce's own paid facts for an order a gateway or an earlier version paid
	 * (`date_paid`, a paid status, a transaction id, a refund). The refunds query runs once.
	 */
	public function has_money(): bool {
		if ( null === $this->has_money ) {
			$this->has_money = $this->rows || $this->attempts || null !== $this->stamp || $this->dated_paid || $this->order->is_paid()
				|| '' !== (string) $this->order->get_transaction_id( 'edit' ) || array() !== $this->order->get_refunds();
		}
		return $this->has_money;
	}
}
