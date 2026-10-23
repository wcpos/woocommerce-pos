<?php
/**
 * Complete a sale at a register as the till's money commands do.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WC_Order;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;

/** A money command whose body names the register it completes the sale at (its `provenance`), without the route. */
final class Register_Sale {
	/**
	 * Take a tender as the till's `record` command does.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $input      Tender input; an id is minted when it has none.
	 * @param array    $provenance The body's `provenance` (register_id, session_id, sale_time, sale_tz, sale_counter).
	 * @return array|\WP_Error The row.
	 */
	public static function cash( WC_Order $order, array $input, array $provenance = array() ) {
		$input['id'] = strtolower( (string) ( $input['id'] ?? wp_generate_uuid4() ) );
		$command     = new Money_Command( $input['id'], 'sha256:' . $input['id'], 'record', '', null, '', new Completion_Origin( $provenance ) );
		return Ledger::instance()->record( self::fresh( $order ), $input, array(), $command );
	}

	/**
	 * Complete the sale: cash for what it still owes, or the till's `close` when nothing is owed (a reopened sale).
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $provenance The body's `provenance`.
	 * @return mixed The command's answer.
	 */
	public static function complete( WC_Order $order, array $provenance = array() ) {
		$order   = self::fresh( $order );
		$balance = Ledger::instance()->balance( $order, Ledger::instance()->read( $order ) );
		if ( Money::minor( $balance ) > 0 ) {
			return self::cash(
				$order,
				array(
					'method_id' => 'pos_cash',
					'amount'    => $balance,
				),
				$provenance
			);
		}
		$id = wp_generate_uuid4();
		return Ledger::instance()->close( $order, new Money_Command( $id, 'sha256:' . $id, 'close', '', null, '', new Completion_Origin( $provenance ) ) );
	}

	/**
	 * The order as storage holds it.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function fresh( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$fresh->read_meta_data( true );
		return $fresh;
	}
}
