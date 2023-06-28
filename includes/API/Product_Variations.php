<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Data;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WCPOS\WooCommercePOS\Logger;

class Product_Variations extends Abstracts\WC_Rest_API_Modifier {
	use Traits\Product_Helpers;
    use Traits\Uuid_Handler;

	/**
	 * Products constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
        $this->get_all_postmeta_uuids();
        $this->add_product_image_src_filter();

		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'product_response' ), 10, 3 );
	}

	/**
	 * Filter the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Data          $product  Product data.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function product_response( WP_REST_Response $response, WC_Data $product, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

        // Add the UUID to the product response
        $this->maybe_add_post_uuid( $product );

        // Add the barcode to the product response
        $data['barcode'] = $this->get_barcode( $product );

        // Truncate the product description and short_description
        $data['description'] = $this->truncate_text( $data['description'] );

        /**
         * Make sure we parse the meta data before returning the response
         */
        $product->save_meta_data(); // make sure the meta data is saved
        $data['meta_data'] = $this->parse_meta_data( $product );

        $response->set_data( $data );
        $this->log_large_rest_response( $response, $product );

		return $response;
	}

	/**
	 * Returns array of all product ids, name.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function get_all_posts( array $fields = array() ): array {
		$parent_id = $this->request['product_id'];

		$args = array(
			'post_type' => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
			'posts_per_page' => -1,
			'fields' => 'ids',
		);

		$variation_query = new WP_Query( $args );

		try {
			$variation_ids = $variation_query->posts;
			return array_map( array( $this, 'format_id' ), $variation_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product IDs: ' . $e->getMessage() );
			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching variation IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
