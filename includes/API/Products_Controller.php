<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Products_Controller') ) {
	return;
}

use Exception;
use WC_Data;
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
	 * Allow decimal quantities.
	 *
	 * @var bool
	 */
	protected $allow_decimal_quantities = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_products_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
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

		$decimal_qty = woocommerce_pos_get_settings( 'general', 'decimal_qty' );
		if ( \is_bool( $decimal_qty ) && $decimal_qty ) {
			$schema['properties']['stock_quantity']['type'] = 'string';
		}

		return $schema;
	}

	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		
		// Modify the per_page argument to allow -1
		$params['per_page']['minimum'] = -1;
		$params['orderby']['enum']     = array_merge(
			$params['orderby']['enum'],
			array( 'sku', 'barcode', 'stock_quantity', 'stock_status' )
		);

		$decimal_qty = woocommerce_pos_get_settings( 'general', 'decimal_qty' );
	
		// New stock params
		$new_stock_params = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		if ( \is_bool( $decimal_qty ) && $decimal_qty ) {
			$params['stock_quantity'] = isset($params['stock_quantity']) ?
				array_merge($params['stock_quantity'], $new_stock_params) :
				$new_stock_params;
		}
		
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
		add_filter( 'wp_get_attachment_image_src', array( $this, 'wcpos_product_image_src' ), 10, 4 );
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'wcpos_insert_product_object' ), 10, 3 );
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
		$data['barcode'] = $this->wcpos_get_barcode( $product );

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
	 * Fires after a single object is created or updated via the REST API.
	 *
	 * @param WC_Data         $object   Inserted object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating object, false when updating.
	 */
	public function wcpos_insert_product_object( WC_Data $object, WP_REST_Request $request, bool $creating ): void {
		$barcode_field = $this->wcpos_get_barcode_field();
		$barcode       = $request->get_param( 'barcode' );
		if ( $barcode ) {
			$object->update_meta_data( $barcode_field, $barcode );
			$object->save_meta_data();
		}
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

	/**
	 * Prepare objects query.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	protected function prepare_objects_query( $request ) {
		$args          = parent::prepare_objects_query( $request );
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );

		// Add custom 'orderby' options
		if ( isset( $request['orderby'] ) ) {
			switch ( $request['orderby'] ) {
				case 'sku':
					$args['meta_key'] = '_sku';
					$args['orderby']  = 'meta_value';

					break;
				case 'barcode':
					$args['meta_key'] = '_sku' !== $barcode_field ? $barcode_field : '_sku';
					$args['orderby']  = 'meta_value';

					break;
				case 'stock_quantity':
					$args['meta_key'] = '_stock';
					$args['orderby']  = 'meta_value';

					break;
				case 'stock_status':
					$args['meta_key'] = '_stock_status';
					$args['orderby']  = 'meta_value';

					break;
			}
		}

		return $args;
	}
}
