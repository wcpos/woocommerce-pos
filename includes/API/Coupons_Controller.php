<?php
/**
 * Coupons_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Coupons_Controller' ) ) {
	return;
}

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Coupon;
use WC_Meta_Data;
use WC_REST_Coupons_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coupons controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Coupons_Controller methods
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

		/**
		 * Check if the request is for all coupons and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all coupon IDs.
		 */
		if ( -1 == $request->get_param( 'posts_per_page' ) && null !== $request->get_param( 'fields' ) ) {
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
		// NOTE: WC_Coupon does not implement get_type(), so we cannot use
		// the shared maybe_add_post_uuid() from Uuid_Handler.
		$this->maybe_add_coupon_uuid( $coupon );

		// Make sure we parse the meta data before returning the response.
		$coupon->save_meta_data();
		$data['meta_data'] = $this->wcpos_parse_meta_data( $coupon );

		// Set changes to the response data.
		$response->set_data( $data );

		// Log large responses.
		$this->wcpos_log_large_rest_response( $response, $coupon->get_id() );

		return $response;
	}

	/**
	 * Ensure the coupon has a valid UUID.
	 *
	 * WC_Coupon does not implement get_type() (unlike WC_Product and WC_Order),
	 * so we cannot use the shared maybe_add_post_uuid() from Uuid_Handler.
	 * This method provides equivalent functionality for coupons.
	 *
	 * @param WC_Coupon $coupon The coupon object.
	 */
	private function maybe_add_coupon_uuid( WC_Coupon $coupon ): void {
		$meta_data = $coupon->get_meta_data();
		$uuids     = array_filter(
			$meta_data,
			function ( WC_Meta_Data $meta ) {
				return '_woocommerce_pos_uuid' === $meta->key;
			}
		);

		$uuid_values = array_map(
			function ( WC_Meta_Data $meta ) {
				return $meta->value;
			},
			$uuids
		);

		// Re-index to ensure sequential keys.
		$uuid_values = array_values( $uuid_values );

		// If more than one UUID exists, keep the first and delete the rest.
		if ( count( $uuid_values ) > 1 ) {
			$first_uuid_key = key( $uuids );
			foreach ( $uuids as $key => $uuid_meta ) {
				if ( $key === $first_uuid_key ) {
					continue;
				}
				$coupon->delete_meta_data_by_mid( $uuid_meta->id );
			}
			$uuid_values = array( reset( $uuids )->value );
		}

		$should_update_uuid = empty( $uuid_values )
			|| ( isset( $uuid_values[0] ) && ! Uuid::isValid( $uuid_values[0] ) );

		if ( $should_update_uuid ) {
			$coupon->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
		}
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

		// Start timing execution.
		$start_time = microtime( true );

		$modified_after        = $request->get_param( 'modified_after' );
		$fields                = $request->get_param( 'fields' );
		$id_with_modified_date = array( 'id', 'date_modified_gmt' ) === $fields;
		$select_fields         = $id_with_modified_date ? 'ID as id, post_modified_gmt as date_modified_gmt' : 'ID as id';

		$sql  = "SELECT DISTINCT {$select_fields} FROM {$wpdb->posts}";
		$sql .= " WHERE post_type = 'shop_coupon' AND post_status = 'publish'";

		// Add modified_after condition if provided.
		if ( $modified_after ) {
			$timestamp = strtotime( $modified_after );
			if ( false === $timestamp ) {
				return new \WP_Error(
					'woocommerce_pos_rest_invalid_modified_after',
					'Invalid modified_after parameter.',
					array( 'status' => 400 )
				);
			}
			$modified_after_date = gmdate( 'Y-m-d H:i:s', $timestamp );
			$sql .= $wpdb->prepare( ' AND post_modified_gmt > %s', $modified_after_date );
		}

		// Order by post_date DESC to maintain order consistency.
		$sql .= " ORDER BY {$wpdb->posts}.post_date DESC";

		try {
			$results           = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built with prepare() above.
			$formatted_results = $this->wcpos_format_all_posts_response( $results );

			// Get the total number of coupons for the given criteria.
			$total = \count( $formatted_results );

			// Collect execution time and server load.
			$execution_time    = microtime( true ) - $start_time;
			$execution_time_ms = number_format( $execution_time * 1000, 2 );
			$server_load       = $this->get_server_load();

			$response = rest_ensure_response( $formatted_results );
			$response->header( 'X-WP-Total', (string) $total );
			$response->header( 'X-Execution-Time', $execution_time_ms . ' ms' );
			$response->header( 'X-Server-Load', json_encode( $server_load ) );

			return $response;
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching coupon IDs: ' . $e->getMessage() );

			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching coupon IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
