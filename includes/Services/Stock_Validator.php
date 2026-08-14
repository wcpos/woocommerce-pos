<?php
/**
 * POS stock validation.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;
use WP_REST_Request;

/**
 * Rejects paid POS orders whose quantities exceed available stock.
 */
class Stock_Validator {
	/**
	 * Register the REST order validation hook.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'validate_stock' ), 10, 3 );
	}

	/**
	 * Validate stock before a POS REST order is written.
	 *
	 * @param WC_Order|WP_Error $order    Prepared order object.
	 * @param WP_REST_Request   $request  REST request.
	 * @param bool              $creating Whether the order is being created.
	 *
	 * @return WC_Order|WP_Error
	 */
	public function validate_stock( $order, WP_REST_Request $request, bool $creating ) {
		if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
			return $order;
		}

		if ( ! \wcpos_request() || ! Settings::instance()->prevent_overselling_enabled() ) {
			return $order;
		}

		if ( ! $this->should_validate_status( $order, $request, $creating ) ) {
			return $order;
		}

		$failures      = array();
		$managed_stock = array();

		$line_index = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				++$line_index;
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$quantity   = (float) $item->get_quantity();
			if ( 0 === $product_id || $quantity <= 0 ) {
				++$line_index;
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				++$line_index;
				continue;
			}

			$line        = $this->line_data( $item, $product, $quantity );
			$stock_owner = $this->stock_owner( $product );

			if ( $stock_owner instanceof WC_Product ) {
				$owner_id = $stock_owner->get_id();
				if ( ! isset( $managed_stock[ $owner_id ] ) ) {
					$managed_stock[ $owner_id ] = array(
						'owner'     => $stock_owner,
						'requested' => 0.0,
						'lines'     => array(),
					);
				}

				$managed_stock[ $owner_id ]['requested'] += $quantity;
				$managed_stock[ $owner_id ]['lines'][ $line_index ] = $line;
				++$line_index;
				continue;
			}

			if ( 'outofstock' === $product->get_stock_status() ) {
				$failures[ $line_index ] = array_merge(
					$line,
					array(
						'available'  => null,
						'reason'     => 'out_of_stock_status',
						'backorders' => $product->get_backorders(),
					)
				);
			}

			++$line_index;
		}

		foreach ( $managed_stock as $group ) {
			$owner      = $group['owner'];
			$available  = (float) $owner->get_stock_quantity();
			$backorders = $owner->get_backorders();

			if ( 'no' !== $backorders || $group['requested'] <= $available ) {
				continue;
			}

			foreach ( $group['lines'] as $index => $line ) {
				$failures[ $index ] = array_merge(
					$line,
					array(
						'available'  => $available,
						'reason'     => 'insufficient_stock',
						'backorders' => $backorders,
					)
				);
			}
		}

		if ( empty( $failures ) ) {
			return $order;
		}
		\ksort( $failures );
		$failures = \array_values( $failures );

		return new WP_Error(
			'wcpos_insufficient_stock',
			\sprintf(
				/* translators: %d: Number of order line items without enough stock. */
				__( 'Cannot complete order: %d item(s) exceed available stock.', 'woocommerce-pos' ),
				\count( $failures )
			),
			array(
				'status' => 400,
				'items'  => $failures,
			)
		);
	}

	/**
	 * Whether the prepared order is leaving draft-land for a sale status.
	 *
	 * Draft/cart syncs must always succeed; validation fires only on the
	 * checkout transition. The gate is an exempt-list rather than
	 * wc_get_is_paid_statuses() because a gateway's configured order_status
	 * may be any status, including custom ones.
	 *
	 * @param WC_Order        $order    Prepared order object.
	 * @param WP_REST_Request $request  REST request.
	 * @param bool            $creating Whether the order is being created.
	 */
	private function should_validate_status( WC_Order $order, WP_REST_Request $request, bool $creating ): bool {
		$exempt_statuses = apply_filters(
			'woocommerce_pos_stock_validation_exempt_statuses',
			array( 'pos-open', 'pos-partial', 'pending', 'auto-draft', 'checkout-draft', 'draft', 'cancelled', 'refunded', 'failed', 'trash' )
		);
		$target_status = $request->has_param( 'status' ) ? (string) $request->get_param( 'status' ) : $order->get_status();
		$target_status = 0 === strpos( $target_status, 'wc-' ) ? substr( $target_status, 3 ) : $target_status;
		$set_paid      = $request->has_param( 'set_paid' ) && rest_sanitize_boolean( $request->get_param( 'set_paid' ) );
		if ( ! $set_paid && \in_array( $target_status, $exempt_statuses, true ) ) {
			return false;
		}

		if ( $creating || 0 === $order->get_id() ) {
			return true;
		}

		$stored_order = wc_get_order( $order->get_id() );

		return ! $stored_order instanceof WC_Order
			|| \in_array( $stored_order->get_status(), $exempt_statuses, true );
	}

	/**
	 * Resolve the product whose quantity owns stock for a line.
	 *
	 * @param WC_Product $product Line item product or variation.
	 */
	private function stock_owner( WC_Product $product ): ?WC_Product {
		if ( $product->is_type( 'variation' ) ) {
			if ( true === $product->get_manage_stock( 'edit' ) && $product->managing_stock() ) {
				return $product;
			}

			$parent = wc_get_product( $product->get_parent_id() );

			return $parent instanceof WC_Product && $parent->managing_stock() ? $parent : null;
		}

		return $product->managing_stock() ? $product : null;
	}

	/**
	 * Build the line-specific part of an error item.
	 *
	 * @param WC_Order_Item_Product $item     Order line item.
	 * @param WC_Product            $product Line item product or variation.
	 * @param float                 $quantity Requested quantity.
	 *
	 * @return array<string,int|float|string>
	 */
	private function line_data( WC_Order_Item_Product $item, WC_Product $product, float $quantity ): array {
		$name = $item->get_name();

		return array(
			'product_id'  => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'name'         => $name ? $name : $product->get_name(),
			'requested'    => $quantity,
		);
	}
}
