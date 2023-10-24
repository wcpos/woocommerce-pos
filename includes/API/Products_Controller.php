<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Products_Controller') ) {
	return;
}

use Exception;
use WC_Product;
use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Products controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Products_Controller methods
 */
class Products_Controller extends WC_REST_Products_Controller {
	use Traits\Product_Helpers;
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_products_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		parent::__construct();
	}

	/**
	 * Add custom fields to the product schema.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();
		
		$schema['properties']['barcode'] = array(
			'description' => __('Barcode', 'woocommerce-pos'),
			'type'        => 'string',
			'context'     => array('view', 'edit'),
			'readonly'    => false,
		);

		return $schema;
	}

	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		
		// Modify the per_page argument to allow -1
		$params['per_page']['minimum'] = -1;
		
		return $params;
	}

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ): mixed {
		$this->wcpos_register_wc_rest_api_hooks();
		$params = $request->get_params();

		// Optimised query for getting all product IDs
		if ( isset( $params['posts_per_page'] ) && -1 == $params['posts_per_page'] && isset( $params['fields'] ) ) {
			$dispatch_result = $this->wcpos_get_all_posts( $params['fields'] );
		}

		return $dispatch_result;
	}

	/**
	 * Register hooks to modify WC REST API response.
	 */
	public function wcpos_register_wc_rest_api_hooks(): void {
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'wcpos_product_response' ), 10, 3 );
	}

	/**
	 * Filter the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Product       $product  Product data.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function wcpos_product_response( WP_REST_Response $response, WC_Product $product, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add the UUID to the product response
		$this->maybe_add_post_uuid( $product );

		// Add the barcode to the product response
		$data['barcode'] = $this->get_barcode( $product );

		/*
		 * If product is variable, add the max and min prices and add them to the meta data
		 * @TODO - only need to update if there is a change
		 */
		if ( $product->is_type( 'variable' ) ) {
			// Initialize price variables
			$price_array = array(
				'price' => array(
					'min' => $product->get_variation_price(),
					'max' => $product->get_variation_price( 'max' ),
				),
				'regular_price' => array(
					'min' => $product->get_variation_regular_price(),
					'max' => $product->get_variation_regular_price( 'max' ),
				),
				'sale_price' => array(
					'min' => $product->get_variation_sale_price(),
					'max' => $product->get_variation_sale_price( 'max' ),
				),
			);

			// Try encoding the array into JSON
			$encoded_price = wp_json_encode( $price_array );

			// Check if the encoding was successful
			if ( false === $encoded_price ) {
				// JSON encode failed, log the original array for debugging
				Logger::log( 'JSON encoding of price array failed: ' . json_last_error_msg(), $price_array );
			} else {
				// Update the meta data with the successfully encoded price data
				$product->update_meta_data( '_woocommerce_pos_variable_prices', $encoded_price );
			}
		}

		// Make sure we parse the meta data before returning the response
		$product->save_meta_data(); // make sure the meta data is saved
		$data['meta_data'] = $this->wcpos_parse_meta_data( $product );

		// Set any changes to the response data
		$response->set_data( $data );
		// $this->log_large_rest_response( $response, $product->get_id() );

		return $response;
	}

	/**
	 * Returns array of all product ids, name.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function wcpos_get_all_posts( array $fields = array() ): array {
		$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		if ( $pos_only_products ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_pos_visibility',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_pos_visibility',
					'value'   => 'online_only',
					'compare' => '!=',
				),
			);
		}

		$product_query = new WP_Query( $args );

		try {
			$product_ids = $product_query->posts;

			return array_map( array( $this, 'wcpos_format_id' ), $product_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching product IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
