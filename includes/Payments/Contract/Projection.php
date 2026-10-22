<?php
/**
 * What a sale's payment rows say about its WooCommerce order.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/**
 * The pure half of `Ledger::derive()`: rows and the order's facts in, the order's payment fields
 * and whether the sale completes out. It reads nothing and writes nothing; `Ledger::write()`
 * applies the answer (S1) and runs the paid transition when it completes (S2).
 */
final class Projection {
	/**
	 * Project rows onto the order.
	 *
	 * @param array         $rows     Payment rows.
	 * @param string        $total    Order total.
	 * @param string        $status   Order status, without `wc-`.
	 * @param bool          $stamped  Whether a sent submit awaits the customer (the order is POS-owned whatever its status).
	 * @param string        $mode     `Ledger::MODE_AUTO`, `MODE_HOLD` (no projection) or `MODE_CLOSE`.
	 * @param bool          $editable Whether WooCommerce calls the order editable (only `close` completes an on-hold order).
	 * @param callable|null $title    Method id to title; the id itself when null.
	 * @return array{status: ?string, method: ?string, title: ?string, txn: ?string, completes: bool} Null leaves the order's own.
	 */
	public static function of( array $rows, string $total, string $status, bool $stamped, string $mode, bool $editable = false, ?callable $title = null ): array {
		$answer = array_fill_keys( array( 'status', 'method', 'title', 'txn' ), null ) + array( 'completes' => false );
		if ( Ledger::MODE_HOLD === $mode ) {
			return $answer;
		}
		$closing  = Ledger::MODE_CLOSE === $mode;
		$counting = array_values( array_filter( $rows, static fn( $row ) => in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) ) );
		$paying   = array_values( array_filter( $counting, static fn( $row ) => 'stored_value' !== ( $row['kind'] ?? '' ) ) );
		// The paying row: the largest, then the earliest captured (a missing time sorts last).
		$time     = static fn( $row ) => is_string( $row['captured_at_gmt'] ?? null ) && false !== strtotime( $row['captured_at_gmt'] ) ? strtotime( $row['captured_at_gmt'] ) : PHP_INT_MAX;
		$selected = null;
		foreach ( $paying ? $paying : $counting as $row ) {
			$amount = Money::minor( $row['amount'] );
			if ( null === $selected || $amount > Money::minor( $selected['amount'] ) || ( Money::minor( $selected['amount'] ) === $amount && $time( $row ) < $time( $selected ) ) ) {
				$selected = $row;
			}
		}
		// No counting row leaves method and title untouched (the cart, or every leg voided).
		if ( $selected ) {
			$titles = array();
			foreach ( $counting as $row ) {
				$titles[] = $title ? (string) $title( $row['method_id'] ) : (string) $row['method_id'];
			}
			$refs             = is_array( $selected['provider_refs'] ?? null ) ? $selected['provider_refs'] : array();
			$answer['method'] = (string) $selected['method_id'];
			$answer['title']  = implode( ' + ', array_unique( $titles ) );
			$answer['txn']    = (string) ( $refs['payment_intent'] ?? $refs['transaction_id'] ?? '' );
		}
		// Only the ledger-managed states are projected: a completed, processing, on-hold or refunded
		// order is never pulled back by a later write, unless a sent submit owns it or `close` asks.
		if ( ! in_array( $status, Ledger::IN_PROGRESS_STATUSES, true ) && ! $stamped && ! ( $closing && $editable ) ) {
			return $answer;
		}
		$paid     = array_sum( array_map( static fn( $row ) => Money::minor( $row['amount'] ), $counting ) );
		$refunded = 0;
		foreach ( $rows as $row ) {
			foreach ( is_array( $row['refunds'] ?? null ) ? $row['refunds'] : array() as $allocation ) {
				$refunded += 'succeeded' === ( $allocation['status'] ?? '' ) ? Money::minor( $allocation['amount'] ?? 0 ) : 0;
			}
		}
		// Covered on the signed balance (rule 1): a refund after an earlier completion is money that
		// left. Only `close` completes a zero total, as WooCommerce's process_order_without_payment() does.
		if ( ( Money::minor( $total ) > 0 || $closing ) && Money::minor( $total ) - $paid + $refunded <= 0 ) {
			$answer['completes'] = true;
			return $answer;
		}
		if ( $selected || ! $stamped ) {
			$pending          = (bool) array_filter( $rows, static fn( $row ) => 'pending' === ( $row['status'] ?? '' ) );
			$answer['status'] = $pending ? 'pending' : ( $paid > 0 ? 'pos-partial' : 'pos-open' );
		}
		return $answer;
	}
}
