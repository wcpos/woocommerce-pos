<?php
/**
 * What one ledger write stored.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;

/** The answer of `Ledger::write()`, read back from storage. */
final class Ledger_Written {
	/**
	 * The order as stored after the write.
	 *
	 * @var WC_Order
	 */
	public $order;

	/**
	 * The rows as stored.
	 *
	 * @var array
	 */
	public $rows;

	/**
	 * What the change returned.
	 *
	 * @var mixed
	 */
	public $answer;

	/**
	 * The standing completion entry after the write; null when the sale does not stand.
	 *
	 * @var array|null
	 */
	public $completion;

	/**
	 * Hold the stored outcome.
	 *
	 * @param WC_Order   $order      Stored order.
	 * @param array      $rows       Stored rows.
	 * @param mixed      $answer     The change's answer.
	 * @param array|null $completion Standing completion entry.
	 */
	public function __construct( WC_Order $order, array $rows, $answer, ?array $completion ) {
		$this->order      = $order;
		$this->rows       = $rows;
		$this->answer     = $answer;
		$this->completion = $completion;
	}
}
