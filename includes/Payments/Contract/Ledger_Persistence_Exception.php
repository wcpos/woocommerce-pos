<?php
/**
 * A payment must not be acknowledged before its ledger is stored.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

/** Retryable failure of a ledger write, including a swallowed WooCommerce save error. */
final class Ledger_Persistence_Exception extends \RuntimeException {
	/**
	 * The write step that failed (`lock`, `rows`, `paid`, `completion`); earlier steps may have committed.
	 *
	 * @var string
	 */
	public $stage = 'rows';

	/**
	 * A failure at one step of `Ledger::write()`.
	 *
	 * @param string $stage   Failed step.
	 * @param string $message Message.
	 */
	public static function at( string $stage, string $message ): self {
		$error        = new self( $message );
		$error->stage = $stage;
		return $error;
	}
}
