<?php
/**
 * Completion history fixtures.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;

/**
 * A sale record belongs to a completion entry (ADR 0040 rule 1c). Tests that only need a sale
 * record to exist give the order completion 1 directly, then write its record.
 */
final class Completion_Fixture {
	/**
	 * Give the order a completion entry without completing it, from its till meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return array The entry.
	 */
	public static function seed( \WC_Order $order ): array {
		$history = Completion_History::of( $order )->entries();
		$n       = count( $history ) + 1;
		$till    = static function ( string $key ) use ( $order ): ?string {
			return '' === (string) $order->get_meta( $key ) ? null : (string) $order->get_meta( $key );
		};
		// As Completion_Stamp resolves it with no request provenance: completion 1 takes the whole till
		// tuple; a later one only the register and session (the latest captured row's, here the till's).
		$entry = array(
			'id' => wp_generate_uuid4(),
			'n' => $n,
			'at_gmt' => gmdate( 'c' ),
			'sale_time' => 1 === $n ? $till( '_wcpos_sale_time' ) : null,
			'sale_tz' => 1 === $n ? $till( '_wcpos_sale_tz' ) : null,
			'register' => $till( '_wcpos_register' ),
			'session' => $till( '_wcpos_session' ),
			'counter' => 1 === $n ? $till( '_wcpos_sale_counter' ) : null,
			'total' => $order->get_total(),
			'payment_ids' => array(),
			'reopened_at_gmt' => null,
		);
		$history[] = $entry;
		$order->update_meta_data( Completion_Stamp::META_KEY, wp_json_encode( $history ) );
		$order->save_meta_data();
		return $entry;
	}

	/**
	 * Mark the latest entry reopened, as a reopen of the sale does.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function reopen( \WC_Order $order ): void {
		$history = Completion_History::of( $order )->entries();
		$history[ count( $history ) - 1 ]['reopened_at_gmt'] = gmdate( 'c' );
		$order->update_meta_data( Completion_Stamp::META_KEY, wp_json_encode( $history ) );
		$order->save_meta_data();
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
