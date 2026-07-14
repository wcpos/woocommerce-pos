<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use RuntimeException;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The uuid fail-closed contract violated: serialize_order/skeleton_for_order
 * always stamp _woocommerce_pos_uuid, so an empty uuid on a non-empty payload
 * means the write surface is broken — never emit the document. This is THE
 * one reaction (#424): direct builders let it fail their page/chunk (retried);
 * the pull planner catches it to stop the page WITHOUT advancing the checkpoint
 * (hasMore forces a retry from the last emitted position).
 */
final class Order_Uuid_Exception extends RuntimeException {
	public static function for_order( int $order_id ): self {
		return new self( 'Order document could not resolve _woocommerce_pos_uuid for order ' . $order_id );
	}
}
