<?php
/**
 * Thrown when the per-order receipt print lock could not be taken in time.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** A caller that cannot take the print lock must not count on stale state. */
final class Print_Counter_Busy_Exception extends \RuntimeException {}
