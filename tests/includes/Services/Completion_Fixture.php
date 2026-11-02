<?php
/**
 * Completion history fixtures.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;

/**
 * A sale record belongs to a completion entry (ADR 0040 rule 1c). Tests that only need a sale
 * record to exist give the order completion 1 directly, then write its record.
 */
final class Completion_Fixture {
	/**
	 * Give the order a completion entry without completing it: the server's own append, at the storage chain's origin.
	 *
	 * @param \WC_Order $order Order.
	 * @return array The entry.
	 */
	public static function seed( \WC_Order $order ): array {
		$entry = Completion_Stamp::append( $order, new Completion_Origin() );
		$order->read_meta_data( true ); // The caller's object reads what the append stored.
		return $entry;
	}

	/**
	 * Mark the latest entry reopened, as a reopen of the sale does.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function reopen( \WC_Order $order ): void {
		Completion_Stamp::reopen( $order );
		$order->read_meta_data( true );
	}

	/**
	 * Give the order completion 1 and write its sale record.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function sale( \WC_Order $order ): void {
		self::seed( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
	}
}
