<?php

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Product_Categories_Controller' ) ) {
	return;
}

use Exception;
use WC_REST_Product_Categories_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Product Tgas controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Product_Categories_Controller methods
 */
class Product_Categories_Controller extends WC_REST_Product_Categories_Controller {
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

		add_filter( 'woocommerce_rest_prepare_product_cat', array( $this, 'wcpos_product_categories_response' ), 10, 3 );
		add_filter( 'woocommerce_rest_product_cat_query', array( $this, 'wcpos_product_category_query' ), 10, 2 );

		/**
		 * Check if the request is for all categories and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all category IDs.
		 */
		if ( $request->get_param( 'posts_per_page' ) == -1 && $request->get_param( 'fields' ) !== null ) {
			return $this->wcpos_get_all_posts( $request );
		}

		return $dispatch_result;
	}

	/**
	 * Filter the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param object           $item     The original term object.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function wcpos_product_categories_response( WP_REST_Response $response, object $item, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Make sure the term has a uuid
		$data['uuid'] = $this->get_term_uuid( $item );

		// Reset the new response data
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Filter the tag query.
	 *
	 * @param array           $args    Query arguments.
	 * @param WP_REST_Request $request Request object.
	 */
	public function wcpos_product_category_query( array $args, WP_REST_Request $request ): array {
		// Check for wcpos_include/wcpos_exclude parameter.
		if ( isset( $request['wcpos_include'] ) || isset( $request['wcpos_exclude'] ) ) {
			// Add a custom WHERE clause to the query.
			add_filter( 'terms_clauses', array( $this, 'wcpos_terms_clauses_include_exclude' ), 10, 3 );
		}

		return $args;
	}

	/**
	 * Filters the terms query SQL clauses.
	 *
	 * @param string[] $clauses {
	 *     Associative array of the clauses for the query.
	 *
	 *     @type string $fields   The SELECT clause of the query.
	 *     @type string $join     The JOIN clause of the query.
	 *     @type string $where    The WHERE clause of the query.
	 *     @type string $distinct The DISTINCT clause of the query.
	 *     @type string $orderby  The ORDER BY clause of the query.
	 *     @type string $order    The ORDER clause of the query.
	 *     @type string $limits   The LIMIT clause of the query.
	 * }
	 * @param string[] $taxonomies An array of taxonomy names.
	 * @param array    $args       An array of term query arguments.
	 *
	 * @return string[] $clauses
	 */
	public function wcpos_terms_clauses_include_exclude( array $clauses, array $taxonomies, array $args ) {
		global $wpdb;

		// Handle 'wcpos_include'
		if ( ! empty( $this->wcpos_request['wcpos_include'] ) ) {
			$include_ids = array_map( 'intval', $this->wcpos_request['wcpos_include'] );
			$ids_format = implode( ',', array_fill( 0, count( $include_ids ), '%d' ) );
			$clauses['where'] .= $wpdb->prepare( " AND t.term_id IN ($ids_format) ", $include_ids );
		}

		// Handle 'wcpos_exclude'
		if ( ! empty( $this->wcpos_request['wcpos_exclude'] ) ) {
			$exclude_ids = array_map( 'intval', $this->wcpos_request['wcpos_exclude'] );
			$ids_format = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$clauses['where'] .= $wpdb->prepare( " AND t.term_id NOT IN ($ids_format) ", $exclude_ids );
		}

		return $clauses;
	}

	/**
	 * Returns array of all product category ids.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function wcpos_get_all_posts( $request ) {
		// Start timing execution.
		$start_time = microtime( true );
		$modified_after = $request->get_param( 'modified_after' );

		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
		);

		try {
			/**
			 * @TODO - terms don't have a modified date, it would be good to add a term_meta for last_update
			 * - ideally WooCommerce would provide a modified_after filter for terms
			 * - for now we'll just return empty for modified terms
			 */
			$results = $modified_after ? array() : get_terms( $args );

			// Format the response.
			$formatted_results = array_map(
				function ( $id ) {
					return array( 'id' => (int) $id );
				},
				$results
			);

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
			Logger::log( 'Error fetching product category IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching product category IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
