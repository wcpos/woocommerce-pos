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
