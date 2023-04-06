<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Customer;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

class Customers {
	private $request;

	/**
	 * Customers constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		add_filter( 'woocommerce_rest_customer_query', array( $this, 'customer_query' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'customer_response' ), 10, 3 );

		$this->request = $request;
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

		// search first_name and last_name
		if ( isset( $prepared_args['search'] ) && '' !== $prepared_args['search'] ) {
			$prepared_args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => 'first_name',
					'value'   => $query_params['search'],
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'last_name',
					'value'   => $query_params['search'],
					'compare' => 'LIKE',
				),
			);
			$prepared_args['search']     = '';
		}

		// add after date_modified_gmt
		// TODO: do I need to add 'relation' => 'OR' if there is already a meta_query?
		if ( isset( $query_params['after'] ) && '' !== $query_params['after'] ) {
			$timestamp = strtotime( $query_params['after'] );
			$prepared_args['meta_query'] = array(
				array(
					'key'     => 'last_update',
					'value'   => $timestamp ? (string) $timestamp : '',
					'compare' => '>',
				),
			);
		}

		// woocommerce removes valid query params, so we must put them back in
		if ( isset( $query_params['orderby'] ) && 'meta_value' == $query_params['orderby'] ) {
			$prepared_args['orderby']  = 'meta_value';
			$prepared_args['meta_key'] = $query_params['meta_key'];
		}

		return $prepared_args;
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

		/**
		 * Make sure the customer has a uuid
		 */
		$uuid = get_user_meta( $user_data->ID, '_woocommerce_pos_uuid', true );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			update_user_meta( $user_data->ID, '_woocommerce_pos_uuid', $uuid );
			try {
				$customer = new WC_Customer( $user_data->ID );
				$data['meta_data'] = $customer->get_meta_data();
			} catch ( Exception $e ) {
				Logger::log( 'Error getting customer meta data: ' . $e->getMessage() );
			}
		}

		/**
		 * In the WC REST Customers Controller -> get_formatted_item_data_core function, the customer's
		 * meta_data is only added for administrators. I assume this is for privacy/security reasons.
		 *
		 * Cashiers are not always administrators so we need to add the meta_data for uuids.
		 * @TODO - are there any other meta_data we need to add?
		 */
		if ( empty( $data['meta_data'] ) ) {
			try {
				$customer = new WC_Customer( $user_data->ID );
				$data['meta_data'] = array_values( array_filter( $customer->get_meta_data(), function ( $meta ) {
					return '_woocommerce_pos_uuid' === $meta->key;
				}));
			} catch ( Exception $e ) {
				Logger::log( 'Error getting customer meta data: ' . $e->getMessage() );
			}
		}

		/**
		 * Reset the new response data
		 */
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Returns array of all customer ids, username.
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		global $wpdb;

		$all_posts = $wpdb->get_results(
			'
			SELECT ID as id FROM ' . $wpdb->users
		);

		// wpdb returns id as string, we need int
		return array_map( array( $this, 'format_id' ), $all_posts );
	}

	/**
	 * @param object $record
	 *
	 * @return object
	 */
	private function format_id( $record ) {
		$record->id = (int) $record->id;

		return $record;
	}
}
