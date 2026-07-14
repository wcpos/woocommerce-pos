<?php
/**
 * Sync status REST API controller.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reports sync store health.
 */
final class Status_Controller extends WP_REST_Controller {
	/**
	 * Register the sync status route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
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
	 * Check whether the current user can inspect sync store health.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function check_permissions( WP_REST_Request $request ): bool {
		return current_user_can( 'manage_woocommerce' );
	}
}
