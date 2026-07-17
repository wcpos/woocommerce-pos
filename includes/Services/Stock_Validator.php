<?php
/**
 * POS stock validation.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;
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
	 * Registered validator instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Get the registered validator.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the REST order validation hook.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'validate_stock' ), 10, 3 );
		add_filter( 'woocommerce_query_for_reserved_stock', array( $this, 'include_pos_draft_reservations' ) );
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

		return $this->validate_order( $order );
	}

	/**
	 * Validate stock for the direct POS checkout action.
	 *
	 * @param WC_Order $order Order being checked out.
	 * @return WC_Order|WP_Error
	 */
	public function validate_checkout( WC_Order $order ) {
		if ( ! \wcpos_request() || ! Settings::instance()->prevent_overselling_enabled() ) {
			return $order;
		}

		return $this->validate_order( $order );
	}

	/**
	 * Release reservations created for a POS checkout.
	 *
	 * @param WC_Order $order Order whose reservation should be released.
	 */
	public function release_checkout_stock( WC_Order $order ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->wc_reserved_stock,
			array( 'order_id' => $order->get_id() ),
			array( '%d' )
		);
	}

	/**
	 * Make POS draft reservations visible to WooCommerce checkout holds.
	 *
	 * @param string $query WooCommerce reserved-stock query.
	 */
	public function include_pos_draft_reservations( string $query ): string {
		if ( ! Settings::instance()->prevent_overselling_enabled() ) {
			return $query;
		}

		return str_replace(
			"IN ( 'wc-checkout-draft', 'wc-pending' )",
			"IN ( 'wc-checkout-draft', 'wc-pending', 'wc-pos-open', 'wc-pos-partial' )",
			$query
		);
	}

	/**
	 * Whether a new-order request is attempting checkout rather than draft sync.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function should_validate_create_request( WP_REST_Request $request ): bool {
		$target_status = $request->has_param( 'status' ) ? (string) $request->get_param( 'status' ) : 'pending';
		$target_status = 0 === strpos( $target_status, 'wc-' ) ? substr( $target_status, 3 ) : $target_status;
		$set_paid      = $request->has_param( 'set_paid' ) && rest_sanitize_boolean( $request->get_param( 'set_paid' ) );

		return $set_paid || ! \in_array( $target_status, $this->exempt_statuses(), true );
	}

	/**
	 * Validate and reserve stock for an order.
	 *
	 * @param WC_Order $order Order being checked out.
	 * @return WC_Order|WP_Error
	 * @throws \Throwable If the atomic reservation query cannot be completed.
	 */
	private function validate_order( WC_Order $order ) {

		$failures      = array();
		$managed_stock = array();

		$line_index = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				++$line_index;
				continue;
			}

			$product_id     = (int) $item->get_product_id();
			$quantity       = (float) $item->get_quantity();
			$quantity_units = $this->stock_units( $quantity );
			if ( 0 === $product_id || $quantity_units <= 0 ) {
				++$line_index;
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				$failures[ $line_index ] = array(
					'product_id'  => $product_id,
					'variation_id' => (int) $item->get_variation_id(),
					'name'         => $item->get_name(),
					'requested'    => $this->stock_quantity( $quantity_units ),
					'available'    => null,
					'reason'       => 'product_not_found',
					'backorders'   => 'no',
				);
				++$line_index;
				continue;
			}

			$line        = $this->line_data( $item, $product, $this->stock_quantity( $quantity_units ) );
			$stock_owner = $this->stock_owner( $product );

			if ( $stock_owner instanceof WC_Product ) {
				$owner_id = $stock_owner->get_id();
				if ( ! isset( $managed_stock[ $owner_id ] ) ) {
					$managed_stock[ $owner_id ] = array(
						'owner'     => $stock_owner,
						'requested' => 0,
						'lines'     => array(),
					);
				}

				$managed_stock[ $owner_id ]['requested'] += $quantity_units;
				$managed_stock[ $owner_id ]['lines'][ $line_index ] = $line;
				++$line_index;
				continue;
			}

			if ( 'outofstock' === $product->get_stock_status() && 'no' === $product->get_backorders() ) {
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

		\ksort( $managed_stock );
		$reserving = 0 < $order->get_id() && ! empty( $managed_stock );
		if ( $reserving ) {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		try {
			foreach ( $managed_stock as $group ) {
				$owner      = $group['owner'];
				$backorders = $owner->get_backorders();

				if ( 'no' !== $backorders ) {
					continue;
				}

				$available = $this->available_stock( $owner, $order->get_id() );
				if ( $reserving && $this->reserve_stock( $order, $owner, $group['requested'] ) ) {
					continue;
				}
				if ( ! $reserving && $group['requested'] <= $this->stock_units( $available ) ) {
					continue;
				}

				if ( $reserving ) {
					$available = $this->available_stock( $owner, $order->get_id() );
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
		} catch ( \Throwable $exception ) {
			if ( $reserving ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			throw $exception;
		}

		if ( $reserving ) {
			if ( empty( $failures ) ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Return sellable stock after active holds.
	 *
	 * @param WC_Product $owner    Product that owns stock.
	 * @param int        $order_id Current order ID.
	 */
	private function available_stock( WC_Product $owner, int $order_id ): float {
		global $wpdb;

		/**
		 * Product stock data store.
		 *
		 * @var \WC_Product_Data_Store_CPT $data_store
		 */
		$data_store  = \WC_Data_Store::load( 'product' );
		$stock_query = $data_store->get_query_for_stock( $owner->get_id() );
		$stock       = (float) $wpdb->get_var( $stock_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$available   = $stock - (float) wc_get_held_stock_quantity( $owner, $order_id );

		return $this->stock_quantity( $this->stock_units( $available ) );
	}

	/**
	 * Atomically reserve a fixed-precision stock quantity.
	 *
	 * @param WC_Order   $order           Order receiving the reservation.
	 * @param WC_Product $owner           Product that owns stock.
	 * @param int        $requested_units Requested fixed-precision units.
	 */
	private function reserve_stock( WC_Order $order, WC_Product $owner, int $requested_units ): bool {
		global $wpdb;

		$owner_id = $owner->get_id();
		/**
		 * Product stock data store.
		 *
		 * @var \WC_Product_Data_Store_CPT $data_store
		 */
		$data_store     = \WC_Data_Store::load( 'product' );
		$stock_query    = $data_store->get_query_for_stock( $owner_id );
		$reserved_query = $this->reserved_stock_query( $owner_id, $order->get_id() );
		$minutes        = max( 1, (int) get_option( 'woocommerce_hold_stock_minutes', 60 ) );
		$precision      = wc_get_rounding_precision();
		$scale          = 10 ** $precision;
		$quantity       = wc_format_decimal( $this->stock_quantity( $requested_units ), $precision );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce supplies the stock subquery; all values are prepared.
		$sql = $wpdb->prepare(
			"
			INSERT INTO {$wpdb->wc_reserved_stock} ( order_id, product_id, stock_quantity, timestamp, expires )
			SELECT %d, %d, %s, NOW(), ( NOW() + INTERVAL %d MINUTE ) FROM DUAL
			WHERE ROUND( ( ( $stock_query FOR UPDATE ) - ( $reserved_query LOCK IN SHARE MODE ) ) * $scale ) >= %d
			ON DUPLICATE KEY UPDATE expires = VALUES( expires ), stock_quantity = VALUES( stock_quantity )
			",
			$order->get_id(),
			$owner_id,
			$quantity,
			$minutes,
			$requested_units
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result && ( 0 < $result || $this->has_reservation( $order->get_id(), $owner_id, $requested_units ) );
	}

	/**
	 * Build the status-scoped held-stock query used by the atomic reservation.
	 *
	 * @param int $owner_id        Stock owner ID.
	 * @param int $exclude_order_id Current order ID.
	 */
	private function reserved_stock_query( int $owner_id, int $exclude_order_id ): string {
		global $wpdb;

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$join         = "{$wpdb->prefix}wc_orders orders ON stock_table.order_id = orders.id";
			$where_status = "orders.status IN ( 'wc-checkout-draft', 'wc-pending' )";
		} else {
			$join         = "{$wpdb->posts} posts ON stock_table.order_id = posts.ID";
			$where_status = "posts.post_status IN ( 'wc-checkout-draft', 'wc-pending' )";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and status clause are selected above; values are prepared.
		$query = $wpdb->prepare(
			"SELECT COALESCE( SUM( stock_table.stock_quantity ), 0 ) FROM {$wpdb->wc_reserved_stock} stock_table LEFT JOIN $join WHERE $where_status AND stock_table.expires > NOW() AND stock_table.product_id = %d AND stock_table.order_id != %d",
			$owner_id,
			$exclude_order_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is a WooCommerce core hook.
			'woocommerce_query_for_reserved_stock',
			$query,
			$owner_id,
			$exclude_order_id
		);
	}

	/**
	 * Check for an idempotent reservation update.
	 *
	 * @param int $order_id       Order ID.
	 * @param int $owner_id       Stock owner ID.
	 * @param int $requested_units Requested fixed-precision units.
	 */
	private function has_reservation( int $order_id, int $owner_id, int $requested_units ): bool {
		global $wpdb;

		$held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT stock_quantity FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d AND product_id = %d AND expires > NOW()",
				$order_id,
				$owner_id
			)
		);

		return null !== $held && $this->stock_units( (float) $held ) === $requested_units;
	}

	/**
	 * Convert a stock amount to fixed-precision units.
	 *
	 * @param float $quantity Stock quantity.
	 */
	private function stock_units( float $quantity ): int {
		return (int) \round( $quantity * ( 10 ** wc_get_rounding_precision() ) );
	}

	/**
	 * Convert fixed-precision units to a stock amount.
	 *
	 * @param int $units Fixed-precision stock units.
	 */
	private function stock_quantity( int $units ): float {
		return $units / ( 10 ** wc_get_rounding_precision() );
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
		$exempt_statuses = $this->exempt_statuses();
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
	 * Draft and terminal statuses exempt from checkout validation.
	 *
	 * @return string[]
	 */
	private function exempt_statuses(): array {
		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional public WCPOS hook.
			'wcpos_stock_validation_exempt_statuses',
			array( 'pos-open', 'pos-partial', 'pending', 'auto-draft', 'checkout-draft', 'draft', 'cancelled', 'refunded', 'failed', 'trash' )
		);
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
