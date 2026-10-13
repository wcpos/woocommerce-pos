<?php
/**
 * Webview payment capture handler.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/**
 * Describes legacy order-pay webview capture.
 *
 * A webview leg is money a WooCommerce gateway took on the order-pay page (or
 * payment_complete()), so it is refunded and (not) voided exactly as a gateway leg
 * is; only the advertised mode differs.
 */
class Webview_Handler extends Gateway_Handler {
	/**
	 * Describe webview capture.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$description                   = parent::describe( $gateway );
		$description['capture']['mode'] = 'webview';

		return $description;
	}
}
