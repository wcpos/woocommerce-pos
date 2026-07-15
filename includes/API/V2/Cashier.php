<?php
/**
 * WCPOS REST API v2 service pass-through.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

/**
 * wcpos/v2 pass-through of the v1 Cashier service — identical behavior,
 * versioned namespace (map #544 boundary ruling: everything under one version).
 */
class Cashier extends \WCPOS\WooCommercePOS\API\V1\Cashier {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
}
