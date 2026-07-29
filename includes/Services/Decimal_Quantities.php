<?php
/**
 * POS decimal quantity REST schema relaxation.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Relaxes integer-typed wc/v3 quantity schemas for POS requests when decimal
 * stock/cart quantities are enabled.
 *
 * The v2 write surface forwards mutation envelopes to the stock wc/v3
 * controllers (Write_Controller::dispatch_write), whose schemas type order
 * `line_items[].quantity` and variation `stock_quantity` as `integer` — a
 * POS push carrying 0.5 is rejected with rest_invalid_param before WooCommerce
 * ever sees it. The v1 surface relaxed these on its own controller subclasses
 * (V1\Orders_Controller / the products trait); the forwarded wc/v3 dispatch
 * needs the same relaxation applied to the registered endpoint args instead.
 *
 * Products' own `stock_quantity` is only relaxed where WC still hardcodes
 * `integer`: current WC keys that schema off wc_is_stock_amount_integer(), so
 * the existing woocommerce_stock_amount floatval swap (Products.php) already
 * yields `number` there and this filter is a no-op for it.
 *
 * Gated the same way as Stock_Validator: the hook is always registered, the
 * callback bails unless this is a POS request AND the setting is enabled — so
 * direct (non-POS) wc/v3 requests keep WooCommerce's strict schema.
 */
class Decimal_Quantities {
	/**
	 * Register the REST endpoint args filter.
	 */
	public function __construct() {
		add_filter( 'rest_endpoints', array( $this, 'relax_quantity_schemas' ) );
	}

	/**
	 * Retype integer quantity args to `number` on the wc/v3 order and product
	 * endpoints for a decimal-enabled POS request.
	 *
	 * @param array $endpoints Registered REST endpoints, keyed by route.
	 *
	 * @return array The (possibly relaxed) endpoints.
	 */
	public function relax_quantity_schemas( array $endpoints ): array {
		if ( ! \wcpos_request() || ! Settings::instance()->decimal_qty_enabled() ) {
			return $endpoints;
		}

		foreach ( $endpoints as $route => $handlers ) {
			if ( ! \is_array( $handlers ) ) {
				continue;
			}
			$is_orders   = 0 === strpos( $route, '/wc/v3/orders' );
			$is_products = 0 === strpos( $route, '/wc/v3/products' );
			if ( ! $is_orders && ! $is_products ) {
				continue;
			}
			foreach ( $handlers as $key => $handler ) {
				// Route-level option keys (e.g. 'namespace') are not handler arrays.
				if ( ! \is_array( $handler ) || ! isset( $handler['args'] ) || ! \is_array( $handler['args'] ) ) {
					continue;
				}
				if ( $is_orders && 'integer' === ( $handler['args']['line_items']['items']['properties']['quantity']['type'] ?? null ) ) {
					$endpoints[ $route ][ $key ]['args']['line_items']['items']['properties']['quantity']['type'] = 'number';
				}
				if ( $is_products && 'integer' === ( $handler['args']['stock_quantity']['type'] ?? null ) ) {
					$endpoints[ $route ][ $key ]['args']['stock_quantity']['type'] = 'number';
				}
			}
		}

		return $endpoints;
	}
}
