<?php
/**
 * Shared helpers for scrubbing digit-bearing order address fields.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\Traits
 */

namespace WCPOS\WooCommercePOS\Tests\API\Traits;

use WC_Order;

/**
 * Trait Order_Address_Scrub_Helpers
 *
 * Provides a helper for removing digits from order address fields so that
 * order-ID search assertions are not affected by the global auto-increment.
 */
trait Order_Address_Scrub_Helpers {
	/**
	 * Strip digit-bearing address fields so an order-ID search term cannot
	 * LIKE-match this order's address index. Order IDs follow the global
	 * auto-increment, so they can collide with the fixture postcode/phone
	 * (e.g. searching "123" matches postcode "123456").
	 *
	 * @param WC_Order $order Order to scrub.
	 *
	 * @return WC_Order
	 */
	protected function scrub_numeric_address_fields( WC_Order $order ): WC_Order {
		$order->set_billing_postcode( 'WooPostcode' );
		$order->set_billing_phone( 'WooPhone' );
		$order->set_shipping_address_1( 'Mercer Street' );
		$order->set_shipping_postcode( 'WooZip' );
		$order->set_shipping_phone( 'WooShipPhone' );
		$order->save();

		return $order;
	}
}
