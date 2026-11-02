<?php
/**
 * The WordPress and WooCommerce hooks that run inside someone else's save.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

\defined( 'ABSPATH' ) || die;

/**
 * One list, so every reader agrees: the test suite's `Save_Reentry_Guard` fails a plugin save made
 * inside these hooks, and production code that must not save inside them asks `contains()`.
 * See `.ai/rules/no-saves-inside-save-hooks.mdc`.
 */
final class Save_Hooks {
	/** Exact names. */
	public const HOOKS = array(
		// WordPress post save stack: everything wp_insert_post(), wp_delete_post() and wp_trash_post() fire around the write.
		'save_post',
		'wp_insert_post',
		'wp_after_insert_post',
		'post_updated',
		'pre_post_update',
		'edit_post',
		'transition_post_status',
		'wp_insert_post_data',
		'content_save_pre',
		'pre_delete_post',
		'pre_delete_attachment',
		'delete_post',
		'before_delete_post',
		'wp_trash_post',
		// Product edit screen staging hooks, which hand over the object before WooCommerce's own save. The meta-box
		// persistence hooks (woocommerce_process_*_meta) are not listed: WooCommerce's own meta boxes save inside them.
		'woocommerce_admin_process_product_object',
		'woocommerce_admin_process_variation_object',
		// Order life-cycle: payment, stock, refunds and checkout.
		'woocommerce_payment_complete',
		'woocommerce_pre_payment_complete',
		'woocommerce_reduce_order_stock',
		'woocommerce_restore_order_stock',
		'woocommerce_reduce_order_item_stock',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_before_set_stock',
		'woocommerce_variation_before_set_stock',
		'woocommerce_order_refunded',
		'woocommerce_refund_created',
		'woocommerce_checkout_order_processed',
		'woocommerce_checkout_create_order',
		'woocommerce_checkout_update_order_meta',
		'woocommerce_checkout_order_created',
		// The item and customer writes WC_Checkout makes while it builds the order (not the non-write checkout filters).
		'woocommerce_checkout_create_order_line_item',
		'woocommerce_checkout_create_order_fee_item',
		'woocommerce_checkout_create_order_shipping_item',
		'woocommerce_checkout_create_order_tax_item',
		'woocommerce_checkout_create_order_coupon_item',
		'woocommerce_checkout_update_customer',
		// Data store create/update events per object type: exact names, as a woocommerce_update_ prefix would also catch settings saves.
		'woocommerce_new_product',
		'woocommerce_update_product',
		'woocommerce_new_product_variation',
		'woocommerce_update_product_variation',
		'woocommerce_new_order',
		'woocommerce_update_order',
		'woocommerce_new_order_refund',
		'woocommerce_update_order_refund',
		'woocommerce_new_order_item',
		'woocommerce_update_order_item',
		'woocommerce_new_coupon',
		'woocommerce_update_coupon',
		'woocommerce_new_customer',
		'woocommerce_update_customer',
		'woocommerce_new_webhook',
		'woocommerce_update_webhook',
		'woocommerce_new_payment_token',
		'woocommerce_update_payment_token',
		'woocommerce_new_shipping_zone',
		'woocommerce_update_shipping_zone',
	);

	/** Families as [ prefix, suffix ] ('' = any): the per-type post save, WC_Data saves, every order status hook, REST writes. */
	public const PATTERNS = array(
		array( 'save_post_', '' ),
		array( 'woocommerce_before_', '_object_save' ),
		array( 'woocommerce_after_', '_object_save' ),
		array( 'woocommerce_', '_object_updated_props' ),
		array( 'woocommerce_order_status_', '' ),
		array( 'woocommerce_payment_complete_order_status_', '' ),
		array( 'woocommerce_rest_insert_', '' ),
		array( 'woocommerce_rest_pre_insert_', '' ),
		array( 'rest_insert_', '' ),
		array( 'rest_after_insert_', '' ),
	);

	/**
	 * Whether a hook runs inside someone else's save.
	 *
	 * @param mixed $name Hook name.
	 */
	public static function contains( $name ): bool {
		if ( ! is_string( $name ) || in_array( $name, self::HOOKS, true ) ) {
			return is_string( $name );
		}
		foreach ( self::PATTERNS as list( $prefix, $suffix ) ) {
			if ( 0 === strpos( $name, $prefix ) && ( '' === $suffix || ( strlen( $name ) > strlen( $prefix ) + strlen( $suffix ) && substr( $name, - strlen( $suffix ) ) === $suffix ) ) ) {
				return true;
			}
		}
		return false;
	}
}
