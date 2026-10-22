<?php
/**
 * A refund-capable gateway fixture for the gateway-adoption pins.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

/** A refund-capable gateway that records its calls and contacts no provider. */
class Adopt_Refund_Test_Gateway extends \WC_Payment_Gateway {
	/**
	 * Calls to process_refund().
	 *
	 * @var int
	 */
	public static $calls = 0;

	/** Fixture identity. */
	public function __construct() {
		$this->id       = 'wcpos_adopt_refund_test';
		$this->title    = 'Online card';
		$this->enabled  = 'yes';
		$this->supports = array( 'products', 'refunds' );
	}

	/**
	 * Refund at the provider.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount.
	 * @param string $reason   Reason.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		++self::$calls;
		return true;
	}
}
