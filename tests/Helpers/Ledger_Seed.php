<?php
/**
 * Seed payment rows through the ledger's one write.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WC_Order;
use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;

/** Stores rows the way the server writes them: `Ledger::write()`, never raw meta. */
final class Ledger_Seed {
	/**
	 * Put rows on an order by id; every other stored row stays.
	 *
	 * @param WC_Order|int $order   Order or order id. An order object is re-read from storage afterwards.
	 * @param array        $rows    Rows to put.
	 * @param bool         $project Project the order's status and method from the rows (MODE_AUTO); false only stages them (MODE_HOLD).
	 * @param array        $dropped Row ids to remove (the write allows only a pending row to vanish).
	 * @return WC_Order The order as the write left it.
	 */
	public static function rows( $order, array $rows, bool $project = true, array $dropped = array() ): WC_Order {
		$written = Ledger::instance()->write(
			$order,
			static function ( Ledger_Draft $draft ) use ( $rows, $dropped ): void {
				foreach ( $rows as $row ) {
					$draft->put( $row );
				}
				foreach ( $dropped as $id ) {
					$draft->drop( (string) $id );
				}
			},
			$project ? Ledger::MODE_AUTO : Ledger::MODE_HOLD
		);
		if ( $order instanceof WC_Order ) {
			$order->get_data_store()->read( $order );
			$order->read_meta_data( true );
		}
		return $written->order;
	}

	/**
	 * Make these rows the order's whole ledger: a fixture rewriting rows it already stored (an amount, a method)
	 * that the write's invariants (I5) rightly refuse for real money starts again from no ledger.
	 *
	 * @param WC_Order $order   Order. Re-read from storage afterwards.
	 * @param array    $rows    The rows.
	 * @param bool     $project As for rows().
	 * @return WC_Order The order as the write left it.
	 */
	public static function replace( WC_Order $order, array $rows, bool $project = true ): WC_Order {
		$order->delete_meta_data( Ledger::META_KEY );
		$order->save_meta_data();
		return self::rows( $order, $rows, $project );
	}

	/**
	 * Open a gateway attempt as a submit does, then leave it as the gateway's answer would.
	 *
	 * @param WC_Order|int $order      Order or order id. An order object is re-read from storage afterwards.
	 * @param string       $attempt    Attempt id.
	 * @param string       $method_id  The gateway's method id.
	 * @param string       $fate       `awaiting` (sent, awaiting the customer), `sent` (stranded: the gateway threw after
	 *                                 dispatch), or a resolved fate.
	 * @param array        $provenance Body-shaped provenance of the submit's command (`register_id`, `session_id`).
	 * @return WC_Order The order as the write left it.
	 */
	public static function attempt( $order, string $attempt, string $method_id = 'pos_cash', string $fate = 'awaiting', array $provenance = array() ): WC_Order {
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:seed', 'submit', '', $attempt, '', new Completion_Origin( $provenance ) );
		$written = Ledger::instance()->write(
			$order,
			static function ( Ledger_Draft $draft ) use ( $attempt, $method_id, $fate, $command ): void {
				Attempts::open( $draft, $attempt, $method_id, $command );
				$draft->order()->set_payment_method( $method_id ); // As the submit's held write assigns the chosen gateway.
				if ( 'awaiting' === $fate ) {
					Attempts::await( $draft, $attempt, null );
				} elseif ( 'sent' !== $fate ) {
					Attempts::resolve( $draft, $attempt, $fate );
				}
			},
			Ledger::MODE_HOLD
		);
		if ( $order instanceof WC_Order ) {
			$order->get_data_store()->read( $order );
			$order->read_meta_data( true );
		}
		return $written->order;
	}
}
