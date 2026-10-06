<?php
/**
 * Sync status REST API controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Health;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Reports sync store health.
 */
final class Status_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	/**
	 * Named features this server supports, which a client may test for instead of comparing versions.
	 *
	 * - `products_id_fast_path`: `GET /wcpos/v2/products?per_page=-1&_fields=id,date_modified_gmt,stock_quantity,stock_status`
	 *   is answered from one query (#2113).
	 * - `order_payments_list`: an order create records the `_woocommerce_pos_payments` meta (a JSON list of
	 *   tenders; `payment_method` is the primary tender), validated and write-once (Services\Pos_Payments).
	 * - `order_create_v5`: `POST /wcpos/v2/push/orders` takes TallyUI order.create v5 creates mapped to the
	 *   WooCommerce shape: `fee_lines`, `shipping_lines` (`method_id` `pos` allowed) and custom `product_id: 0`
	 *   lines priced and taxed from `_woocommerce_pos_data`, idempotent on `mutationId` (#2137).
	 *
	 * Append new names; never rename or remove one.
	 */
	private const CAPABILITIES = array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' );

	/**
	 * Register the sync status route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Get the current sync store health.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$missing_tables = Health::missing_tables();

		return new WP_REST_Response(
			array(
				'healthy'        => array() === $missing_tables,
				'missing_tables' => $missing_tables,
				'schema_version' => get_option( Api::SCHEMA_OPTION, null ),
				'capabilities'   => self::CAPABILITIES,
				'wcpos_version'  => VERSION,
			),
			200
		);
	}

	/**
	 * Allow the status endpoint to report an unhealthy sync store.
	 */
	protected function health_gated(): bool {
		return false;
	}
}
