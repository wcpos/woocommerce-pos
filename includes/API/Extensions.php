<?php
/**
 * Extensions REST API controller.
 *
 * Serves the extension catalog with local plugin status.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Extensions as ExtensionsService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Extensions controller class.
 */
class Extensions extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'extensions';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/seen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_seen' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Mark all current catalog extensions as seen by the current user.
	 *
	 * Stores the catalog slugs in user meta so the "new" badge can be computed.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_seen( WP_REST_Request $request ): WP_REST_Response {
		$service = ExtensionsService::instance();
		$catalog = $service->get_catalog();
		$slugs   = array_column( $catalog, 'slug' );

		update_user_meta( get_current_user_id(), '_wcpos_seen_extension_slugs', $slugs );

		return new WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * Get all extensions with status.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$service    = ExtensionsService::instance();
		$extensions = $service->get_extensions();

		$response = new WP_REST_Response( $extensions );
		$response->header( 'X-WP-Total', (string) \count( $extensions ) );

		return $response;
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permissions( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view extensions.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
