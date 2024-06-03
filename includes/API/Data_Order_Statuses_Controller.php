<?php
/**
 * REST API Data controller.
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Data_Controller' ) ) {
	return;
}

use WC_REST_Data_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use WP_REST_Response;

/**
 * REST API Data controller.
 *
 * Seems overkill to create a new class for this, but WC doesn't provide a way to get the list of order statuses.
 */
class Data_Order_Statuses_Controller extends WC_REST_Data_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

		/**
		 * Route base.
		 *
		 * @var string
		 */
	protected $rest_base = 'data/order_statuses';

		/**
		 * Return the list of order statuses.
		 *
		 * @param  WP_REST_Request $request Request data.
		 * @return WP_Error|WP_REST_Response
		 */
	public function get_items( $request ) {
		$statuses = wc_get_order_statuses();
		$data     = array();

		foreach ( $statuses as $status => $label ) {
			// Remove the 'wc-' prefix from the status
			$status = str_replace( 'wc-', '', $status );

			$resource = array(
				'status' => $status,
				'label'  => $label,
			);

			$item   = $this->prepare_item_for_response( (object) $resource, $request );
			$data[] = $this->prepare_response_for_collection( $item );
		}

		return rest_ensure_response( $data );
	}

		/**
		 * Prepare a data resource object for serialization.
		 *
		 * @param stdClass        $resource Resource data.
		 * @param WP_REST_Request $request  Request object.
		 * @return WP_REST_Response $response Response data.
		 */
	public function prepare_item_for_response( $resource, $request ) {
		$data = array(
			'status' => $resource->status,
			'label'  => $resource->label,
		);

		$data = $this->add_additional_fields_to_object( $data, $request );
		$data = $this->filter_response_by_context( $data, 'view' );

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $resource ) );

		return $response;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param object $item Data object.
	 * @return array Links for the given country.
	 */
	protected function prepare_links( $item ) {
		$links = array(
			// 'self'       => array(
			// 'href' => rest_url( sprintf( '/%s/%s/%s', $this->namespace, $this->rest_base, $item->status ) ),
			// ),
			'collection' => array(
				'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	/**
	 * Get the data index schema, conforming to JSON Schema.
	 *
	 * @since  3.5.0
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'data_index',
			'type'       => 'object',
			'properties' => array(
				'status'        => array(
					'description' => __( 'Order Status.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'label' => array(
					'description' => __( 'Order Status Label.', 'woocommerce-pos' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Check whether a given request has permission to view order statuses.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}
		return parent::get_items_permissions_check( $request );
	}
}
