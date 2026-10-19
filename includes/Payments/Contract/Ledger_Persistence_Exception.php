<?php
/**
 * A payment must not be acknowledged before its ledger is stored.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

/** Retryable failure of a ledger write, including a swallowed WooCommerce save error. */
final class Ledger_Persistence_Exception extends \RuntimeException {}
