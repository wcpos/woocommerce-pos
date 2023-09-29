<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use WC_Data;
use WC_Product;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_HTTP_Response;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @property string $search_term
 */
class Products extends Abstracts\WC_Rest_API_Modifier {
	use Traits\Product_Helpers;
    use Traits\Uuid_Handler;

	/**
	 * Products constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->uuids = $this->get_all_postmeta_uuids();
        $this->add_product_image_src_filter();

		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_request_before_callbacks' ), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'product_response' ), 10, 3 );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'product_query' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'posts_search' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
		add_filter( 'woocommerce_rest_product_schema', array( $this, 'add_barcode_to_product_schema' ) );
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'insert_product_object' ), 10, 3 );
        add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'prevent_description_update' ), 10, 3 );
	}

	/**
	 * @param $schema
	 * @return array
	 */
	public function add_barcode_to_product_schema( $schema ): array {
		$schema['properties']['barcode'] = array(
			'description' => __( 'Barcode', 'woocommerce-pos' ),
			'type'        => 'string',
			'context'     => array( 'view', 'edit' ),
		);
		return $schema;
	}

	/**
	 * Filters the response before executing any REST API callbacks.
	 *
	 * We can use this filter to bypass data validation checks
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
	 *                                                                   Usually a WP_REST_Response or WP_Error.
	 * @param array                                            $handler  Route handler used for the request.
	 * @param WP_REST_Request                                  $request  Request used to generate the response.
	 */
	public function rest_request_before_callbacks( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			// Check if the error code 'rest_invalid_param' exists
			if ( $response->get_error_message( 'rest_invalid_param' ) ) {
				// Get the error data for 'rest_invalid_param'
				$error_data = $response->get_error_data( 'rest_invalid_param' );

				// Check if the invalid parameter was 'line_items'
				if ( array_key_exists( 'stock_quantity', $error_data['params'] ) ) {
					// Get the 'line_items' details
					$line_items_details = $error_data['details']['stock_quantity'];

					//
					if ( $line_items_details['code'] === 'rest_invalid_type' && woocommerce_pos_get_settings( 'general', 'decimal_qty' ) ) {
							unset( $error_data['params']['stock_quantity'], $error_data['details']['stock_quantity'] );
					}
				}

				// Check if the invalid parameter was 'orderby'
				if ( array_key_exists( 'orderby', $error_data['params'] ) ) {
					// Get the 'orderby' details
					$orderby_details = $error_data['details']['orderby'];

					// Get the 'orderby' request
					$orderby_request = $request->get_param( 'orderby' );

					// Extended 'orderby' values
					$orderby_extended = array(
						'stock_quantity',
					);

					// Check if 'orderby' has 'rest_not_in_enum', but is in the extended 'orderby' values
					if ( $orderby_details['code'] === 'rest_not_in_enum' && in_array( $orderby_request, $orderby_extended, true ) ) {
						unset( $error_data['params']['orderby'], $error_data['details']['orderby'] );
					}
				}

				// Check if $error_data['params'] is empty
				if ( empty( $error_data['params'] ) ) {
					return null;
				} else {
					// Remove old error data and add new error data
					$error_message = 'Invalid parameter(s): ' . implode( ', ', array_keys( $error_data['params'] ) ) . '.';

					$response->remove( 'rest_invalid_param' );
					$response->add( 'rest_invalid_param', $error_message, $error_data );
				}
			}
		}

		return $response;
	}


    /**
     * Filters the product object before it is inserted or updated via the REST API.
     *
     * This filter is used to prevent the description and short_description fields
     * from being updated when a product is updated via the REST API. It does this by
     * resetting these fields to their current values in the database before the
     * product object is updated.
     *
     * Ask me why this is here 😓
     *
     * @param WC_Product $product  The product object that is being inserted or updated.
     *                                   This object is mutable.
     * @param WP_REST_Request $request  The request object.
     * @param bool $creating If is creating a new object.
     *
     * @return WC_Product The modified product object.
     */
    public function prevent_description_update( WC_Product $product, WP_REST_Request $request, bool $creating ): WC_Product {
        // If product is being updated
        if ( ! $creating ) {
            $original_product = wc_get_product( $product->get_id() );

            // Reset the description and short description to the original values
            $product->set_description( $original_product->get_description() );
            $product->set_short_description( $original_product->get_short_description() );
        }

        return $product;
    }


	/**
	 * Fires after a single object is created or updated via the REST API.
	 *
	 * @param WC_Data         $object    Inserted object.
	 * @param WP_REST_Request $request   Request object.
	 * @param boolean $creating  True when creating object, false when updating.
	 */
	public function insert_product_object( WC_Data $object, WP_REST_Request $request, bool $creating ) {
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$barcode = $request->get_param( 'barcode' );
		if ( $barcode ) {
			$object->update_meta_data( $barcode_field, $barcode );
			$object->save_meta_data();
		}
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
	public function product_response( WP_REST_Response $response, WC_Product $product, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

        // Add the UUID to the product response
        $this->maybe_add_post_uuid( $product );

		// Add the barcode to the product response
		$data['barcode'] = $this->get_barcode( $product );

		/**
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
			if ( $encoded_price === false ) {
				// JSON encode failed, log the original array for debugging
				Logger::log( 'JSON encoding of price array failed: ' . json_last_error_msg(), $price_array );
			} else {
				// Update the meta data with the successfully encoded price data
				$product->update_meta_data( '_woocommerce_pos_variable_prices', $encoded_price );
			}
		}

		/**
		 * Make sure we parse the meta data before returning the response
		 */
        $product->save_meta_data(); // make sure the meta data is saved
		$data['meta_data'] = $this->parse_meta_data( $product );

        // Set any changes to the response data
		$response->set_data( $data );
        // $this->log_large_rest_response( $response, $product->get_id() );

		return $response;
	}

	/**
	 * Filter the query arguments for a request.
	 *
	 * @param array           $args    Key value array of query var to query value.
	 * @param WP_REST_Request $request The request used.
	 *
	 * @return array $args Key value array of query var to query value.
	 */
	public function product_query( array $args, WP_REST_Request $request ): array {
		if ( ! empty( $request['search'] ) ) {
			// We need to set the query up for a postmeta join
			add_filter( 'posts_join', array( $this, 'barcode_postmeta_join' ), 10, 2 );
			add_filter( 'posts_groupby', array( $this, 'barcode_postmeta_groupby' ), 10, 2 );
		}

		return $args;
	}


	/**
	 * Filters the JOIN clause of the query.
	 *
	 * @param string $join  The JOIN clause of the query.
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 */
	public function barcode_postmeta_join( string $join, WP_Query $query ): string {
		global $wpdb;

		if ( isset( $query->query_vars['s'] ) ) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS postmeta_barcode ON {$wpdb->posts}.ID = postmeta_barcode.post_id";
		}

		return $join;
	}

	/**
	 * Filters the GROUP BY clause of the query.
	 *
	 * @param string   $groupby The GROUP BY clause of the query.
	 * @param WP_Query $query   The WP_Query instance (passed by reference).
	 */
	public function barcode_postmeta_groupby( string $groupby, WP_Query $query ): string {
		global $wpdb;

		if ( ! empty( $query->query_vars['s'] ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}

		return $groupby;
	}


	/**
	 * Filters all query clauses at once, for convenience.
	 *
	 * Covers the WHERE, GROUP BY, JOIN, ORDER BY, DISTINCT,
	 * fields (SELECT), and LIMIT clauses.
	 *
	 * @param string[] $clauses {
	 *     Associative array of the clauses for the query.
	 *
	 *     @type string $where    The WHERE clause of the query.
	 *     @type string $groupby  The GROUP BY clause of the query.
	 *     @type string $join     The JOIN clause of the query.
	 *     @type string $orderby  The ORDER BY clause of the query.
	 *     @type string $distinct The DISTINCT clause of the query.
	 *     @type string $fields   The SELECT clause of the query.
	 *     @type string $limits   The LIMIT clause of the query.
	 * }
	 * @param WP_Query $wp_query   The WP_Query instance (passed by reference).
	 */
	public function posts_clauses( array $clauses, WP_Query $wp_query ): array {
		global $wpdb;

		// add option to order by stock quantity
		if ( isset( $wp_query->query_vars['orderby'] ) && 'stock_quantity' === $wp_query->query_vars['orderby'] ) {
			// Join the postmeta table to access the stock data
			$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS stock_meta ON {$wpdb->posts}.ID = stock_meta.post_id AND stock_meta.meta_key='_stock'";

			// Order the query results: records with _stock meta_value first, then NULL, then items without _stock meta_key
			$order = isset( $wp_query->query_vars['order'] ) ? $wp_query->query_vars['order'] : 'DESC';
			$clauses['orderby']  = 'CASE';
			$clauses['orderby'] .= ' WHEN stock_meta.meta_value IS NOT NULL THEN 1';
			$clauses['orderby'] .= ' WHEN stock_meta.meta_value IS NULL THEN 2';
			$clauses['orderby'] .= ' ELSE 3';
			$clauses['orderby'] .= " END {$order}, COALESCE(stock_meta.meta_value+0, 0) {$order}";
		}

		return $clauses;
	}



	/**
	 * Filter to adjust the WordPress search SQL query
	 * - Search for the product title and SKU and barcode
	 * - Do not search product description
	 *
	 * @param string $search
	 * @param WP_Query $wp_query
	 */
	public function posts_search( string $search, WP_Query $wp_query ): string {
		global $wpdb;

		if ( ! empty( $search ) && ! empty( $wp_query->query_vars['search_terms'] ) ) {
			$q = $wp_query->query_vars;
			$n = ! empty( $q['exact'] ) ? '' : '%';

			$search_array = array();

			foreach ( (array) $q['search_terms'] as $term ) {
				$like_term = $wpdb->esc_like( $term );
				$search_array[] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $n . $like_term . $n );

				// Search in _sku field
                $search_array[] = $wpdb->prepare( "({$wpdb->postmeta}.meta_key = '_sku' AND {$wpdb->postmeta}.meta_value LIKE %s)", $n . $like_term . $n );

				// Search in barcode field
				$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
				if ( $barcode_field !== '_sku' ) {
                    $search_array[] = $wpdb->prepare( "(postmeta_barcode.meta_key = %s AND postmeta_barcode.meta_value LIKE %s)", $barcode_field, $n . $like_term . $n );
				}
			}

			if ( ! is_user_logged_in() ) {
				$search_array[] = "{$wpdb->posts}.post_password = ''";
			}

			$search = ' AND (' . implode( ' OR ', $search_array ) . ')';
		}

		return $search;
	}


	/**
	 * Returns array of all product ids, name.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function get_all_posts( array $fields = array() ): array {
		$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		$args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		);

		if ( $pos_only_products ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key' => '_pos_visibility',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => '_pos_visibility',
					'value' => 'online_only',
					'compare' => '!=',
				),
			);
		}

		$product_query = new WP_Query( $args );

		try {
			$product_ids = $product_query->posts;
			return array_map( array( $this, 'format_id' ), $product_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching product IDs: ' . $e->getMessage() );
			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching product IDs.',
				array( 'status' => 500 )
			);
		}
	}

}
