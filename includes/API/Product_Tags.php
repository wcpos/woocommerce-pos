<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Product_Tags extends Abstracts\WC_Rest_API_Modifier {
    use Traits\Uuid_Handler;

	/**
	 * Customers constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		add_filter( 'woocommerce_rest_prepare_product_tag', array( $this, 'product_tags_response' ), 10, 3 );
	}

	/**
	 * Filter the tag response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param object $item      The original term object.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function product_tags_response( WP_REST_Response $response, object $item, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

        // Make sure the term has a uuid
        $data['uuid'] = $this->get_term_uuid( $item );

        // Reset the new response data
        $response->set_data( $data );

		return $response;
	}

	/**
	 * Returns array of all product tag ids
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function get_all_posts( array $fields = array() ): array {
		$args = array(
			'taxonomy'   => 'product_tag',
			'hide_empty' => false,
			'fields'     => 'ids',
		);

		try {
			$product_tag_ids = get_terms( $args );
			return array_map( array( $this, 'format_id' ), $product_tag_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product IDs: ' . $e->getMessage() );
			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching product tags IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
