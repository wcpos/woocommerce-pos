<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Customers_Controller') ) {
	return;
}

use Exception;
use WC_Customer;
use WC_REST_Customers_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_User_Query;

/**
 * Product Tgas controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Customers_Controller methods
 */
class Customers_Controller extends WC_REST_Customers_Controller {
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
		add_filter( 'woocommerce_pos_rest_dispatch_customers_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
	}

	/**
	 * Add extra fields to WP_REST_Controller::get_collection_params().
	 * - add new fields to the 'orderby' enum list.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// Check if 'orderby' is set and is an array before modifying it
		if (isset($params['orderby']) && \is_array($params['orderby']['enum'])) {
			// Add new fields to the 'orderby' enum list
			$new_orderby_options = array(
				'first_name',
				'last_name',
				'email',
				'role',
				'username',
			);
			foreach ($new_orderby_options as $option) {
				if ( ! \in_array($option, $params['orderby']['enum'], true)) {
					$params['orderby']['enum'][] = $option;
				}
			}
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
		add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'wcpos_customer_response' ), 10, 3 );
		add_filter( 'woocommerce_rest_customer_query', array( $this, 'wcpos_customer_query' ), 10, 2 );
	}

	/**
	 * Filter customer data returned from the REST API.
	 *
	 * @param WP_REST_Response $response  The response object.
	 * @param WP_User          $user_data User object used to create response.
	 * @param WP_REST_Request  $request   Request object.
	 */
	public function wcpos_customer_response( WP_REST_Response $response, WP_User $user_data, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add the uuid to the response
		$this->maybe_add_user_uuid( $user_data );

		/*
		 * Add the customer meta data to the response
		 *
		 * In the WC REST Customers Controller -> get_formatted_item_data_core function, the customer's
		 * meta_data is only added for administrators. I assume this is for privacy/security reasons.
		 *
		 * NOTE: for now we are only adding the uuid meta_data
		 * @TODO - are there any other meta_data we need to add?
		 */
		try {
			$customer           = new WC_Customer( $user_data->ID );
			$raw_meta_data      = $customer->get_meta_data();

			$filtered_meta_data = array_filter($raw_meta_data, function ($meta) {
				return '_woocommerce_pos_uuid' === $meta->key;
			});

			// Convert to WC REST API expected format
			$data['meta_data'] = array_map(function ($meta) {
				return array(
					'id'    => $meta->id,
					'key'   => $meta->key,
					'value' => $meta->value,
				);
			}, array_values($filtered_meta_data));
		} catch ( Exception $e ) {
			Logger::log( 'Error getting customer meta data: ' . $e->getMessage() );
		}

		// Set any changes to the response data
		$response->set_data( $data );
		// $this->log_large_rest_response( $response, $user_data->ID );

		return $response;
	}

	/**
	 * Returns array of all customer ids.
	 *
	 * Note: user queries are a little more complicated than post queries, for example,
	 * multisite would return all users from all sites, not just the current site.
	 * Also, querying by role is not as simple as querying by post type.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function wcpos_get_all_posts( array $fields = array() ): array {
		$args = array(
			'fields' => 'ID', // Only return user IDs
		);
		$roles = 'all'; // @TODO: could be an array of roles, like ['customer', 'cashier']

		if ( 'all' !== $roles ) {
			$args['role__in'] = $roles;
		}

		$user_query = new WP_User_Query( $args );

		try {
			$user_ids = $user_query->get_results();

			return array_map( array( $this, 'wcpos_format_id' ), $user_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching order IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching customer IDs.',
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Filter arguments, before passing to WP_User_Query, when querying users via the REST API.
	 *
	 * @see https://developer.wordpress.org/reference/classes/wp_user_query/
	 *
	 * @param array           $prepared_args Array of arguments for WP_User_Query.
	 * @param WP_REST_Request $request       The current request.
	 *
	 * @return array $prepared_args Array of arguments for WP_User_Query.
	 */
	public function wcpos_customer_query( array $prepared_args, WP_REST_Request $request ): array {
		$query_params = $request->get_query_params();

		// Existing meta query
		$existing_meta_query = $prepared_args['meta_query'] ?? array();

		// add modified_after date_modified_gmt
		if ( isset( $query_params['modified_after'] ) && '' !== $query_params['modified_after'] ) {
			$timestamp                   = strtotime( $query_params['modified_after'] );
			$prepared_args['meta_query'] = array(
				array(
					'key'     => 'last_update',
					'value'   => $timestamp ? (string) $timestamp : '',
					'compare' => '>',
				),
			);
		}

		// If the 'search' parameter exists
		if ( isset( $query_params['search'] ) && ! empty( $query_params['search'] ) ) {
			$search_keyword = $query_params['search'];
	
			$search_meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => 'first_name',
					'value'   => $search_keyword,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'last_name',
					'value'   => $search_keyword,
					'compare' => 'LIKE',
				),
			);
	
			// Merge with existing meta_query if any
			if ( ! empty($existing_meta_query)) {
				$existing_meta_query = array(
					'relation' => 'AND',
					$existing_meta_query,
					$search_meta_query,
				);
			} else {
				$existing_meta_query = $search_meta_query;
			}
		}

		// Apply the modified or newly created meta_query
		$prepared_args['meta_query'] = $existing_meta_query;

		// Handle orderby cases
		if ( isset( $query_params['orderby'] ) ) {
			switch ( $query_params['orderby'] ) {
				case 'first_name':
					$prepared_args['meta_key'] = 'first_name';
					$prepared_args['orderby']  = 'meta_value';

					break;

				case 'last_name':
					$prepared_args['meta_key'] = 'last_name';
					$prepared_args['orderby']  = 'meta_value';

					break;

				case 'email':
					$prepared_args['orderby'] = 'user_email';

					break;

				case 'role':
					$prepared_args['meta_key'] = 'wp_capabilities';
					$prepared_args['orderby']  = 'meta_value';

					break;

				case 'username':
					$prepared_args['orderby'] = 'user_login';

					break;

				default:
					break;
			}
		}

		return $prepared_args;
	}
}
