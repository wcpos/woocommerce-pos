<?php
/**
 * Test double that stands in for MyParcel calling into WooCommerce.
 *
 * Lives outside the PSR-4 map on purpose: the class imitates MyParcel's
 * `MyParcelNL\` namespace, which is how WCPOS recognises the caller
 * (woocommerce-pos#2468).
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace MyParcelNL\WooCommerce\Tests;

use WC_Order_Item_Product;

/**
 * Calls WooCommerce from a `MyParcelNL\` frame.
 */
class MyParcel_Caller_Stub {
	/**
	 * Ask the order item for its product, as MyParcel does.
	 *
	 * @param WC_Order_Item_Product $item The order item.
	 *
	 * @return bool|\WC_Product
	 */
	public static function product_for( WC_Order_Item_Product $item ) {
		return $item->get_product();
	}

	/**
	 * Run a callback with this class on the stack.
	 *
	 * @param callable $callback The callback to run.
	 *
	 * @return mixed
	 */
	public static function call( callable $callback ) {
		return $callback();
	}
}
