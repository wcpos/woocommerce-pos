<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Product_Variations_Controller') ) {
	return;
}

use Exception;
use WC_Data;
use WC_REST_Product_Variations_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Product Tgas controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Product_Variations_Controller methods
 */
class Product_Variations_Controller extends WC_REST_Product_Variations_Controller {
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
		add_filter( 'woocommerce_pos_rest_dispatch_product_variations_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
	}

	/**
	 * Add custom fields to the product schema.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();
	
		// Add the 'barcode' property if 'properties' exists and is an array
		if (isset($schema['properties']) && \is_array($schema['properties'])) {
			$schema['properties']['barcode'] = array(
				'description' => __('Barcode', 'woocommerce-pos'),
				'type'        => 'string',
				'context'     => array('view', 'edit'),
				'readonly'    => false,
			);
		}

		// Check for 'stock_quantity' and allow decimal
		if ($this->wcpos_allow_decimal_quantities()     &&
			isset($schema['properties']['stock_quantity']) &&
			\is_array($schema['properties']['stock_quantity'])) {
			$schema['properties']['stock_quantity']['type'] = 'string';
		}

		return $schema;
	}


	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
	
		// Check if 'per_page' parameter exists and has a 'minimum' key before modifying
		if (isset($params['per_page']) && \is_array($params['per_page'])) {
			$params['per_page']['minimum'] = -1;
		}

		// Ensure 'orderby' is set and is an array before attempting to modify it
		if (isset($params['orderby']['enum']) && \is_array($params['orderby']['enum'])) {
			// Define new sorting options
			$new_sort_options = array(
				'sku',
				'barcode',
				'stock_quantity',
				'stock_status',
			);
			// Merge new options, avoiding duplicates
			$params['orderby']['enum'] = array_unique(array_merge($params['orderby']['enum'], $new_sort_options));
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
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
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
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'wcpos_variation_response' ), 10, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'wcpos_product_image_src' ), 10, 4 );
		add_action( 'woocommerce_rest_insert_product_variation_object', array( $this, 'wcpos_insert_product_variation_object' ), 10, 3 );
	}

	/**
	 * Filter the variation response.
	 *
	 * @param WP_REST_Response $response  The response object.
	 * @param WC_Data          $variation Product data.
	 * @param WP_REST_Request  $request   Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function wcpos_variation_response( WP_REST_Response $response, WC_Data $variation, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add the UUID to the product response
		$this->maybe_add_post_uuid( $variation );

		// Add the barcode to the product response
		$data['barcode'] = $this->wcpos_get_barcode( $variation );

		/*
		 * Backwards compatibility for WooCommerce < 8.3
		 *
		 * WooCommerce added 'parent_id' and 'name' to the variation response in 8.3
		 */
		if ( ! isset( $data['parent_id'] ) ) {
			$data['parent_id'] = $variation->get_parent_id();
		}
		if ( ! isset( $data['name'] ) ) {
			$data['name'] = \function_exists('wc_get_formatted_variation') ? wc_get_formatted_variation( $variation, true, false, false ) : '';
		}

		// Make sure we parse the meta data before returning the response
		$variation->save_meta_data(); // make sure the meta data is saved
		$data['meta_data'] = $this->wcpos_parse_meta_data( $variation );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Fires after a single object is created or updated via the REST API.
	 *
	 * @param WC_Data         $object   Inserted object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating object, false when updating.
	 */
	public function wcpos_insert_product_variation_object( WC_Data $object, WP_REST_Request $request, bool $creating ): void {
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
		$parent_id = $this->request['product_id'];

		$args = array(
			'post_type'      => 'product_variation',
			'post_status'    => 'publish',
			'post_parent'    => $parent_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$variation_query = new WP_Query( $args );

		try {
			$variation_ids = $variation_query->posts;

			return array_map( array( $this, 'wcpos_format_id' ), $variation_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product variation IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching product variation IDs.',
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
		$barcode_field = $this->wcpos_get_barcode_field();

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
					$args['orderby']  = 'meta_value_num';

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
