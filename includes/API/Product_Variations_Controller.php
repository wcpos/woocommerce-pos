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
use WP_Error;
use WCPOS\WooCommercePOS\Services\Settings;

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
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
		$this->wcpos_request = $request;

		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'wcpos_variation_response' ), 10, 3 );
		add_action( 'woocommerce_rest_insert_product_variation_object', array( $this, 'wcpos_insert_product_variation_object' ), 10, 3 );
		add_filter( 'woocommerce_rest_product_variation_object_query', array( $this, 'wcpos_product_variation_query' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'wcpos_posts_search' ), 10, 2 );

		/**
		 * Check if the request is for all products and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all product IDs.
		 */
		if ( $request->get_param( 'posts_per_page' ) == -1 && $request->get_param( 'fields' ) !== null ) {
			return $this->wcpos_get_all_posts( $request );
		}

		return $dispatch_result;
	}

	/**
	 *
	 */
	public function register_routes() {
		parent::register_routes();

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

				// Check if the response has an image
		if ( isset( $data['image'] ) && ! empty( $data['image'] ) && isset( $data['image']['id'] ) ) {
			// Replace the full size 'src' with the URL of the medium size image.
			$medium_image_data = image_downsize( $data['image']['id'], 'medium' );

			if ( $medium_image_data && isset( $medium_image_data[0] ) ) {
				$data['image']['src'] = $medium_image_data[0];
			}
		}

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
	public function wcpos_insert_product_variation_object( WC_Data $object, WP_REST_Request $request, $creating ): void {
		$barcode_field = $this->wcpos_get_barcode_field();
		if ( $request->has_param( 'barcode' ) ) {
			$barcode = $request->get_param( 'barcode' );
			$object->update_meta_data( $barcode_field, $barcode );
			$object->save_meta_data();
		}
	}

	/**
	 * Filter to adjust the WordPress search SQL query
	 * - Search for the variation SKU and barcode
	 * - Do not search variation description.
	 *
	 * @param string   $search Search string.
	 * @param WP_Query $wp_query WP_Query object.
	 *
	 * @return string
	 */
	public function wcpos_posts_search( string $search, WP_Query $wp_query ) {
		global $wpdb;

		if ( empty( $search ) ) {
			return $search; // skip processing - no search term in query.
		}

		$q = $wp_query->query_vars;
		$n = ! empty( $q['exact'] ) ? '' : '%';
		$search_terms = (array) $q['search_terms'];

		// Fields in the main 'posts' table.
		$post_fields = array(); // nothing at the moment for variations.

		// Meta fields to search.
		$meta_fields = array( '_sku' );
		$barcode_field = $this->wcpos_get_barcode_field();
		if ( '_sku' !== $barcode_field ) {
			$meta_fields[] = $barcode_field;
		}

		$barcode_field = $this->wcpos_get_barcode_field();
		if ( '_sku' !== $barcode_field ) {
			$fields_to_search[] = $barcode_field;
		}

		$search_conditions = array();

		foreach ( $search_terms as $term ) {
			$term = $n . $wpdb->esc_like( $term ) . $n;

			// Search in post fields
			foreach ( $post_fields as $field ) {
				if ( ! empty( $field ) ) {
					$search_conditions[] = $wpdb->prepare( "($wpdb->posts.$field LIKE %s)", $term );
				}
			}

			// Search in meta fields
			foreach ( $meta_fields as $field ) {
				$search_conditions[] = $wpdb->prepare( '(pm1.meta_value LIKE %s AND pm1.meta_key = %s)', $term, $field );
			}
		}

		if ( ! empty( $search_conditions ) ) {
			$search = ' AND (' . implode( ' OR ', $search_conditions ) . ') ';
			if ( ! is_user_logged_in() ) {
				$search .= " AND ($wpdb->posts.post_password = '') ";
			}
		}

		return $search;
	}

	/**
	 * Filters the JOIN clause of the query.
	 *
	 * @param string   $join  The JOIN clause of the query.
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 *
	 * @return string
	 */
	public function wcpos_posts_join_to_posts_search( string $join, WP_Query $query ) {
		global $wpdb;

		if ( ! empty( $query->query_vars['s'] ) && false === strpos( $join, 'pm1' ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} pm1 ON {$wpdb->posts}.ID = pm1.post_id ";
		}

		return $join;
	}

	/**
	 * Filters the GROUP BY clause of the query.
	 *
	 * @param string   $groupby The GROUP BY clause of the query.
	 * @param WP_Query $query   The WP_Query instance (passed by reference).
	 *
	 * @return string
	 */
	public function wcpos_posts_groupby_posts_search( string $groupby, WP_Query $query ) {
		global $wpdb;

		if ( ! empty( $query->query_vars['s'] ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}

		return $groupby;
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
		if ( ! empty( $request['search'] ) ) {
			// We need to set the query up for a postmeta join.
			add_filter( 'posts_join', array( $this, 'wcpos_posts_join_to_posts_search' ), 10, 2 );
			add_filter( 'posts_groupby', array( $this, 'wcpos_posts_groupby_posts_search' ), 10, 2 );
		}

		// if POS only products are enabled, exclude online-only products
		if ( $this->wcpos_pos_only_products_enabled() ) {
			add_filter( 'posts_where', array( $this, 'wcpos_posts_where_product_variation_exclude_online_only' ), 10, 2 );
		}

		// Check for wcpos_include/wcpos_exclude parameter.
		// NOTE: do this after POS visibility filter so that takes precedence.
		if ( isset( $request['wcpos_include'] ) || isset( $request['wcpos_exclude'] ) ) {
			add_filter( 'posts_where', array( $this, 'wcpos_posts_where_product_variation_include_exclude' ), 20, 2 );
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
	public function wcpos_posts_where_product_variation_exclude_online_only( string $where, WP_Query $query ) {
		global $wpdb;

		$settings_instance = Settings::instance();
		$online_only = $settings_instance->get_online_only_variations_visibility_settings();
		$online_only_ids = isset( $online_only['ids'] ) && is_array( $online_only['ids'] ) ? $online_only['ids'] : array();

		// Exclude online-only product IDs if POS only products are enabled
		if ( ! empty( $online_only_ids ) ) {
			$online_only_ids = array_map( 'intval', (array) $online_only_ids );
			$ids_format = implode( ',', array_fill( 0, count( $online_only_ids ), '%d' ) );
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ($ids_format) ", $online_only_ids );
		}

		return $where;
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
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function wcpos_get_all_posts( $request ) {
		global $wpdb;

		// Start timing execution.
		$start_time = microtime( true );

		$modified_after = $request->get_param( 'modified_after' );
		$fields = $request->get_param( 'fields' );
		$parent_id = (int) $this->wcpos_request->get_param( 'product_id' );
		$id_with_modified_date = array( 'id', 'date_modified_gmt' ) === $fields;

		// Build the SELECT clause based on requested fields.
		$select_fields = $id_with_modified_date ? 'ID as id, post_modified_gmt as date_modified_gmt' : 'ID as id';

		// Initialize the SQL query.
		$sql = "SELECT DISTINCT {$select_fields} FROM {$wpdb->posts}";
		$sql .= " WHERE {$wpdb->posts}.post_type = 'product_variation' AND {$wpdb->posts}.post_status = 'publish'";

		// If the '_pos_visibility' condition needs to be applied.
		if ( $this->wcpos_pos_only_products_enabled() ) {
			$settings_instance = Settings::instance();
			$online_only = $settings_instance->get_online_only_variations_visibility_settings();

			if ( isset( $online_only['ids'] ) && is_array( $online_only['ids'] ) && ! empty( $online_only['ids'] ) ) {
				$online_only_ids = array_map( 'intval', (array) $online_only['ids'] );
				$ids_format = implode( ',', array_fill( 0, count( $online_only_ids ), '%d' ) );
				$sql .= $wpdb->prepare( " AND ID NOT IN ($ids_format) ", $online_only_ids );
			}
		}

		// Add modified_after condition if provided.
		if ( $modified_after ) {
			$modified_after_date = date( 'Y-m-d H:i:s', strtotime( $modified_after ) );
			$sql .= $wpdb->prepare( ' AND post_modified_gmt > %s', $modified_after_date );
		}

		// Dynamically add the post_parent clause if a parent ID is provided.
		if ( $parent_id ) {
			$sql = $wpdb->prepare( $sql . " AND {$wpdb->posts}.post_parent = %d", $parent_id );
		}

		try {
			// Execute the query.
			$results = $wpdb->get_results( $sql, ARRAY_A );
			$formatted_results = $this->wcpos_format_all_posts_response( $results );

			// Get the total number of orders for the given criteria.
			$total = count( $formatted_results );

			// Collect execution time and server load.
			$execution_time = microtime( true ) - $start_time;
			$execution_time_ms = number_format( $execution_time * 1000, 2 );
			$server_load = $this->get_server_load();

			$response = rest_ensure_response( $formatted_results );
			$response->header( 'X-WP-Total', (int) $total );
			$response->header( 'X-Execution-Time', $execution_time_ms . ' ms' );
			$response->header( 'X-Server-Load', json_encode( $server_load ) );

			return $response;
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product variation IDs: ' . $e->getMessage() );

			return new WP_Error(
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

	/**
	 * Endpoint for getting all product variations, eg: search for sku or barcode.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 */
	public function wcpos_get_all_items( $request ) {
		return parent::get_items( $request );
	}
}
