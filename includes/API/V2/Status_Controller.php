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
	 *
	 * Append new names; never rename or remove one.
	 */
	private const CAPABILITIES = array( 'products_id_fast_path' );

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
	 * Declare the route's protocol-gate classification.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public function wcpos_route_classifications(): array {
		return array( 'protocol_exempt' => array( '/wcpos/v2/status' ) );
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
