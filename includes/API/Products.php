<?php

namespace WCPOS\WooCommercePOS\API;

use Ramsey\Uuid\Uuid;
use WC_Data;
use WC_Product;
use WCPOS\WooCommercePOS\Logger;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WC_Product_Query;
use function image_downsize;
use function is_array;
use function wp_get_attachment_metadata;

/**
 * @property string $search_term
 */
class Products {
	private $request;
	private $uuids;

	/**
	 * Products constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->uuids = $this->get_all_uuids();

		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_request_before_callbacks' ), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'product_response' ), 10, 3 );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'product_query' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'posts_search' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
		add_filter( 'woocommerce_rest_product_schema', array( $this, 'add_barcode_to_product_schema' ) );
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'insert_product_object' ), 10, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'product_image_src' ), 10, 4 );

//		add_filter('rest_pre_echo_response', function($result, $server, $request) {
//			$logger = wc_get_logger();
//			$test = wp_json_encode( $result, 0 );
//			if(is_array($result)) {
//				foreach($result as $record) {
//					if(is_array($record) && isset($record['meta_data'])) {
//						$test = wp_json_encode( $record, 0 );
//						$logger->info($test, array('source' => 'wcpos-support-3'));
//					}
//				}
//			}
//			return $result;
//		}, 10, 3);

//		add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
//			$logger = wc_get_logger();
////			$logger->info(wp_json_encode($result), array('source' => 'wcpos-support'));
//			$data = $result->get_data();
//			if(is_array($data)) {
//				foreach($data as $record) {
//					if(isset($record['meta_data'])) {
//						$logger->info(wp_json_encode($record['meta_data']), array('source' => 'wcpos-support'));
//					}
//				}
//			}
//			return $served;
//		}, 10, 4);
	}

	/**
	 * Note: this gets all postmeta uuids, including orders, we're just interested in doing a check sanity check
	 * This addresses a bug where I have seen two products with the same uuid
	 *
	 * @return array
	 */
	private function get_all_uuids() : array {
		global $wpdb;
		$result = $wpdb->get_col(
			"
            SELECT meta_value
            FROM $wpdb->postmeta
            WHERE meta_key = '_woocommerce_pos_uuid'
            "
		);
		return $result;
	}

	/**
	 * Make sure the product has a uuid
	 */
	private function maybe_add_uuid( WC_Product $product ) {
		$uuids = get_post_meta( $product->get_id(), '_woocommerce_pos_uuid', false );
		$uuid_counts = array_count_values( $this->uuids );

		if ( empty( $uuids ) ) {
			$this->add_uuid_meta_data( $product );
		}

		if ( count( $uuids ) > 1 || count( $uuids ) === 1 && $uuid_counts[ $uuids[0] ] > 1 ) {
			delete_post_meta( $product->get_id(), '_woocommerce_pos_uuid' );
			$this->add_uuid_meta_data( $product );
		}
	}

	/**
	 *
	 */
	private function add_uuid_meta_data( WC_Product $product ) {
		$uuid = Uuid::uuid4()->toString();
		while ( in_array( $uuid, $this->uuids ) ) { // ensure the new UUID is unique
			$uuid = Uuid::uuid4()->toString();
		}
		$this->uuids[] = $uuid; // update the UUID list
		$product->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$product->save_meta_data();
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

		/**
		 * Make sure the product has a uuid
		 */
		$this->maybe_add_uuid( $product );

		/**
		 * Add barcode field
		 */
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$data['barcode'] = $product->get_meta( $barcode_field );

		/**
		 * Truncate the product description
		 */
		$max_length = 100;
		$plain_text_description = wp_strip_all_tags( $data['description'], true );
		if ( strlen( $plain_text_description ) > $max_length ) {
			$truncated_description = substr( $plain_text_description, 0, $max_length - 3 ) . '...';
			$data['description'] = $truncated_description;
		}

		/**
		 * Check the response size and log a debug message if it is over the maximum size.
		 */
		$response_size = strlen( serialize( $response->data ) );
		$max_response_size = 100000;
		if ( $response_size > $max_response_size ) {
			Logger::log( "Product ID {$product->get_id()} has a response size of {$response_size} bytes, exceeding the limit of {$max_response_size} bytes." );
		}

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
				$product->save_meta_data();
			}
		}


		/**
		 * Reset the new response data
		 * BUG FIX: some servers are not returning the correct meta_data if it is left as WC_Meta_Data objects
		 */
		$data['meta_data'] = array_map( function( $meta_data ) {
			return $meta_data->get_data();
		}, $product->get_meta_data());
		$response->set_data( $data );

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
			$join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id";
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
				$search_array[] = $wpdb->prepare( "(wp_postmeta.meta_key = '_sku' AND wp_postmeta.meta_value LIKE %s)", $n . $like_term . $n );

				// Search in barcode field
				$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
				if ( $barcode_field !== '_sku' ) {
					$search_array[] = $wpdb->prepare( "(wp_postmeta.meta_key = %s AND wp_postmeta.meta_value LIKE %s)", $barcode_field, $n . $like_term . $n );
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
	 * @return array
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
		$product_ids = $product_query->posts;

		// Convert the array of product IDs to an array of objects with product IDs as integers
		return array_map( array( $this, 'format_id' ), $product_ids );
	}

	/**
	 * Returns thumbnail if it exists, if not, returns the WC placeholder image.
	 *
	 * @param int $id
	 *
	 * @return string
	 */
	private function get_thumbnail( int $id ): string {
		$image    = false;
		$thumb_id = get_post_thumbnail_id( $id );

		if ( $thumb_id ) {
			$image = wp_get_attachment_image_src( $thumb_id, 'shop_thumbnail' );
		}

		if ( is_array( $image ) ) {
			return $image[0];
		}

		return wc_placeholder_img_src();
	}

	/**
	 * Filters the attachment image source result.
	 * The WC REST API returns 'full' images by default, but we want to return 'shop_thumbnail' images.
	 *
	 * @param array|false  $image         {
	 *     Array of image data, or boolean false if no image is available.
	 *
	 *     @type string $0 Image source URL.
	 *     @type int    $1 Image width in pixels.
	 *     @type int    $2 Image height in pixels.
	 *     @type bool   $3 Whether the image is a resized image.
	 * }
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Requested image size. Can be any registered image size name, or
	 *                                    an array of width and height values in pixels (in that order).
	 * @param bool         $icon          Whether the image should be treated as an icon.
	 */
	public function product_image_src( $image, int $attachment_id, $size, bool $icon ) {
		// Get the metadata for the attachment.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		// Use the 'woocommerce_gallery_thumbnail' size if it exists.
		if ( isset( $metadata['sizes']['woocommerce_gallery_thumbnail'] ) ) {
			return image_downsize( $attachment_id, 'woocommerce_gallery_thumbnail' );
		}
		// If 'woocommerce_gallery_thumbnail' doesn't exist, try the 'thumbnail' size.
		else if ( isset( $metadata['sizes']['thumbnail'] ) ) {
			return image_downsize( $attachment_id, 'thumbnail' );
		}
		// If neither 'woocommerce_gallery_thumbnail' nor 'thumbnail' sizes exist, return the original $image.
		else {
			return $image;
		}
	}

	/**
	 * @param string $product_id
	 *
	 * @return object
	 */
	private function format_id( string $product_id ): object {
		return (object) array( 'id' => (int) $product_id );
	}
}
