<?php

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Product_Variations_Controller' ) ) {
	return;
}

use Exception;
use WC_Data;
use WC_REST_Product_Variations_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WC_Product_Variation;

/**
 * Product Tgas controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Product_Variations_Controller methods
 */
class Product_Variations_Controller extends WC_REST_Product_Variations_Controller {
	use Traits\Product_Helpers;
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;
	use Traits\Query_Helpers;

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
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_product_variations_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();

			register_rest_route(
				$this->namespace,
				'/products/variations',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'wcpos_get_all_items' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $this->get_collection_params(),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);
		}
	}

	/**
	 * Add custom fields to the product schema.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		// Add the 'barcode' property if 'properties' exists and is an array
		if ( isset( $schema['properties'] ) && \is_array( $schema['properties'] ) ) {
			$schema['properties']['barcode'] = array(
				'description' => __( 'Barcode', 'woocommerce-pos' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
			);
		}

		// Check for 'stock_quantity' and allow decimal
		if ( $this->wcpos_allow_decimal_quantities() &&
			isset( $schema['properties']['stock_quantity'] ) &&
			\is_array( $schema['properties']['stock_quantity'] ) ) {
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
		if ( isset( $params['per_page'] ) && \is_array( $params['per_page'] ) ) {
			$params['per_page']['minimum'] = -1;
		}

		// Ensure 'orderby' is set and is an array before attempting to modify it
		if ( isset( $params['orderby']['enum'] ) && \is_array( $params['orderby']['enum'] ) ) {
			// Define new sorting options
			$new_sort_options = array(
				'sku',
				'barcode',
				'stock_quantity',
				'stock_status',
			);
			// Merge new options, avoiding duplicates
			$params['orderby']['enum'] = array_unique( array_merge( $params['orderby']['enum'], $new_sort_options ) );
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
		$this->wcpos_request = $request;
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
		add_filter( 'woocommerce_rest_product_variation_object_query', array( $this, 'wcpos_product_variation_query' ), 10, 2 );
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
			$data['name'] = \function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $variation, true, false, false ) : '';
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
	 * Filter the query arguments for a request.
	 *
	 * @param array           $args    Key value array of query var to query value.
	 * @param WP_REST_Request $request The request used.
	 *
	 * @return array $args Key value array of query var to query value.
	 */
	public function wcpos_product_variation_query( array $args, WP_REST_Request $request ) {
		// Check for wcpos_include/wcpos_exclude parameter.
		if ( isset( $request['wcpos_include'] ) || isset( $request['wcpos_exclude'] ) ) {
			// Add a custom WHERE clause to the query.
			add_filter( 'posts_where', array( $this, 'wcpos_posts_where_product_variation_include_exclude' ), 10, 2 );
		}

		return $args;
	}

	/**
	 * Filters the WHERE clause of the query.
	 *
	 * @param string   $where The WHERE clause of the query.
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 *
	 * @return string
	 */
	public function wcpos_posts_where_product_variation_include_exclude( string $where, WP_Query $query ) {
		global $wpdb;

		// Handle 'wcpos_include'
		if ( ! empty( $this->wcpos_request['wcpos_include'] ) ) {
			$include_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_include'] );
			$ids_format = implode( ',', array_fill( 0, count( $include_ids ), '%d' ) );
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID IN ($ids_format) ", $include_ids );
		}

		// Handle 'wcpos_exclude'
		if ( ! empty( $this->wcpos_request['wcpos_exclude'] ) ) {
			$exclude_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_exclude'] );
			$ids_format = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ($ids_format) ", $exclude_ids );
		}

		return $where;
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

		if ( $this->wcpos_pos_only_products_enabled() ) {
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

		// Add online_only check
		if ( $this->wcpos_pos_only_products_enabled() ) {
			$meta_query = array(
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

			// Combine meta queries
			$args['meta_query'] = $this->wcpos_combine_meta_queries( $args['meta_query'], $meta_query );

		};

		return $args;
	}

	/**
	 * Endpoint for getting all product variations, eg: search for sku or barcode.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 */
	public function wcpos_get_all_items( $request ) {
		$query_args = $this->prepare_objects_query( $request );
		if ( is_wp_error( current( $query_args ) ) ) {
			return current( $query_args );
		}

		// Check if 'search' param is set for SKU search
		if ( ! empty( $query_args['s'] ) ) {
			$barcode_field = $this->wcpos_get_barcode_field();
			$meta_query = array(
				'key' => '_sku',
				'value' => $query_args['s'],
				'compare' => 'LIKE',
			);

			if ( $barcode_field && '_sku' !== $barcode_field ) {
				$meta_query = array(
					'relation' => 'OR',
					$meta_query,
					array(
						'key' => $barcode_field,
						'value' => $query_args['s'],
						'compare' => 'LIKE',
					),
				);
			}

			// Combine meta queries
			$query_args['meta_query'] = $this->wcpos_combine_meta_queries( $query_args['meta_query'], $meta_query );

			unset( $query_args['s'] );
		}

		$query_results = $this->get_objects( $query_args );

		$objects = array();
		foreach ( $query_results['objects'] as $object ) {
			if ( ! \wc_rest_check_post_permissions( $this->post_type, 'read', $object->get_id() ) ) {
				continue;
			}

			$data      = $this->prepare_object_for_response( $object, $request );
			$objects[] = $this->prepare_response_for_collection( $data );
		}

		$page      = (int) $query_args['paged'];
		$max_pages = $query_results['pages'];

		$response = rest_ensure_response( $objects );
		$response->header( 'X-WP-Total', $query_results['total'] );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		/**
		 * Note: custom endpoint for getting all product variations
		 */
		$base          = 'products/variations';
		$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $base ) ) );

		if ( $page > 1 ) {
			$prev_page = $page - 1;
			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}
			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );
			$response->link_header( 'next', $next_link );
		}

		return $response;
	}
}
