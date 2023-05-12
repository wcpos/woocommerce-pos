<?php

namespace WCPOS\WooCommercePOS\API;

use Ramsey\Uuid\Uuid;
use WP_REST_Request;
use WP_REST_Response;

class Product_Categories {
	private $request;

	/**
	 * Customers constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		add_filter( 'woocommerce_rest_prepare_product_cat', array( $this, 'product_categories_response' ), 10, 3 );
	}

	/**
	 * Filter the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param object $item      The original term object.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function product_categories_response( WP_REST_Response $response, object $item, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		/**
		 * Make sure the product has a uuid
		 */
		$uuid = get_term_meta( $item->term_id, '_woocommerce_pos_uuid', true );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			add_term_meta( $item->term_id, '_woocommerce_pos_uuid', $uuid, true );
		}
		$data['uuid'] = $uuid;

		/**
		 * Reset the new response data
		 */
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Returns array of all product category ids
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function get_all_posts( array $fields = array() ): array {
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
		);

		$product_category_ids = get_terms( $args );

		// Convert the array of cat IDs to an array of objects with cat IDs as integers
		return array_map( array( $this, 'format_id' ), $product_category_ids );
	}

	/**
	 * @param string $product_category_id
	 *
	 * @return object
	 */
	private function format_id( string $product_category_id ): object {
		return (object) array( 'id' => (int) $product_category_id );
	}
}
