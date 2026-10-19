<?php
/**
 * Epoch-scoped completion evidence, independent of the sync journal epoch.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

// phpcs:disable Squiz.Commenting -- Compact contract helpers.
namespace WCPOS\WooCommercePOS\Payments\Contract;

use WC_Order;

final class Money_Epoch {
	public static function current( WC_Order $order ): int {
		return self::epoch( $order->get_meta( '_wcpos_money_epoch' ) );
	}

	private static function epoch( $value ): int {
		return ( is_int( $value ) || is_string( $value ) ) && false !== filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) ) ? (int) $value : 0;
	}

	public static function completion_stamp( WC_Order $order ): ?array {
		$at = $order->get_meta( '_wcpos_completed_at' );
		$session = $order->get_meta( '_wcpos_completed_session' );
		return is_string( $at ) && '' !== $at ? array(
			'completed_at' => $at,
			'epoch' => self::epoch( $order->get_meta( '_wcpos_completed_epoch' ) ),
			'session' => '' === $session ? null : $session,
		) : null;
	}

	/** One reader for legacy and epoch-bearing attempt history. */
	public static function attempt_entries( WC_Order $order ): array {
		return Gateway_Submission::read_attempts( $order );
	}

	/** Rows belonging to this sale, excluding retained history from earlier epochs. */
	public static function current_rows( WC_Order $order, array $rows ): array {
		$epoch = self::current( $order );
		return array_filter( $rows, static fn( $row ) => self::epoch( $row['epoch'] ?? 0 ) === $epoch );
	}

	/** Counting-row net paid, in the ledger's integer minor units. */
	public static function net_paid( array $rows ): int {
		$paid = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) ) {
				$paid += Money::minor( $row['amount'] ?? 0 ) - Money::minor( $row['refunded_amount'] ?? 0 );
			}
		}
		return $paid;
	}

	public static function is_completed_in_current_epoch( WC_Order $order, Ledger $ledger ): bool {
		$epoch = self::current( $order );
		$stamp = self::completion_stamp( $order );
		if ( $stamp && $stamp['epoch'] === $epoch ) {
			return true;
		}
		if ( self::ledger_completed( $order, $ledger ) ) {
			return true;
		}
		foreach ( self::attempt_entries( $order ) as $id => $attempt ) {
			if ( $attempt['epoch'] === $epoch && ( 'settled' === $attempt['state'] || ( 'paid' === $attempt['state'] && self::represented( $order, $ledger, (string) $id, true ) ) ) ) {
				return true;
			}
		}
		return self::legacy_completed( $order );
	}
	/** An attempt must retain its counting money in this epoch. */
	private static function represented( WC_Order $order, Ledger $ledger, string $id, bool $paid = false ): bool {
		// A sent invoice can be paid by separate tenders covering this sale (save's promotion).
		if ( $paid && self::ledger_completed( $order, $ledger ) ) {
			return true;
		}
		foreach ( self::current_rows( $order, $ledger->read( $order ) ) as $row ) {
			if ( is_string( $row['id'] ?? null ) && strtolower( $row['id'] ) === strtolower( $id ) && in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ledger_completed( WC_Order $order, Ledger $ledger ): bool {
		$rows = self::current_rows( $order, $ledger->read( $order ) );
		if ( self::net_paid( $rows ) >= Money::minor( $order->get_total() ) ) {
			foreach ( $rows as $row ) {
				if ( in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) && Money::minor( $row['amount'] ?? 0 ) > Money::minor( $row['refunded_amount'] ?? 0 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function legacy_completed( WC_Order $order ): bool {
		// Ledgerless offline pushes have a server-owned assertion instead of a transaction id.
		return 0 === self::current( $order ) && $order->get_date_paid( 'edit' ) && ! in_array( $order->get_status(), Ledger::IN_PROGRESS_STATUSES, true )
			&& ( '' !== $order->get_transaction_id() || 'offline' === $order->get_meta( '_pos_payment_asserted' ) );
	}

	/** Shared reverse gate; payment routes do not acquire an expectedEpoch parameter in this PR. */
	public static function gate( WC_Order $order, \WP_REST_Request $request, ?int $expected = null, bool $check_expected = true ) {
		$epoch = self::current( $order );
		$modern = \WCPOS\WooCommercePOS\Services\Client_Signal::payments_at_least( \WCPOS\WooCommercePOS\Services\Client_Signal::read( $request ), '1.3' );
		$reason = ! $modern ? 'money_epoch' : 'expected_epoch_missing';
		if ( $epoch > 0 && ( ! $modern || ( $check_expected && null === $expected ) ) ) {
			return \WCPOS\WooCommercePOS\Services\Protocol_Gate::refusal(
				$request,
				array(
					'reason' => $reason,
					'epoch' => $epoch,
				)
			);
		}
		if ( $modern && $check_expected && null !== $expected && $expected !== $epoch ) {
			return new \WP_Error(
				'wcpos_money_epoch_stale',
				__( 'The sale has moved to a different money epoch.', 'woocommerce-pos' ),
				array(
					'status' => 422,
					'epoch' => $epoch,
				)
			);
		}
		return null;
	}

	public static function is_reopen( array $payload, WC_Order $stored ): bool {
		$meta = Settlement_Change::meta( $payload );
		return 'pos-open' === ( $payload['status'] ?? null ) && ( self::current( $stored ) + 1 ) === ( $meta['_wcpos_money_epoch'] ?? null )
			&& array_key_exists( '_wcpos_completed_at', $meta ) && null === $meta['_wcpos_completed_at'] && is_string( $meta['_wcpos_reopened_at'] ?? null ) && '' !== $meta['_wcpos_reopened_at'];
	}

	public static function reopen_refusal( WC_Order $stored ): ?\WP_Error {
		$ledger = Ledger::instance();
		$reason = null;
		// Neither a completion stamp nor another payment can replace this attempt's money.
		foreach ( self::attempt_entries( $stored ) as $id => $attempt ) {
			if ( self::current( $stored ) === $attempt['epoch'] && in_array( $attempt['state'], array( 'settled', 'paid' ), true ) && ! self::represented( $stored, $ledger, (string) $id, 'paid' === $attempt['state'] ) ) {
				$reason = $reason ?? $attempt['state'] . '_without_row';
			}
		}
		if ( ! self::is_completed_in_current_epoch( $stored, $ledger ) ) {
			$reason = $reason ?? 'not_completed';
		} else {
			$rows = self::current_rows( $stored, $ledger->read( $stored ) );
			foreach ( $rows as $row ) {
				if ( in_array( $row['status'] ?? '', array( 'pending', 'authorized' ), true ) ) {
					$reason = $reason ?? 'live_leg';
				}
			}
			foreach ( self::attempt_entries( $stored ) as $attempt ) {
				if ( self::current( $stored ) === $attempt['epoch'] && in_array( $attempt['state'], array( 'in-flight', 'indeterminate', 'sent' ), true ) ) {
					$reason = $reason ?? 'attempt_unresolved';
				}
			}
		}
		return $reason ? new \WP_Error(
			'wcpos_invalid_transition',
			__( 'This sale cannot be reopened.', 'woocommerce-pos' ),
			array(
				'status' => 422,
				'reason' => $reason,
			)
		) : null;
	}
}
