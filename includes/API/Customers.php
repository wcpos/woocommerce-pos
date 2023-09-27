<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Customer;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_User_Query;

class Customers extends Abstracts\WC_Rest_API_Modifier {
    use Traits\Uuid_Handler;

	/**
	 * Customers constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
        $this->uuids = $this->get_all_usermeta_uuids();

		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_request_before_callbacks' ), 10, 3 );
		add_filter( 'woocommerce_rest_customer_query', array( $this, 'customer_query' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'customer_response' ), 10, 3 );
		add_filter( 'users_where', array( $this, 'users_where' ), 10, 2 );
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

				// Check if the invalid parameter was 'orderby'
				if ( array_key_exists( 'orderby', $error_data['params'] ) ) {
					// Get the 'orderby' details
					$orderby_details = $error_data['details']['orderby'];

					// Get the 'orderby' request
					$orderby_request = $request->get_param( 'orderby' );

					// Extended 'orderby' values
					$orderby_extended = array(
						'first_name',
						'last_name',
						'email',
						'role',
						'username',
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
	 * Filter customer data returned from the REST API.
	 *
	 * @param WP_REST_Response $response   The response object.
	 * @param WP_User $user_data  User object used to create response.
	 * @param WP_REST_Request $request    Request object.
	 */
	public function customer_response( WP_REST_Response $response, WP_User $user_data, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

        // Add the uuid to the response
        $this->maybe_add_user_uuid( $user_data );

        /**
         * Add the customer meta data to the response
         *
         * In the WC REST Customers Controller -> get_formatted_item_data_core function, the customer's
         * meta_data is only added for administrators. I assume this is for privacy/security reasons.
         *
         * NOTE: for now we are only adding the uuid meta_data
         * @TODO - are there any other meta_data we need to add?
         */
        try {
            $customer = new WC_Customer( $user_data->ID );
            $data['meta_data'] = array_values( array_filter( $customer->get_meta_data(), function ( $meta ) {
                return '_woocommerce_pos_uuid' === $meta->key;
            }));
        } catch ( Exception $e ) {
            Logger::log( 'Error getting customer meta data: ' . $e->getMessage() );
        }

        // Set any changes to the response data
        $response->set_data( $data );
        // $this->log_large_rest_response( $response, $user_data->ID );

		return $response;
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
	public function customer_query( array $prepared_args, WP_REST_Request $request ): array {
		$query_params = $request->get_query_params();

		if ( isset( $prepared_args['search'] ) && '' !== $prepared_args['search'] ) {
			$prepared_args['_search_term'] = $query_params['search'];
			$prepared_args['search'] = '';

			add_action( 'pre_user_query', array( $this, 'modify_user_query' ) );
		}

		// add modified_after date_modified_gmt
		// TODO: do I need to add 'relation' => 'OR' if there is already a meta_query?
		if ( isset( $query_params['modified_after'] ) && '' !== $query_params['modified_after'] ) {
			$timestamp = strtotime( $query_params['modified_after'] );
			$prepared_args['meta_query'] = array(
				array(
					'key'     => 'last_update',
					'value'   => $timestamp ? (string) $timestamp : '',
					'compare' => '>',
				),
			);
		}

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
					$prepared_args['orderby'] = 'meta_value';
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

    public function modify_user_query( $user_query ) {
        if ( isset( $user_query->query_vars['_search_term'] ) && ! empty( $user_query->query_vars['_search_term'] ) ) {
            $search_term = $user_query->query_vars['_search_term'];

            global $wpdb;

            $like_term = '%' . $wpdb->esc_like( $search_term ) . '%';

            $meta_conditions = $wpdb->prepare(
                "({$wpdb->usermeta}.meta_key = '_woocommerce_pos_uuid' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'first_name' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'last_name' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'billing_first_name' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'billing_last_name' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'billing_email' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'billing_company' AND {$wpdb->usermeta}.meta_value LIKE %s)
			OR
			({$wpdb->usermeta}.meta_key = 'billing_phone' AND {$wpdb->usermeta}.meta_value LIKE %s)",
                $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, $like_term
            );

            $user_conditions = $wpdb->prepare(
                "({$wpdb->users}.user_email LIKE %s)
			OR
			({$wpdb->users}.user_login LIKE %s)
			OR
			({$wpdb->users}.ID = %d)",
                $like_term, $like_term, $search_term
            );

            $all_conditions = "($meta_conditions) OR ($user_conditions)";

            $user_query->query_where .= " AND ( {$all_conditions} )";

            remove_action( 'pre_user_query', array( $this, 'modify_user_query' ) );
        }
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
	public function get_all_posts( array $fields = array() ): array {
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
			return array_map( array( $this, 'format_id' ), $user_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching order IDs: ' . $e->getMessage() );
			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching customer IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
