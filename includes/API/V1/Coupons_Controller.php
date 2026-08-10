<?php
/**
 * Coupons_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Coupons_Controller' ) ) {
	return;
}

use Exception;
use WC_Coupon;
use WC_REST_Coupons_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coupons controller class.
 *
 * Extends WC_REST_Coupons_Controller directly under the wcpos/v1
 * namespace and adds POS-specific behaviour (UUID, permissions,
 * optimised bulk-ID queries).
 */
class Coupons_Controller extends WC_REST_Coupons_Controller {
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Store the request object for use in lifecycle methods.
	 *
	 * @var WP_REST_Request
	 */
	protected $wcpos_request;

	/**
	 * Create a single coupon.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$invalid_meta = $this->wcpos_sanitize_meta_data_param( $request );
		if ( is_wp_error( $invalid_meta ) ) {
			return $invalid_meta;
		}

		return parent::create_item( $request );
	}

	/**
	 * Update a single coupon.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		$invalid_meta = $this->wcpos_sanitize_meta_data_param( $request );
		if ( is_wp_error( $invalid_meta ) ) {
			return $invalid_meta;
		}

		return parent::update_item( $request );
	}

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
		$this->wcpos_request = $request;

		add_filter( 'woocommerce_rest_prepare_shop_coupon_object', array( $this, 'wcpos_coupon_response' ), 10, 3 );
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		// The post-date touch that used to be installed here is now registered
		// unconditionally at plugins_loaded (Sync\Coupon_Modified_Date), so it also
		// covers wp-admin/WP-CLI/third-party coupon saves this dispatch never saw.

		/**
		 * Check if the request is for all coupons and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all coupon IDs.
		 */
		if ( Bulk_ID_Fast_Path::supports_request( $request ) ) {
			return $this->wcpos_get_all_posts( $request );
		}

		return $dispatch_result;
	}

	/**
	 * Check whether a given request has permission to read coupons.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( current_user_can( 'access_woocommerce_pos' ) ) {
			return true;
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to read a coupon.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		if ( current_user_can( 'access_woocommerce_pos' ) ) {
			return true;
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Authorize coupon read access for POS users.
	 *
	 * The WC CRUD controller's get_items() calls wc_rest_check_post_permissions()
	 * per coupon. This filter ensures POS users can read coupons.
	 *
	 * @param bool   $permission The current permission.
	 * @param string $context    The context of the request (read, create, edit, delete).
	 * @param int    $object_id  The object ID.
	 * @param string $post_type  The post type.
	 *
	 * @return bool
	 */
	public function wcpos_check_permissions( $permission, $context, $object_id, $post_type ) {
		if ( ! $permission && 'shop_coupon' === $post_type && 'read' === $context ) {
			$permission = current_user_can( 'access_woocommerce_pos' );
		}

		return $permission;
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array $params The collection parameters.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// LANE SCOPE — v1 ONLY, deliberately NOT ported to v2 (lane audit 2026-08-10).
		// `orderby=code` has NO caller: the client's coupon list marks its `code`
		// column "disableSort": true and defaults to sorting on date_created_gmt
		// (monorepo packages/core/.../ui-settings/initial-settings.json), and the
		// coupon query hook only ever rewrites date_created/date_modified
		// (packages/query/src/hooks/coupons.ts). The v2 Catalog_Proxy_Controller
		// therefore leaves the wc/v3 enum unchanged on purpose. If a future
		// cashier-facing "sort by code" lands, port it deliberately — do not add
		// it back just to make the lanes look symmetrical.
		// Ensure 'orderby' is set and is an array before attempting to modify it.
		if ( isset( $params['orderby']['enum'] ) && \is_array( $params['orderby']['enum'] ) ) {
			$params['orderby']['enum'] = array_unique( array_merge( $params['orderby']['enum'], array( 'code' ) ) );
		}

		return $params;
	}

	/**
	 * Prepare objects query.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	protected function prepare_objects_query( $request ) {
		$args = parent::prepare_objects_query( $request );

		// Coupon code is stored as post_title.
		if ( isset( $request['orderby'] ) && 'code' === $request['orderby'] ) {
			$args['orderby'] = 'title';
		}

		if ( ! empty( $request['wcpos_include'] ) ) {
			$args['post__in'] = array_map( 'intval', (array) $request['wcpos_include'] );
		}

		if ( ! empty( $request['wcpos_exclude'] ) ) {
			$args['post__not_in'] = array_map( 'intval', (array) $request['wcpos_exclude'] );
		}

		return $args;
	}

	/**
	 * Filter coupon object returned from the REST API.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Coupon        $coupon   Coupon object used to create response.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function wcpos_coupon_response( WP_REST_Response $response, WC_Coupon $coupon, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add the UUID to the coupon response.
		// The retained coupon seam delegates to Uuid_Handler's shared Pos_Uuid path.
		$this->maybe_add_coupon_uuid( $coupon );

		// Parse the meta data before returning the response.
		$data['meta_data'] = $this->wcpos_parse_meta_data( $coupon );

		// Estimate response size and log if excessive.
		$this->wcpos_estimate_response_size( $data, $coupon->get_id(), 'Coupon' );

		// Set changes to the response data.
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Ensure the coupon has a valid UUID.
	 *
	 * Retains the legacy controller seam while delegating to the trait's shared
	 * Pos_Uuid path. That path no longer depends on WC_Data::get_type().
	 *
	 * @param WC_Coupon $coupon The coupon object.
	 */
	private function maybe_add_coupon_uuid( WC_Coupon $coupon ): void {
		$this->maybe_add_post_uuid( $coupon );
	}

	/**
	 * Returns array of all coupon IDs.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function wcpos_get_all_posts( $request ) {
		global $wpdb;

		$start_time    = microtime( true );
		$select_fields = Bulk_ID_Fast_Path::select_fields( $request, 'ID', 'post_modified_gmt' );

		$sql  = "SELECT DISTINCT {$select_fields} FROM {$wpdb->posts}";
		$sql .= " WHERE post_type = 'shop_coupon' AND post_status = 'publish'";

		$modified_after_date = Bulk_ID_Fast_Path::modified_after_gmt( $request, true );
		if ( is_wp_error( $modified_after_date ) ) {
			return $modified_after_date;
		}
		if ( $modified_after_date ) {
			$sql .= $wpdb->prepare( ' AND post_modified_gmt > %s', $modified_after_date );
		}

		$sql = Bulk_ID_Fast_Path::append_id_filters_sql( $sql, $request, "{$wpdb->posts}.ID" );
		$sql .= " ORDER BY {$wpdb->posts}.post_date DESC";

		try {
			$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built with prepare() above.

			return Bulk_ID_Fast_Path::response( $this, $results, $start_time );
		} catch ( Exception $e ) {
			return Bulk_ID_Fast_Path::fetch_error( 'Error fetching coupon IDs: ' . $e->getMessage(), 'Error fetching coupon IDs.' );
		}
	}
}
